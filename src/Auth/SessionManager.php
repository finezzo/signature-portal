<?php
declare(strict_types=1);

namespace App\Auth;

use App\Config\Config;

final class SessionManager
{
    public function __construct(private readonly Config $config) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $name     = (string) $this->config->get('session.name', 'sigportal_sid');
        $lifetime = ((int) $this->config->get('session.lifetime', 120)) * 60;
        // Secure flag if EITHER the configured base_url is https OR the request
        // actually arrived over TLS — so a mis-typed/http base_url can't silently
        // ship the session cookie in the clear on an HTTPS deployment. We only
        // ever add Secure here, never remove it (local http dev stays working).
        $secure   = str_starts_with((string) $this->config->get('base_url', ''), 'https://')
            || (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        // Reject a session ID the server never issued: an unknown ID starts a
        // fresh empty session instead of adopting the value from the cookie.
        // This closes the classic session-fixation vector where an attacker
        // pre-sets the cookie and waits for the victim to authenticate under it.
        ini_set('session.use_strict_mode', '1');

        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            // regenerate() runs on privilege change (login). session_regenerate_id
            // keeps the session *data*, so drop the CSRF token as well — a token
            // an attacker learned from a shared/fixated pre-login session must
            // not survive into the authenticated session. Csrf::token() mints a
            // fresh one on next use (key mirrors App\Auth\Csrf::SESSION_KEY).
            unset($_SESSION['_csrf']);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** @return array{id:int,email:string,name:?string,role:string,tenant_id:?int}|null */
    public function currentUser(): ?array
    {
        $u = $_SESSION['user'] ?? null;
        return is_array($u) ? $u : null;
    }

    public function isAuthenticated(): bool
    {
        return $this->currentUser() !== null;
    }

    public function flash(string $type, string $message): void
    {
        $bag   = $_SESSION['_flash'] ?? [];
        $bag[] = ['type' => $type, 'message' => $message];
        $_SESSION['_flash'] = $bag;
    }

    /** @return list<array{type:string,message:string}> */
    public function consumeFlashes(): array
    {
        $bag = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($bag) ? array_values($bag) : [];
    }
}
