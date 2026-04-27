<?php
declare(strict_types=1);

namespace App\Tenant;

final class Rule
{
    public const SCOPE_ALL      = 'all';
    public const SCOPE_EXTERNAL = 'external';
    public const SCOPE_INTERNAL = 'internal';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $templateId,
        public readonly string $fromDomain,
        public readonly ?string $language,
        public readonly ?string $mailboxType,    // 'personal' | 'shared' | null
        public readonly string $recipientScope, // 'all' | 'external' | 'internal'
        public readonly int $priority,
        public readonly bool $isFallback,
        public readonly bool $isEnabled,
        public readonly ?string $validFrom,
        public readonly ?string $validUntil,
        public readonly string $createdAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['template_id'],
            (string) $row['from_domain'],
            $row['language']     !== null ? (string) $row['language']     : null,
            $row['mailbox_type'] !== null ? (string) $row['mailbox_type'] : null,
            (string) $row['recipient_scope'],
            (int) $row['priority'],
            (bool) ((int) $row['is_fallback']),
            (bool) ((int) ($row['is_enabled'] ?? 1)),
            isset($row['valid_from'])  && $row['valid_from']  !== null ? (string) $row['valid_from']  : null,
            isset($row['valid_until']) && $row['valid_until'] !== null ? (string) $row['valid_until'] : null,
            (string) $row['created_at'],
        );
    }
}
