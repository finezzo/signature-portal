<?php
declare(strict_types=1);

namespace App\Audit;

use App\Auth\SessionManager;
use PDO;

/**
 * Writes audit-log entries. Controllers call `record()` after a successful
 * mutation; everything else (current user, IP, timestamp) is filled in here
 * so the call sites stay short.
 */
final class AuditLogger
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SessionManager $session,
    ) {}

    /**
     * @param string $action       short verb-noun, e.g. "template.created"
     * @param string|null $targetType  e.g. "template", "rule", "tenant", "user"
     * @param int|string|null $targetId  primary key of the affected entity
     * @param string|null $summary  one-line human description for the list view
     * @param array<string,mixed>|null $payload  small structured detail
     */
    public function record(
        ?int $tenantId,
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        ?string $summary = null,
        ?array $payload = null,
    ): void {
        $user = $this->session->currentUser();

        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log
                (tenant_id, user_id, user_email, action, target_type, target_id, summary, payload_json, ip_address)
             VALUES (:tid, :uid, :email, :action, :ttype, :tid2, :summary, :payload, :ip)'
        );
        $stmt->execute([
            ':tid'     => $tenantId,
            ':uid'     => $user !== null ? (int) $user['id'] : null,
            ':email'   => $user !== null ? (string) $user['email'] : null,
            ':action'  => $action,
            ':ttype'   => $targetType,
            ':tid2'    => $targetId !== null ? (string) $targetId : null,
            ':summary' => $summary,
            ':payload' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ':ip'      => $this->detectIp(),
        ]);
    }

    private function detectIp(): ?string
    {
        // REMOTE_ADDR only. Slim's PSR-7 doesn't make it readable here without
        // threading the request through to the logger; $_SERVER is fine for
        // shared-host PHP. X-Forwarded-For and friends are client-supplied:
        // honouring them would let anyone write any IP into the audit log.
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip !== '' ? mb_substr($ip, 0, 45) : null;
    }
}
