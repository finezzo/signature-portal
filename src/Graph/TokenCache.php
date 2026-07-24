<?php
declare(strict_types=1);

namespace App\Graph;

use App\Crypto\Encryption;
use PDO;
use RuntimeException;

/**
 * Per-tenant cache for Microsoft Graph access tokens.
 *
 * Tokens issued to client_credentials apps live ~3600 s. We store with a
 * conservative safety margin baked in by the caller, then read them back
 * with a small re-check window so the request-handler doesn't hand out a
 * token that is about to expire.
 *
 * The token is a bearer credential for the whole Entra tenant (User.Read.All),
 * so it is encrypted at rest with the same APP_KEY-backed libsodium box used
 * for the client secret — a DB read (SQLi, backup leak, host access) then
 * yields ciphertext rather than a live Graph token.
 */
final class TokenCache
{
    private const SAFETY_WINDOW_SECONDS = 30;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Encryption $encryption,
    ) {}

    public function get(int $tenantId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT access_token, UNIX_TIMESTAMP(expires_at) AS exp_ts
             FROM graph_token_cache WHERE tenant_id = :tid'
        );
        $stmt->execute([':tid' => $tenantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        if ((int) $row['exp_ts'] - self::SAFETY_WINDOW_SECONDS <= time()) {
            return null;
        }
        try {
            return $this->encryption->decrypt((string) $row['access_token']);
        } catch (RuntimeException) {
            // Legacy plaintext row, tampered ciphertext, or APP_KEY rotation:
            // treat as a cache miss so the caller fetches a fresh token and
            // overwrites the row with a valid encrypted value.
            return null;
        }
    }

    /** @param int $ttlSeconds Lifetime of the token, in seconds. */
    public function store(int $tenantId, string $accessToken, int $ttlSeconds): void
    {
        // Knock 60s off the issuer-supplied TTL so we refresh just before expiry.
        $effectiveTtl = max(60, $ttlSeconds - 60);
        $stmt = $this->pdo->prepare(
            'INSERT INTO graph_token_cache (tenant_id, access_token, expires_at)
             VALUES (:tid, :tok, FROM_UNIXTIME(:exp))
             ON DUPLICATE KEY UPDATE
                 access_token = VALUES(access_token),
                 expires_at   = VALUES(expires_at)'
        );
        $stmt->execute([
            ':tid' => $tenantId,
            ':tok' => $this->encryption->encrypt($accessToken),
            ':exp' => time() + $effectiveTtl,
        ]);
    }

    public function purge(int $tenantId): void
    {
        $this->pdo->prepare('DELETE FROM graph_token_cache WHERE tenant_id = :tid')
            ->execute([':tid' => $tenantId]);
    }
}
