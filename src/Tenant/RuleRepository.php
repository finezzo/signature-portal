<?php
declare(strict_types=1);

namespace App\Tenant;

use PDO;

final class RuleRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<Rule> Ordered: non-fallback by priority asc, then fallbacks. */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rules
             WHERE tenant_id = :tid
             ORDER BY is_fallback ASC, priority ASC, id ASC'
        );
        $stmt->execute([':tid' => $tenantId]);
        return array_map(static fn(array $r) => Rule::fromRow($r), $stmt->fetchAll());
    }

    public function find(int $id, ?int $tenantId = null): ?Rule
    {
        $sql  = 'SELECT * FROM rules WHERE id = :id';
        $args = [':id' => $id];
        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tid';
            $args[':tid'] = $tenantId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        return $row === false ? null : Rule::fromRow($row);
    }

    public function create(
        int $tenantId,
        int $templateId,
        string $fromDomain,
        ?string $language,
        ?string $mailboxType,
        string $recipientScope,
        int $priority,
        bool $isFallback,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rules
                (tenant_id, template_id, from_domain, language, mailbox_type, recipient_scope, priority, is_fallback)
             VALUES (:tid, :pid, :dom, :lang, :mtype, :scope, :prio, :fb)'
        );
        $stmt->execute([
            ':tid'   => $tenantId,
            ':pid'   => $templateId,
            ':dom'   => mb_strtolower($fromDomain),
            ':lang'  => $language,
            ':mtype' => $mailboxType,
            ':scope' => $recipientScope,
            ':prio'  => $priority,
            ':fb'    => $isFallback ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        int $tenantId,
        int $templateId,
        string $fromDomain,
        ?string $language,
        ?string $mailboxType,
        string $recipientScope,
        int $priority,
        bool $isFallback,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE rules SET
                template_id     = :pid,
                from_domain     = :dom,
                language        = :lang,
                mailbox_type    = :mtype,
                recipient_scope = :scope,
                priority        = :prio,
                is_fallback     = :fb
             WHERE id = :id AND tenant_id = :tid'
        );
        $stmt->execute([
            ':id'    => $id,
            ':tid'   => $tenantId,
            ':pid'   => $templateId,
            ':dom'   => mb_strtolower($fromDomain),
            ':lang'  => $language,
            ':mtype' => $mailboxType,
            ':scope' => $recipientScope,
            ':prio'  => $priority,
            ':fb'    => $isFallback ? 1 : 0,
        ]);
    }

    public function delete(int $id, int $tenantId): void
    {
        $this->pdo->prepare('DELETE FROM rules WHERE id = :id AND tenant_id = :tid')
            ->execute([':id' => $id, ':tid' => $tenantId]);
    }
}
