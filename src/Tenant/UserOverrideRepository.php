<?php
declare(strict_types=1);

namespace App\Tenant;

use PDO;

final class UserOverrideRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<UserOverride> */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_overrides WHERE tenant_id = :tid ORDER BY email ASC'
        );
        $stmt->execute([':tid' => $tenantId]);
        return array_map(static fn(array $r) => UserOverride::fromRow($r), $stmt->fetchAll());
    }

    public function find(int $id, int $tenantId): ?UserOverride
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_overrides WHERE id = :id AND tenant_id = :tid'
        );
        $stmt->execute([':id' => $id, ':tid' => $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : UserOverride::fromRow($row);
    }

    public function findByEmail(int $tenantId, string $email): ?UserOverride
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_overrides WHERE tenant_id = :tid AND email = :email LIMIT 1'
        );
        $stmt->execute([':tid' => $tenantId, ':email' => mb_strtolower($email)]);
        $row = $stmt->fetch();
        return $row === false ? null : UserOverride::fromRow($row);
    }

    public function create(int $tenantId, string $email, int $templateId, ?string $note): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_overrides (tenant_id, email, template_id, note)
             VALUES (:tid, :email, :pid, :note)'
        );
        $stmt->execute([
            ':tid'   => $tenantId,
            ':email' => mb_strtolower($email),
            ':pid'   => $templateId,
            ':note'  => $note,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, int $tenantId, string $email, int $templateId, ?string $note): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_overrides SET email = :email, template_id = :pid, note = :note
             WHERE id = :id AND tenant_id = :tid'
        );
        $stmt->execute([
            ':id'    => $id,
            ':tid'   => $tenantId,
            ':email' => mb_strtolower($email),
            ':pid'   => $templateId,
            ':note'  => $note,
        ]);
    }

    public function delete(int $id, int $tenantId): void
    {
        $this->pdo->prepare('DELETE FROM user_overrides WHERE id = :id AND tenant_id = :tid')
            ->execute([':id' => $id, ':tid' => $tenantId]);
    }
}
