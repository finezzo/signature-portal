<?php
declare(strict_types=1);

namespace App\Portal;

use App\Auth\Authenticator;
use App\Auth\EntraProvider;
use App\Auth\SessionManager;
use App\Auth\SsoAuthenticator;
use App\Tenant\TenantRepository;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Throwable;

final class AuthController
{
    public function __construct(
        private readonly Twig $view,
        private readonly Authenticator $auth,
        private readonly SessionManager $session,
        private readonly TenantRepository $tenants,
        private readonly EntraProvider $entra,
        private readonly SsoAuthenticator $sso,
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

        $result = $this->auth->attempt($email, $pass);
        if (!$result->ok()) {
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
            $this->session->set('flash_login_error', 'SSO configuration error: ' . $e->getMessage());
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
            $this->session->set('flash_login_error', 'Entra returned an error: ' . $error);
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
            $this->session->set('flash_login_error', 'Entra rejected the sign-in: ' . $e->getMessage());
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        } catch (Throwable $e) {
            $this->session->set('flash_login_error', 'SSO failed: ' . $e->getMessage());
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
