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
        $secure   = str_starts_with(
            (string) $this->config->get('base_url', ''),
            'https://'
        );

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
