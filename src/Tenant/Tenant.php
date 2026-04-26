<?php
declare(strict_types=1);

namespace App\Tenant;

final class Tenant
{
    /** @param list<string> $emailDomains */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly array $emailDomains,
        public readonly ?string $entraTenantId,
        public readonly ?string $entraClientId,
        public readonly ?string $entraClientSecretEncrypted,
        public readonly ?string $apiKeyEncrypted,
        public readonly ?string $apiKeyLastRotatedAt,
        public readonly ?string $manifestGuid,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $domains = [];
        if (!empty($row['email_domains'])) {
            $decoded = json_decode((string) $row['email_domains'], true);
            if (is_array($decoded)) {
                $domains = array_values(array_filter(array_map(
                    static fn($d): string => mb_strtolower(trim((string) $d)),
                    $decoded
                )));
            }
        }

        return new self(
            (int) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            $domains,
            $row['entra_tenant_id'] !== null ? (string) $row['entra_tenant_id'] : null,
            $row['entra_client_id'] !== null ? (string) $row['entra_client_id'] : null,
            $row['entra_client_secret_encrypted'] !== null ? (string) $row['entra_client_secret_encrypted'] : null,
            $row['api_key_encrypted'] !== null ? (string) $row['api_key_encrypted'] : null,
            $row['api_key_last_rotated_at'] !== null ? (string) $row['api_key_last_rotated_at'] : null,
            $row['manifest_guid'] !== null ? (string) $row['manifest_guid'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
