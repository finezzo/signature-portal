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
        public readonly ?string $apiKeyAcknowledgedAt,
        public readonly ?string $manifestGuid,
        public readonly bool $ssoEnabled,
        public readonly bool $ssoAutoProvision,
        public readonly string $ssoDefaultRole,
        public readonly string $sharedDisplayMode,
        /** @var array<string,string> lowercase-domain → mode */
        public readonly array $sharedDisplayModePerDomain,
        public readonly ?string $disclaimerHtml,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /**
     * Picks the display mode that should apply when the FROM mailbox is on
     * the given domain. Per-domain override wins; otherwise the tenant's
     * default `sharedDisplayMode`.
     */
    public function displayModeForDomain(string $domain): string
    {
        $d = mb_strtolower(trim($domain));
        return $this->sharedDisplayModePerDomain[$d] ?? $this->sharedDisplayMode;
    }

    /**
     * Decode the JSON column into a clean string→string map. Defensive:
     * a malformed value just collapses to an empty map (= use default
     * mode everywhere).
     *
     * @return array<string,string>
     */
    private static function decodeDomainModeMap(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (!is_string($k) || !is_string($v)) continue;
            $k = mb_strtolower(trim($k));
            if ($k === '' || !in_array($v, ['shared', 'primary', 'derived'], true)) continue;
            $out[$k] = $v;
        }
        return $out;
    }

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
            isset($row['api_key_acknowledged_at']) && $row['api_key_acknowledged_at'] !== null ? (string) $row['api_key_acknowledged_at'] : null,
            $row['manifest_guid'] !== null ? (string) $row['manifest_guid'] : null,
            (bool) ($row['sso_enabled'] ?? 0),
            (bool) ($row['sso_auto_provision'] ?? 0),
            (string) ($row['sso_default_role'] ?? 'tenant_editor'),
            (string) ($row['shared_display_mode'] ?? 'shared'),
            self::decodeDomainModeMap($row['shared_display_mode_per_domain'] ?? null),
            isset($row['disclaimer_html']) && $row['disclaimer_html'] !== '' ? (string) $row['disclaimer_html'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
