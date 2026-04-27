<?php
declare(strict_types=1);

namespace App\Tenant;

final class UserOverride
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $email,
        public readonly int $templateId,
        public readonly ?string $note,
        public readonly string $createdAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['email'],
            (int) $row['template_id'],
            $row['note'] !== null ? (string) $row['note'] : null,
            (string) $row['created_at'],
        );
    }
}
