<?php
declare(strict_types=1);

namespace App\Tenant;

use PDO;

final class TemplateRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<Template> */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM templates WHERE tenant_id = :tid ORDER BY name'
        );
        $stmt->execute([':tid' => $tenantId]);
        return array_map(static fn(array $r) => Template::fromRow($r), $stmt->fetchAll());
    }

    public function find(int $id, ?int $tenantId = null): ?Template
    {
        $sql  = 'SELECT * FROM templates WHERE id = :id';
        $args = [':id' => $id];
        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tid';
            $args[':tid'] = $tenantId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        return $row === false ? null : Template::fromRow($row);
    }

    public function create(int $tenantId, string $name, string $sanitizedHtml): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO templates (tenant_id, name, html) VALUES (:tid, :name, :html)'
        );
        $stmt->execute([
            ':tid'  => $tenantId,
            ':name' => $name,
            ':html' => $sanitizedHtml,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, int $tenantId, string $name, string $sanitizedHtml): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE templates SET name = :name, html = :html
             WHERE id = :id AND tenant_id = :tid'
        );
        $stmt->execute([
            ':id'   => $id,
            ':tid'  => $tenantId,
            ':name' => $name,
            ':html' => $sanitizedHtml,
        ]);
    }

    public function delete(int $id, int $tenantId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM templates WHERE id = :id AND tenant_id = :tid'
        );
        $stmt->execute([':id' => $id, ':tid' => $tenantId]);
    }
}
