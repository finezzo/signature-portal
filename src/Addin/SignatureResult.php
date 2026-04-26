<?php
declare(strict_types=1);

namespace App\Addin;

final class SignatureResult
{
    public const KIND_OK       = 'ok';
    public const KIND_NO_MATCH = 'no_match';
    public const KIND_ERROR    = 'error';

    private function __construct(
        public readonly string $kind,
        public readonly int $status,
        public readonly string $html,
        public readonly string $error,
        public readonly bool $sharedMailbox,
    ) {}

    public static function ok(string $html, bool $sharedMailbox): self
    {
        return new self(self::KIND_OK, 200, $html, '', $sharedMailbox);
    }

    public static function noMatch(bool $sharedMailbox): self
    {
        return new self(self::KIND_NO_MATCH, 204, '', '', $sharedMailbox);
    }

    public static function error(int $status, string $error): self
    {
        return new self(self::KIND_ERROR, $status, '', $error, false);
    }
}
