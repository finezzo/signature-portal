<?php
declare(strict_types=1);

namespace App\Tenant;

use PDO;

final class TenantRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<Tenant> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM tenants ORDER BY name')->fetchAll();
        return array_map(static fn(array $r) => Tenant::fromRow($r), $rows);
    }

    public function find(int $id): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : Tenant::fromRow($row);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenants WHERE slug = :slug');
        $stmt->execute([':slug' => mb_strtolower($slug)]);
        $row = $stmt->fetch();
        return $row === false ? null : Tenant::fromRow($row);
    }

    /** @param list<string> $emailDomains */
    public function create(
        string $slug,
        string $name,
        array $emailDomains,
        string $manifestGuid,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenants (slug, name, email_domains, manifest_guid)
             VALUES (:slug, :name, :domains, :guid)'
        );
        $stmt->execute([
            ':slug'    => mb_strtolower($slug),
            ':name'    => $name,
            ':domains' => json_encode(array_values($emailDomains), JSON_THROW_ON_ERROR),
            ':guid'    => $manifestGuid,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<string> $emailDomains */
    public function update(
        int $id,
        string $name,
        array $emailDomains,
        ?string $entraTenantId,
        ?string $entraClientId,
        ?string $entraClientSecretEncrypted,
        bool $ssoEnabled,
        bool $ssoAutoProvision,
        string $ssoDefaultRole,
        string $sharedDisplayMode,
        /** @var array<string,string> $sharedDisplayModePerDomain */
        array $sharedDisplayModePerDomain,
        ?string $disclaimerHtml,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE tenants
             SET name = :name,
                 email_domains = :domains,
                 entra_tenant_id = :etid,
                 entra_client_id = :ecid,
                 entra_client_secret_encrypted = COALESCE(:esec, entra_client_secret_encrypted),
                 sso_enabled = :sso,
                 sso_auto_provision = :prov,
                 sso_default_role = :role,
                 shared_display_mode = :sdmode,
                 shared_display_mode_per_domain = :sdmpd,
                 disclaimer_html = :disc
             WHERE id = :id'
        );
        $stmt->execute([
            ':id'      => $id,
            ':name'    => $name,
            ':domains' => json_encode(array_values($emailDomains), JSON_THROW_ON_ERROR),
            ':etid'    => $entraTenantId,
            ':ecid'    => $entraClientId,
            ':esec'    => $entraClientSecretEncrypted,
            // null on :esec → leave the existing encrypted secret untouched.
            ':sso'     => $ssoEnabled ? 1 : 0,
            ':prov'    => $ssoAutoProvision ? 1 : 0,
            ':role'    => $ssoDefaultRole,
            ':sdmode'  => $sharedDisplayMode,
            ':sdmpd'   => $sharedDisplayModePerDomain === []
                ? null
                : json_encode((object) $sharedDisplayModePerDomain, JSON_THROW_ON_ERROR),
            ':disc'    => $disclaimerHtml,
        ]);
    }

    public function setApiKey(int $id, string $apiKeyEncrypted): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tenants
             SET api_key_encrypted = :k,
                 api_key_last_rotated_at = NOW(),
                 api_key_acknowledged_at = NULL
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id, ':k' => $apiKeyEncrypted]);
    }

    public function acknowledgeApiKey(int $id): void
    {
        $this->pdo->prepare('UPDATE tenants SET api_key_acknowledged_at = NOW() WHERE id = :id')
            ->execute([':id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute([':id' => $id]);
    }
}
