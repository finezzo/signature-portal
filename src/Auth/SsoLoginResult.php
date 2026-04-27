<?php
declare(strict_types=1);

namespace App\Auth;

final class SsoLoginResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $error,
    ) {}

    public static function success(): self
    {
        return new self(true, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, $error);
    }
}
