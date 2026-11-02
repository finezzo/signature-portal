<?php
declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditLogger;
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
 * The lockout is per-user (matched by email). Per-IP throttling lives in
 * AuthController, on top of the DB-backed RateLimiter.
 *
 * An account with two-factor authentication passes through a pending state: the
 * password only earns the right to enter a code, for {@see MFA_PENDING_SECONDS}.
 * Wrong codes count towards the same lockout as wrong passwords.
 */
final class Authenticator
{
    public const MAX_FAILED_ATTEMPTS    = 5;
    public const FAILURE_WINDOW_MINUTES = 10;
    public const LOCKOUT_DURATION_MINUTES = 15;
    public const MFA_PENDING_SECONDS    = 300;

    private const MFA_PENDING_KEY = 'mfa_pending';

    /**
     * A fixed, valid bcrypt hash (cost 10, matching PASSWORD_BCRYPT) that no
     * real password produces. It is verified against on the user-not-found
     * path solely to equalize response timing — without it, "no such account"
     * returns in microseconds while "account exists, wrong password" spends
     * ~100 ms in bcrypt, letting an attacker enumerate valid portal accounts.
     * It is not a credential — no password produces it and it grants nothing.
     */
    // nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash
    private const DUMMY_HASH = '$2y$10$gUuEO/nH9k62RQI7Od/4m.fbl8G18X6j8kmU0wgx97wxr2A5KlD7m';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $session,
        private readonly MfaService $mfa,
        private readonly AuditLogger $audit,
    ) {}

    public function attempt(string $email, string $password): AuthAttemptResult
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, email, password_hash, password_changed_at, role, name,
                    failed_login_count, last_failed_login_at, locked_until, mfa_enabled_at
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => mb_strtolower(trim($email))]);
        $user = $stmt->fetch();

        if ($user === false || empty($user['password_hash'])) {
            // Burn the same time a real bcrypt verify would, so the response
            // time does not reveal whether the account exists (or is SSO-only).
            $this->hasher->verify($password, self::DUMMY_HASH);
            return AuthAttemptResult::badCredentials();
        }

        // Active lockout?
        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            // Same bcrypt cost as a wrong password, otherwise the fast response
            // reveals that this account exists and is currently locked.
            $this->hasher->verify($password, self::DUMMY_HASH);
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

        if (!empty($user['mfa_enabled_at'])) {
            // The failure counter stays as it is. Resetting it here would let
            // someone who knows the password try codes forever, by entering the
            // password again before each lockout.
            $this->session->regenerate();
            $this->session->forget('user');
            $this->session->set(self::MFA_PENDING_KEY, [
                'id'       => (int) $user['id'],
                'pw_epoch' => (string) ($user['password_changed_at'] ?? ''),
                'at'       => time(),
            ]);
            return AuthAttemptResult::mfaRequired();
        }

        $this->signIn((array) $user);
        return AuthAttemptResult::success();
    }

    public function hasPendingSecondFactor(): bool
    {
        $pending = $this->session->get(self::MFA_PENDING_KEY);
        if (!is_array($pending)) {
            return false;
        }
        if (time() - (int) $pending['at'] > self::MFA_PENDING_SECONDS) {
            $this->session->forget(self::MFA_PENDING_KEY);
            return false;
        }
        return true;
    }

    public function attemptSecondFactor(string $code): AuthAttemptResult
    {
        if (!$this->hasPendingSecondFactor()) {
            return AuthAttemptResult::badCredentials();
        }
        $pending = (array) $this->session->get(self::MFA_PENDING_KEY);

        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, email, password_changed_at, role, name,
                    failed_login_count, last_failed_login_at, locked_until
             FROM users WHERE id = :id'
        );
        $stmt->execute([':id' => (int) $pending['id']]);
        $user = $stmt->fetch();

        // A password change since the first step voids it, as it does a session.
        if ($user === false || (string) ($user['password_changed_at'] ?? '') !== (string) $pending['pw_epoch']) {
            $this->session->forget(self::MFA_PENDING_KEY);
            return AuthAttemptResult::badCredentials();
        }
        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            $this->session->forget(self::MFA_PENDING_KEY);
            return AuthAttemptResult::lockedUntil((string) $user['locked_until']);
        }

        $tenantId = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
        $factor   = $this->mfa->verifyCode((int) $user['id'], $code);
        if ($factor === null) {
            $lockedUntil = $this->recordFailure((array) $user);
            // Whoever got here knows the password, which is worth a trace.
            $this->audit->record($tenantId, 'auth.mfa.failed', 'user', (int) $user['id'], 'Wrong two-factor code after a correct password');
            if ($lockedUntil !== null) {
                $this->session->forget(self::MFA_PENDING_KEY);
                return AuthAttemptResult::lockedUntil($lockedUntil);
            }
            return AuthAttemptResult::badCredentials();
        }

        $this->signIn((array) $user);
        if ($factor === MfaService::FACTOR_RECOVERY) {
            $this->audit->record($tenantId, 'auth.mfa.recovery_used', 'user', (int) $user['id'], 'Signed in with a recovery code');
        }
        return AuthAttemptResult::success($factor);
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    /** @param array<string,mixed> $user */
    private function signIn(array $user): void
    {
        $this->pdo->prepare(
            'UPDATE users
             SET last_login_at = NOW(),
                 failed_login_count = 0,
                 last_failed_login_at = NULL,
                 locked_until = NULL
             WHERE id = :id'
        )->execute([':id' => (int) $user['id']]);

        $this->session->regenerate();
        $this->session->forget(self::MFA_PENDING_KEY);
        $this->session->set('user', [
            'id'        => (int) $user['id'],
            'email'     => (string) $user['email'],
            'name'      => $user['name'] !== null ? (string) $user['name'] : null,
            'role'      => (string) $user['role'],
            'tenant_id' => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
            // Captured so AuthMiddleware can kill this session if the password
            // changes elsewhere (self-service reset, admin reset).
            'pw_epoch'  => (string) ($user['password_changed_at'] ?? ''),
            // AuthMiddleware asks password sessions for a second factor, not SSO ones.
            'auth_method' => 'local',
        ]);
    }

    /**
     * @param array<string,mixed> $user
     * @return string|null when the account is now locked until, if this locked it
     */
    private function recordFailure(array $user): ?string
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
        return $lockUntil;
    }
}
