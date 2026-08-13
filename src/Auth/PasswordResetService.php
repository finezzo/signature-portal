<?php
declare(strict_types=1);

namespace App\Auth;

use App\Mail\Mailer;
use PDO;

/**
 * Self-service "forgot password" flow for local-auth portal users.
 *
 * Design notes:
 *  - The reset token is 32 random bytes; only its SHA-256 hash is stored, so
 *    a DB read never yields a usable link.
 *  - request() is intentionally silent about whether the email exists —
 *    the UI always shows the same message (no user enumeration).
 *  - SSO-only users (no password_hash) never receive a link; their password
 *    lives in Entra.
 *  - Per-user throttle: at most MAX_ACTIVE_REQUESTS links per hour, so the
 *    endpoint can't be used to flood someone's inbox.
 */
final class PasswordResetService
{
    private const TOKEN_TTL_MINUTES    = 30;
    private const MAX_ACTIVE_REQUESTS  = 3;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
        private readonly string $baseUrl,
    ) {}

    /**
     * Issue a reset link for the account, if it exists and is local-auth.
     * Always returns without revealing whether anything was sent.
     */
    public function request(string $email): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email FROM users
             WHERE email = :email AND password_hash IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute([':email' => mb_strtolower(trim($email))]);
        $user = $stmt->fetch();
        if ($user === false) {
            return;
        }
        $userId = (int) $user['id'];

        // Throttle: count links issued in the last hour.
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM password_resets
             WHERE user_id = :uid AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $count->execute([':uid' => $userId]);
        if ((int) $count->fetchColumn() >= self::MAX_ACTIVE_REQUESTS) {
            error_log('[pwreset] throttled request for user id=' . $userId);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL :ttl MINUTE))'
        )->execute([
            ':uid'  => $userId,
            ':hash' => hash('sha256', $token),
            ':ttl'  => self::TOKEN_TTL_MINUTES,
        ]);

        $link = rtrim($this->baseUrl, '/') . '/portal/password/reset?token=' . $token;
        $body = "Hello,\n\n"
              . "a password reset was requested for your SignaturePortal account.\n"
              . "Open the link below to choose a new password. It is valid for "
              . self::TOKEN_TTL_MINUTES . " minutes and can be used once:\n\n"
              . $link . "\n\n"
              . "If you did not request this, you can ignore this email — your\n"
              . "password stays unchanged.\n";

        $sent = $this->mailer->send((string) $user['email'], 'Reset your SignaturePortal password', $body);
        error_log('[pwreset] link ' . ($sent ? 'sent' : 'SEND FAILED') . ' for user id=' . $userId);
    }

    /**
     * Validate a token without consuming it (for showing the reset form).
     * Returns the user id, or null if the token is unknown/expired/used.
     */
    public function validateToken(string $token): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM password_resets
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        return $row === false ? null : (int) $row['user_id'];
    }

    /**
     * Consume the token and set the new password. Also clears any lockout and
     * bumps password_changed_at so every existing session of the user dies.
     * Returns false if the token was invalid (expired, used, unknown).
     */
    public function reset(string $token, string $newPassword): bool
    {
        $userId = $this->validateToken($token);
        if ($userId === null) {
            return false;
        }

        $this->pdo->prepare(
            'UPDATE password_resets SET used_at = NOW()
             WHERE token_hash = :hash AND used_at IS NULL'
        )->execute([':hash' => hash('sha256', $token)]);

        $this->pdo->prepare(
            'UPDATE users
             SET password_hash        = :hash,
                 password_changed_at  = NOW(),
                 failed_login_count   = 0,
                 last_failed_login_at = NULL,
                 locked_until         = NULL
             WHERE id = :id'
        )->execute([
            ':hash' => $this->hasher->hash($newPassword),
            ':id'   => $userId,
        ]);

        error_log('[pwreset] password reset completed for user id=' . $userId);
        return true;
    }
}
