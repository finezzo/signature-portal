<?php
declare(strict_types=1);

namespace App\Tenant;

use PDO;

/**
 * CRUD for `user_profiles`, the local write-through store in front of Graph.
 *
 * Every statement is scoped by tenant_id — profiles are tenant-owned data and
 * the (tenant_id, email) pair is the only lookup key the signature pipeline
 * uses. Emails are lowercased on the way in and on the way out of a lookup so
 * they match what /api/sig normalises its query parameters to.
 *
 * Upserts are deliberately SELECT-then-INSERT/UPDATE rather than MySQL's
 * ON DUPLICATE KEY UPDATE: the repository tests run against in-memory SQLite,
 * and plain ANSI statements keep both engines on the same code path.
 */
final class ProfileRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<StoredProfile> */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_profiles WHERE tenant_id = :tid ORDER BY email ASC'
        );
        $stmt->execute([':tid' => $tenantId]);
        return array_map(static fn(array $r) => StoredProfile::fromRow($r), $stmt->fetchAll());
    }

    public function find(int $id, int $tenantId): ?StoredProfile
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_profiles WHERE id = :id AND tenant_id = :tid'
        );
        $stmt->execute([':id' => $id, ':tid' => $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : StoredProfile::fromRow($row);
    }

    public function findByEmail(int $tenantId, string $email): ?StoredProfile
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_profiles WHERE tenant_id = :tid AND email = :email LIMIT 1'
        );
        $stmt->execute([':tid' => $tenantId, ':email' => mb_strtolower($email)]);
        $row = $stmt->fetch();
        return $row === false ? null : StoredProfile::fromRow($row);
    }

    /**
     * Write-through after a successful Graph fetch.
     *
     * Graph always wins: an existing csv/manual row is overwritten and its
     * source flips to 'graph', because live directory data is by definition
     * more correct than whatever was seeded by hand.
     *
     * @param array<string,mixed> $raw the Graph response as decoded JSON
     */
    public function upsertFromGraph(int $tenantId, string $email, array $raw): void
    {
        $email       = mb_strtolower($email);
        $displayName = isset($raw['displayName']) ? (string) $raw['displayName'] : null;
        $json        = $this->encode($raw);
        $existingId  = $this->findId($tenantId, $email);

        if ($existingId === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO user_profiles
                     (tenant_id, email, display_name, source, profile_json, graph_synced_at)
                 VALUES (:tid, :email, :name, :source, :json, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                ':tid'    => $tenantId,
                ':email'  => $email,
                ':name'   => $displayName,
                ':source' => 'graph',
                ':json'   => $json,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE user_profiles
                SET display_name    = :name,
                    source          = :source,
                    profile_json    = :json,
                    graph_synced_at = CURRENT_TIMESTAMP,
                    updated_at      = CURRENT_TIMESTAMP
              WHERE id = :id AND tenant_id = :tid'
        );
        $stmt->execute([
            ':id'     => $existingId,
            ':tid'    => $tenantId,
            ':name'   => $displayName,
            ':source' => 'graph',
            ':json'   => $json,
        ]);
    }

    /**
     * Insert or update a locally sourced profile ('csv' or 'manual').
     *
     * graph_synced_at is left untouched on update — it records when Graph last
     * confirmed the row, and a manual edit does not confirm anything.
     *
     * @param array<string,mixed> $raw the Graph response as decoded JSON
     * @return int the row id
     */
    public function upsert(int $tenantId, string $email, array $raw, string $source): int
    {
        $email       = mb_strtolower($email);
        $displayName = isset($raw['displayName']) ? (string) $raw['displayName'] : null;
        $json        = $this->encode($raw);
        $existingId  = $this->findId($tenantId, $email);

        if ($existingId === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO user_profiles
                     (tenant_id, email, display_name, source, profile_json)
                 VALUES (:tid, :email, :name, :source, :json)'
            );
            $stmt->execute([
                ':tid'    => $tenantId,
                ':email'  => $email,
                ':name'   => $displayName,
                ':source' => $source,
                ':json'   => $json,
            ]);
            return (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare(
            'UPDATE user_profiles
                SET display_name = :name,
                    source       = :source,
                    profile_json = :json,
                    updated_at   = CURRENT_TIMESTAMP
              WHERE id = :id AND tenant_id = :tid'
        );
        $stmt->execute([
            ':id'     => $existingId,
            ':tid'    => $tenantId,
            ':name'   => $displayName,
            ':source' => $source,
            ':json'   => $json,
        ]);
        return $existingId;
    }

    /**
     * Edit an existing row by id — the portal's "edit this profile" path, where
     * the email itself may change.
     *
     * @param array<string,mixed> $raw the Graph response as decoded JSON
     */
    public function updateById(int $id, int $tenantId, string $email, array $raw, string $source): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_profiles
                SET email        = :email,
                    display_name = :name,
                    source       = :source,
                    profile_json = :json,
                    updated_at   = CURRENT_TIMESTAMP
              WHERE id = :id AND tenant_id = :tid'
        );
        $stmt->execute([
            ':id'     => $id,
            ':tid'    => $tenantId,
            ':email'  => mb_strtolower($email),
            ':name'   => isset($raw['displayName']) ? (string) $raw['displayName'] : null,
            ':source' => $source,
            ':json'   => $this->encode($raw),
        ]);
    }

    public function delete(int $id, int $tenantId): void
    {
        $this->pdo->prepare('DELETE FROM user_profiles WHERE id = :id AND tenant_id = :tid')
            ->execute([':id' => $id, ':tid' => $tenantId]);
    }

    /** Existing row id for the (tenant, lowercased email) key, or null. */
    private function findId(int $tenantId, string $email): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM user_profiles WHERE tenant_id = :tid AND email = :email LIMIT 1'
        );
        $stmt->execute([':tid' => $tenantId, ':email' => $email]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array<string,mixed> $raw */
    private function encode(array $raw): string
    {
        return (string) json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
