<?php
declare(strict_types=1);

namespace App\Portal;

use App\Auth\Authenticator;
use App\Auth\EntraProvider;
use App\Auth\SessionManager;
use App\Auth\SsoAuthenticator;
use App\Http\RateLimiter;
use App\Tenant\TenantRepository;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Throwable;

final class AuthController
{
    /**
     * Failed sign-ins per client IP before that IP is refused for the rest of
     * the window. Catches one address trying passwords across many accounts,
     * which the per-account lockout cannot see.
     */
    private const LOGIN_FAILURE_LIMIT  = 20;
    private const LOGIN_FAILURE_WINDOW = 900;

    public function __construct(
        private readonly Twig $view,
        private readonly Authenticator $auth,
        private readonly SessionManager $session,
        private readonly TenantRepository $tenants,
        private readonly EntraProvider $entra,
        private readonly SsoAuthenticator $sso,
        private readonly RateLimiter $limiter,
    ) {}

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->session->isAuthenticated()) {
            return $response->withHeader('Location', '/portal/dashboard')->withStatus(302);
        }

        $flash = $this->session->get('flash_login_error');
        $this->session->forget('flash_login_error');

        $ssoTenants = array_values(array_filter(
            $this->tenants->all(),
            fn($t) => $this->entra->isConfigured($t)
        ));

        return $this->view->render($response, 'portal/login.twig', [
            'error'        => $flash,
            'sso_tenants'  => $ssoTenants,
        ]);
    }

    public function doLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = (array) $request->getParsedBody();
        $email = isset($body['email']) ? trim((string) $body['email']) : '';
        $pass  = isset($body['password']) ? (string) $body['password'] : '';

        if ($email === '' || $pass === '') {
            $this->session->set('flash_login_error', 'Invalid email or password.');
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        // Only failures count, so normal use never gets near the limit. The
        // message may be specific: it is about the IP and reveals nothing about
        // any account.
        $ipBucket = 'loginfail:' . (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        if (!$this->limiter->check($ipBucket, self::LOGIN_FAILURE_LIMIT, self::LOGIN_FAILURE_WINDOW)) {
            $this->session->set('flash_login_error', 'Too many failed sign-ins from your network. Please try again in a few minutes.');
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        $result = $this->auth->attempt($email, $pass);
        if ($result->state === \App\Auth\AuthAttemptResult::STATE_MFA_REQUIRED) {
            return $response->withHeader('Location', '/portal/login/verify')->withStatus(302);
        }
        if (!$result->ok()) {
            $this->limiter->hit($ipBucket, self::LOGIN_FAILURE_LIMIT, self::LOGIN_FAILURE_WINDOW);
            // Generic message for every failure path — including lockout —
            // so an attacker can't probe whether an email exists by
            // triggering 5 misses and reading a different message back.
            // The lockout itself still applies; it's just not advertised.
            $this->session->set('flash_login_error', 'Invalid email or password.');
            if ($result->state === \App\Auth\AuthAttemptResult::STATE_LOCKED) {
                // Never log the email itself (see CLAUDE.md logging policy) — a
                // one-way hash is enough to correlate repeated lockouts of the
                // same account across log lines without exposing the address.
                error_log(
                    '[login] lockout active for account='
                    . substr(hash('sha256', mb_strtolower(trim($email))), 0, 12)
                    . ' until=' . ($result->lockedUntil ?? 'unknown')
                );
            }
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        return $response->withHeader('Location', '/portal/dashboard')->withStatus(302);
    }

    public function showSecondFactor(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->session->isAuthenticated()) {
            return $response->withHeader('Location', '/portal/dashboard')->withStatus(302);
        }
        if (!$this->auth->hasPendingSecondFactor()) {
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        $flash = $this->session->get('flash_login_error');
        $this->session->forget('flash_login_error');

        return $this->view->render($response, 'portal/login_verify.twig', ['error' => $flash]);
    }

    public function doSecondFactor(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $retry = $response->withHeader('Location', '/portal/login/verify')->withStatus(302);
        $login = $response->withHeader('Location', '/portal/login')->withStatus(302);

        if (!$this->auth->hasPendingSecondFactor()) {
            $this->session->set('flash_login_error', 'Your sign-in timed out. Please sign in again.');
            return $login;
        }

        $ipBucket = 'loginfail:' . (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        if (!$this->limiter->check($ipBucket, self::LOGIN_FAILURE_LIMIT, self::LOGIN_FAILURE_WINDOW)) {
            $this->session->set('flash_login_error', 'Too many failed sign-ins from your network. Please try again in a few minutes.');
            return $retry;
        }

        $result = $this->auth->attemptSecondFactor((string) (((array) $request->getParsedBody())['code'] ?? ''));
        if ($result->ok()) {
            if ($result->secondFactor === \App\Auth\MfaService::FACTOR_RECOVERY) {
                $this->session->flash('success', 'You signed in with a recovery code, which is now used up. If you lost your authenticator, replace it under My account.');
            }
            return $response->withHeader('Location', '/portal/dashboard')->withStatus(302);
        }

        $this->limiter->hit($ipBucket, self::LOGIN_FAILURE_LIMIT, self::LOGIN_FAILURE_WINDOW);
        // Only someone who knew the password gets here, so unlike on the first
        // step, naming the reason gives nothing away.
        if ($result->state === \App\Auth\AuthAttemptResult::STATE_LOCKED) {
            $this->session->set('flash_login_error', 'Too many wrong codes. Please try again in a few minutes.');
            return $login;
        }
        $this->session->set('flash_login_error', 'That code is not valid.');
        return $retry;
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->auth->logout();
        return $response->withHeader('Location', '/portal/login')->withStatus(302);
    }

    public function ssoStart(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->tenants->findBySlug((string) ($args['slug'] ?? ''));
        if ($tenant === null || !$this->entra->isConfigured($tenant)) {
            $this->session->set('flash_login_error', 'SSO is not configured for that tenant.');
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        try {
            $provider = $this->entra->build($tenant);
        } catch (Throwable $e) {
            // Never reflect exception details to the (unauthenticated)
            // visitor — log the class server-side, show a generic message.
            error_log('[sso] provider build failed for tenant ' . $tenant->slug . ' (' . $e::class . ')');
            $this->session->set('flash_login_error', 'SSO is misconfigured for this tenant. Contact an administrator.');
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        $authUrl = $provider->getAuthorizationUrl();
        $this->session->set('sso_state', $provider->getState());
        $this->session->set('sso_tenant_slug', $tenant->slug);

        return $response->withHeader('Location', $authUrl)->withStatus(302);
    }

    public function ssoCallback(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug         = (string) ($args['slug'] ?? '');
        $expectedSlug = (string) ($this->session->get('sso_tenant_slug') ?? '');
        $expectedState = (string) ($this->session->get('sso_state') ?? '');
        $this->session->forget('sso_state');
        $this->session->forget('sso_tenant_slug');

        $query = $request->getQueryParams();
        $state = (string) ($query['state'] ?? '');
        $code  = (string) ($query['code']  ?? '');
        $error = (string) ($query['error_description'] ?? $query['error'] ?? '');

        if ($error !== '') {
            // $error comes from the query string — attacker-craftable via a
            // forged callback link. Log it, but never display it verbatim.
            error_log('[sso] Entra returned an error for slug ' . $slug . ': ' . mb_substr($error, 0, 200));
            $this->session->set('flash_login_error', 'Microsoft reported a sign-in problem. Please try again.');
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }
        if ($expectedState === '' || !hash_equals($expectedState, $state) || $expectedSlug !== $slug) {
            $this->session->set('flash_login_error', 'SSO state mismatch — please try again.');
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        $tenant = $this->tenants->findBySlug($slug);
        if ($tenant === null || !$this->entra->isConfigured($tenant)) {
            $this->session->set('flash_login_error', 'SSO is not configured for that tenant.');
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        try {
            $provider = $this->entra->build($tenant);
            $token    = $provider->getAccessToken('authorization_code', ['code' => $code]);
            /** @var \TheNetworg\OAuth2\Client\Provider\AzureResourceOwner $owner */
            $owner    = $provider->getResourceOwner($token);
        } catch (IdentityProviderException $e) {
            error_log('[sso] token exchange rejected for tenant ' . $tenant->slug . ' (' . $e::class . ')');
            $this->session->set('flash_login_error', 'Microsoft rejected the sign-in. Please try again or contact an administrator.');
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        } catch (Throwable $e) {
            error_log('[sso] callback failed for tenant ' . $tenant->slug . ' (' . $e::class . ')');
            $this->session->set('flash_login_error', 'SSO sign-in failed. Please try again or contact an administrator.');
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        try {
            $result = $this->sso->loginFromAzureOwner($tenant, $owner);
        } catch (Throwable $e) {
            // Last-resort net so no SSO edge case can surface as a raw 500.
            // Log the exception class only — never $e->getMessage(), which for a
            // DB constraint violation would contain the offending email address.
            error_log('[sso] login failed for tenant ' . $tenant->slug . ' (' . $e::class . ')');
            $this->session->set(
                'flash_login_error',
                'SSO sign-in could not be completed. Please try again or contact an administrator.'
            );
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }
        if (!$result->ok) {
            $this->session->set('flash_login_error', $result->error ?? 'SSO login was rejected.');
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        return $response->withHeader('Location', '/portal/dashboard')->withStatus(302);
    }
}
