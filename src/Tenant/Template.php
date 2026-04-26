<?php
declare(strict_types=1);

namespace App\Tenant;

final class Template
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $name,
        public readonly string $html,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['name'],
            (string) $row['html'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
