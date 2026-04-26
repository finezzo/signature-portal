<?php
declare(strict_types=1);

namespace App\Auth;

final class Csrf
{
    private const SESSION_KEY = '_csrf';
    public const FIELD_NAME   = '_csrf';

    public function __construct(private readonly SessionManager $session) {}

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public function verify(?string $candidate): bool
    {
        $stored = $this->session->get(self::SESSION_KEY);
        if (!is_string($stored) || !is_string($candidate) || $candidate === '') {
            return false;
        }
        return hash_equals($stored, $candidate);
    }
}
