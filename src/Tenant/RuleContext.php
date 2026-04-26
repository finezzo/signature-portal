<?php
declare(strict_types=1);

namespace App\Tenant;

final class RuleContext
{
    /** @param list<string> $recipients @param list<string> $ownDomains */
    public function __construct(
        public readonly string $fromEmail,
        public readonly ?string $language,
        public readonly ?string $mailboxType,  // 'personal' | 'shared' | null
        public readonly array $recipients,
        public readonly array $ownDomains,
    ) {}

    public function fromDomain(): string
    {
        $at = strrchr($this->fromEmail, '@');
        return $at === false ? '' : mb_strtolower(substr($at, 1));
    }
}
