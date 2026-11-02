<?php
declare(strict_types=1);

namespace App\Auth;

final class AuthAttemptResult
{
    public const STATE_OK              = 'ok';
    public const STATE_BAD_CREDENTIALS = 'bad_credentials';
    public const STATE_LOCKED          = 'locked';
    public const STATE_MFA_REQUIRED    = 'mfa_required';

    private function __construct(
        public readonly string $state,
        public readonly ?string $lockedUntil,
        /** On success after a second factor: which kind, see MfaService::FACTOR_*. */
        public readonly ?string $secondFactor = null,
    ) {}

    public static function success(?string $secondFactor = null): self
    {
        return new self(self::STATE_OK, null, $secondFactor);
    }

    public static function mfaRequired(): self
    {
        return new self(self::STATE_MFA_REQUIRED, null);
    }

    public static function badCredentials(): self
    {
        return new self(self::STATE_BAD_CREDENTIALS, null);
    }

    public static function lockedUntil(string $until): self
    {
        return new self(self::STATE_LOCKED, $until);
    }

    public function ok(): bool
    {
        return $this->state === self::STATE_OK;
    }
}
