<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;

/**
 * Local-account login flow with anti-bruteforce lockout.
 *
 * After {@see MAX_FAILED_ATTEMPTS} consecutive misses inside
 * {@see FAILURE_WINDOW_MINUTES}, the account is locked for
 * {@see LOCKOUT_DURATION_MINUTES}. The counter resets on successful login or
 * when the previous failure aged out of the window — so a careless typo a
 * day ago doesn't pile onto today's misses.
 *
 * The lockout is per-user (matched by email). Throttling by IP is harder
 * on shared hosting (no shared memory) and offers limited protection
 * against distributed bruteforce, so we don't attempt it here.
 */
final class Authenticator
{
    public const MAX_FAILED_ATTEMPTS    = 5;
    public const FAILURE_WINDOW_MINUTES = 10;
    public const LOCKOUT_DURATION_MINUTES = 15;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $session,
    ) {}

    public function attempt(string $email, string $password): AuthAttemptResult
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, email, password_hash, role, name,
                    failed_login_count, last_failed_login_at, locked_until
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => mb_strtolower(trim($email))]);
        $user = $stmt->fetch();

        if ($user === false || empty($user['password_hash'])) {
            return AuthAttemptResult::badCredentials();
        }

        // Active lockout?
        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            return AuthAttemptResult::lockedUntil((string) $user['locked_until']);
        }

        if (!$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->recordFailure((array) $user);
            return AuthAttemptResult::badCredentials();
        }

        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([
                ':h'  => $this->hasher->hash($password),
                ':id' => $user['id'],
            ]);
        }

        $this->pdo->prepare(
            'UPDATE users
             SET last_login_at = NOW(),
                 failed_login_count = 0,
                 last_failed_login_at = NULL,
                 locked_until = NULL
             WHERE id = :id'
        )->execute([':id' => (int) $user['id']]);

        $this->session->regenerate();
        $this->session->set('user', [
            'id'        => (int) $user['id'],
            'email'     => (string) $user['email'],
            'name'      => $user['name'] !== null ? (string) $user['name'] : null,
            'role'      => (string) $user['role'],
            'tenant_id' => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
        ]);
        return AuthAttemptResult::success();
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    /** @param array<string,mixed> $user */
    private function recordFailure(array $user): void
    {
        $now      = time();
        $lastFail = !empty($user['last_failed_login_at']) ? strtotime((string) $user['last_failed_login_at']) : 0;
        $window   = self::FAILURE_WINDOW_MINUTES * 60;

        // If the previous failure aged out of the window, the chain breaks
        // and we start over at 1 — typo-tolerance.
        $count = ($lastFail > 0 && ($now - $lastFail) <= $window)
            ? ((int) $user['failed_login_count']) + 1
            : 1;

        $lockUntil = null;
        if ($count >= self::MAX_FAILED_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', $now + self::LOCKOUT_DURATION_MINUTES * 60);
        }

        $this->pdo->prepare(
            'UPDATE users
             SET failed_login_count   = :count,
                 last_failed_login_at = NOW(),
                 locked_until         = :lu
             WHERE id = :id'
        )->execute([
            ':count' => $count,
            ':lu'    => $lockUntil,
            ':id'    => (int) $user['id'],
        ]);
    }
}
