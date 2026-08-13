<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;

/**
 * Portal-user CRUD. Used by the User-Management UI.
 *
 * Scope rules:
 *   - tenant_id NULL → cross-tenant role (only superadmin uses this).
 *   - tenant_id set  → user is scoped to one tenant.
 *
 * `auth_provider`:
 *   - 'local'  → password_hash required.
 *   - 'entra'  → entra_object_id required, password_hash optional.
 */
final class UserRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, tenant_id, email, name, role, auth_provider, last_login_at, locked_until, created_at
             FROM users
             ORDER BY (tenant_id IS NULL) DESC, tenant_id ASC, email ASC'
        );
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, email, name, role, auth_provider, last_login_at, locked_until, created_at
             FROM users
             WHERE tenant_id = :tid
             ORDER BY email ASC'
        );
        $stmt->execute([':tid' => $tenantId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function existsByEmail(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM users WHERE email = :email';
        $args = [':email' => mb_strtolower($email)];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $args[':id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetch() !== false;
    }

    public function create(
        ?int $tenantId,
        string $email,
        string $passwordHash,
        string $role,
        ?string $name,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, email, password_hash, role, name, auth_provider)
             VALUES (:tid, :email, :hash, :role, :name, "local")'
        );
        $stmt->execute([
            ':tid'   => $tenantId,
            ':email' => mb_strtolower($email),
            ':hash'  => $passwordHash,
            ':role'  => $role,
            ':name'  => $name,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, ?int $tenantId, string $role, ?string $name): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET tenant_id = :tid, role = :role, name = :name WHERE id = :id'
        );
        $stmt->execute([
            ':id'   => $id,
            ':tid'  => $tenantId,
            ':role' => $role,
            ':name' => $name,
        ]);
    }

    public function setPassword(int $id, string $hash): void
    {
        // password_changed_at is the session-invalidation epoch: bumping it
        // logs the user out of every existing session (see AuthMiddleware).
        $this->pdo->prepare(
            'UPDATE users SET password_hash = :h, password_changed_at = NOW() WHERE id = :id'
        )->execute([':h' => $hash, ':id' => $id]);
    }

    public function unlock(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE users
             SET failed_login_count = 0,
                 last_failed_login_at = NULL,
                 locked_until = NULL
             WHERE id = :id'
        )->execute([':id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')
            ->execute([':id' => $id]);
    }
}
