<?php
declare(strict_types=1);

namespace App\Addin;

final class SignatureRequest
{
    /** @param list<string> $recipients */
    public function __construct(
        public readonly string $tenantSlug,
        public readonly string $apiKey,
        public readonly string $fromEmail,
        public readonly string $primaryEmail,
        public readonly array $recipients,
        public readonly ?string $language,    // override for Graph preferredLanguage
        public readonly ?string $mailboxType, // 'personal' | 'shared' | null (auto)
    ) {}
}
