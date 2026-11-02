<?php
declare(strict_types=1);

namespace App\Portal;

use App\Audit\AuditLogger;
use App\Auth\MfaService;
use App\Auth\PasswordHasher;
use App\Auth\QrCode;
use App\Auth\SessionManager;
use App\Auth\Totp;
use App\Auth\UserRepository;
use App\Config\Config;
use App\Http\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Views\Twig;

/**
 * Self-service two-factor setup. Both changes ask for the current password, so a
 * session left open somewhere is not enough to take over or remove the second
 * factor. Accounts without a local password sign in through Entra and have
 * nothing to protect here.
 */
final class MfaController
{
    private const ISSUER = 'SignaturePortal';

    /** The not yet confirmed secret, sealed, so a reload keeps showing the same QR code. */
    private const SESSION_SECRET = 'mfa_enroll_secret';

    /** Failed attempts per user on these forms, which would otherwise allow guessing the password. */
    private const FAILURE_LIMIT  = 10;
    private const FAILURE_WINDOW = 900;

    public function __construct(
        private readonly Twig $view,
        private readonly UserRepository $users,
        private readonly SessionManager $session,
        private readonly PasswordHasher $hasher,
        private readonly MfaService $mfa,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
    ) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->currentUserRow($request);
        if ($user === null || empty($user['password_hash'])) {
            return $response->withHeader('Location', '/portal/account')->withStatus(302);
        }

        if (empty($user['mfa_enabled_at'])) {
            return $this->renderEnrolment($response, $user, []);
        }

        return $this->noStore($this->view->render($response, 'portal/mfa/show.twig', [
            'enrolled'      => true,
            'recovery_left' => $this->mfa->remainingRecoveryCodes((int) $user['id']),
            'required'      => $this->isRequired(),
            'errors'        => [],
        ]));
    }

    public function enable(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->currentUserRow($request);
        if ($user === null || empty($user['password_hash']) || !empty($user['mfa_enabled_at'])) {
            return $response->withHeader('Location', '/portal/account/mfa')->withStatus(302);
        }

        $body   = (array) $request->getParsedBody();
        $bucket = $this->failureBucket($user);
        if (!$this->limiter->check($bucket, self::FAILURE_LIMIT, self::FAILURE_WINDOW)) {
            return $this->renderEnrolment($response, $user, ['Too many failed attempts. Please try again in a few minutes.']);
        }

        $secret = $this->pendingSecret();
        $code   = (string) preg_replace('/\s+/', '', (string) ($body['code'] ?? ''));
        $step   = Totp::match($secret, $code, time());

        $errors = [];
        if (!$this->hasher->verify((string) ($body['current_password'] ?? ''), (string) $user['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        }
        if ($step === null) {
            $errors[] = 'That code is not valid. Check that the clock on your phone is correct, then try the next code.';
        }
        if ($errors !== []) {
            $this->limiter->hit($bucket, self::FAILURE_LIMIT, self::FAILURE_WINDOW);
            return $this->renderEnrolment($response, $user, $errors);
        }

        $recoveryCodes = $this->mfa->enable((int) $user['id'], $secret, (int) $step);
        $this->session->forget(self::SESSION_SECRET);
        $this->audit->record($this->tenantIdOf($user), 'auth.mfa.enabled', 'user', (int) $user['id'], 'Two-factor authentication turned on');

        return $this->noStore($this->view->render($response, 'portal/mfa/recovery_codes.twig', [
            'codes' => $recoveryCodes,
        ]));
    }

    public function disable(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $back = $response->withHeader('Location', '/portal/account/mfa')->withStatus(302);

        $user = $this->currentUserRow($request);
        if ($user === null || empty($user['password_hash']) || empty($user['mfa_enabled_at'])) {
            return $back;
        }

        $body   = (array) $request->getParsedBody();
        $bucket = $this->failureBucket($user);
        if (!$this->limiter->check($bucket, self::FAILURE_LIMIT, self::FAILURE_WINDOW)) {
            $this->session->flash('error', 'Too many failed attempts. Please try again in a few minutes.');
            return $back;
        }

        // Password first: a typo in it must not burn a one-time recovery code.
        $confirmed = $this->hasher->verify((string) ($body['current_password'] ?? ''), (string) $user['password_hash'])
            && $this->mfa->verifyCode((int) $user['id'], (string) ($body['code'] ?? '')) !== null;
        if (!$confirmed) {
            $this->limiter->hit($bucket, self::FAILURE_LIMIT, self::FAILURE_WINDOW);
            $this->session->flash('error', 'Current password or code is incorrect.');
            return $back;
        }

        $this->mfa->disable((int) $user['id']);
        $this->audit->record($this->tenantIdOf($user), 'auth.mfa.disabled', 'user', (int) $user['id'], 'Two-factor authentication removed by the user');
        $this->session->flash('success', 'Authenticator removed.');
        return $back;
    }

    /**
     * @param array<string,mixed> $user
     * @param list<string> $errors
     */
    private function renderEnrolment(ResponseInterface $response, array $user, array $errors): ResponseInterface
    {
        $secret = $this->pendingSecret();

        return $this->noStore($this->view->render($response, 'portal/mfa/show.twig', [
            'enrolled' => false,
            'required' => $this->isRequired(),
            'qr_svg'   => QrCode::svg(Totp::provisioningUri($secret, (string) $user['email'], self::ISSUER)),
            'secret'   => trim(chunk_split($secret, 4, ' ')),
            'errors'   => $errors,
        ]));
    }

    private function pendingSecret(): string
    {
        $sealed = $this->session->get(self::SESSION_SECRET);
        if (is_string($sealed)) {
            try {
                return $this->mfa->unseal($sealed);
            } catch (RuntimeException) {
                // Sealed under another APP_KEY: start over with a new secret.
            }
        }
        $secret = Totp::generateSecret();
        $this->session->set(self::SESSION_SECRET, $this->mfa->seal($secret));
        return $secret;
    }

    private function isRequired(): bool
    {
        return (bool) $this->config->get('auth.local.require_mfa', true);
    }

    /** @param array<string,mixed> $user */
    private function failureBucket(array $user): string
    {
        return 'mfamanage:' . (int) $user['id'];
    }

    /** @param array<string,mixed> $user */
    private function tenantIdOf(array $user): ?int
    {
        return $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
    }

    /** These pages show secrets, which must not stay in a browser or proxy cache. */
    private function noStore(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store');
    }

    /** @return array<string,mixed>|null */
    private function currentUserRow(ServerRequestInterface $request): ?array
    {
        $current = (array) $request->getAttribute('current_user');
        return $this->users->find((int) ($current['id'] ?? 0));
    }
}
