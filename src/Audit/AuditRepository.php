<?php
declare(strict_types=1);

namespace App\Audit;

use PDO;

final class AuditRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(int $tenantId, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt  = $this->pdo->prepare(
            'SELECT * FROM audit_log
             WHERE tenant_id = :tid
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([':tid' => $tenantId]);
        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listGlobal(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt  = $this->pdo->query(
            'SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . $limit
        );
        return $stmt->fetchAll();
    }
}
