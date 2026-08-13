<?php
declare(strict_types=1);

namespace App\Http;

use PDO;
use Throwable;

/**
 * DB-backed fixed-window rate limiter — the only shape that works on shared
 * hosting (no shared memory, no Redis, no cron). One row per bucket; the
 * window resets lazily when the next hit arrives after it elapsed.
 *
 * Fail-open by design: if the rate_limits table is missing (migration not
 * yet applied) or the DB hiccups, the request is allowed. A rate limiter
 * must never take the signature API down with it.
 */
final class RateLimiter
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Read-only probe: is the bucket currently within its limit? Does NOT
     * record a hit — use for "is this client already blocked?" checks where
     * the hit is only recorded on specific outcomes (e.g. auth failures).
     */
    public function check(string $bucket, int $limit, int $windowSeconds): bool
    {
        $bucket = mb_substr($bucket, 0, 64);
        try {
            $stmt = $this->pdo->prepare(
                'SELECT hits FROM rate_limits
                 WHERE bucket = :b AND window_start >= DATE_SUB(NOW(), INTERVAL :w SECOND)'
            );
            $stmt->execute([':b' => $bucket, ':w' => $windowSeconds]);
            $hits = $stmt->fetchColumn();
            return $hits === false || (int) $hits < $limit;
        } catch (Throwable) {
            return true; // fail open — see class docblock
        }
    }

    /**
     * Records a hit on the bucket and returns whether the request is still
     * within the limit.
     *
     * @param string $bucket        e.g. "sig:acme" or "sigfail:203.0.113.7"
     * @param int    $limit         max hits per window
     * @param int    $windowSeconds window length
     */
    public function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        $bucket = mb_substr($bucket, 0, 64);
        try {
            // Atomic upsert: start a fresh window when the old one elapsed,
            // otherwise increment within the current window.
            $this->pdo->prepare(
                'INSERT INTO rate_limits (bucket, window_start, hits)
                 VALUES (:b, NOW(), 1)
                 ON DUPLICATE KEY UPDATE
                     hits = IF(window_start < DATE_SUB(NOW(), INTERVAL :w SECOND), 1, hits + 1),
                     window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL :w2 SECOND), NOW(), window_start)'
            )->execute([':b' => $bucket, ':w' => $windowSeconds, ':w2' => $windowSeconds]);

            $stmt = $this->pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = :b');
            $stmt->execute([':b' => $bucket]);
            $hits = (int) $stmt->fetchColumn();

            return $hits <= $limit;
        } catch (Throwable) {
            return true; // fail open — see class docblock
        }
    }
}
