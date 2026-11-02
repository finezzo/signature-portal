<?php
declare(strict_types=1);

namespace App\Auth;

use App\Crypto\Encryption;
use PDO;
use RuntimeException;
use Throwable;

/**
 * A user's second factor: the encrypted TOTP secret and the one-time recovery
 * codes. Enrolment and sign-in flows live in MfaController and Authenticator.
 */
final class MfaService
{
    public const FACTOR_TOTP     = 'totp';
    public const FACTOR_RECOVERY = 'recovery';

    private const RECOVERY_CODE_COUNT  = 10;
    private const RECOVERY_CODE_LENGTH = 16;
    /** Crockford's base32: no I, L or O, so a code read off paper can't be mistyped into another. */
    private const RECOVERY_ALPHABET    = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Encryption $crypto,
    ) {}

    public function seal(string $secret): string
    {
        return $this->crypto->encrypt($secret);
    }

    /** @throws RuntimeException if the value can't be decrypted */
    public function unseal(string $sealed): string
    {
        return $this->crypto->decrypt($sealed);
    }

    /**
     * Stores a confirmed secret and replaces any recovery codes.
     *
     * @param int $confirmedStep time step of the code that confirmed the secret,
     *                           so that same code can't also sign in
     * @return list<string> the recovery codes in clear, for showing once
     */
    public function enable(int $userId, string $secret, int $confirmedStep): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = $this->generateRecoveryCode();
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE users
                 SET mfa_secret_encrypted = :secret, mfa_enabled_at = :now, mfa_last_step = :step
                 WHERE id = :id'
            )->execute([
                ':secret' => $this->seal($secret),
                ':now'    => date('Y-m-d H:i:s'),
                ':step'   => $confirmedStep,
                ':id'     => $userId,
            ]);
            $this->pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = :id')
                ->execute([':id' => $userId]);

            $insert = $this->pdo->prepare('INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (:id, :hash)');
            foreach ($codes as $code) {
                $insert->execute([':id' => $userId, ':hash' => $this->hashRecoveryCode($code)]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $codes;
    }

    public function disable(int $userId): void
    {
        $this->pdo->prepare(
            'UPDATE users SET mfa_secret_encrypted = NULL, mfa_enabled_at = NULL, mfa_last_step = NULL WHERE id = :id'
        )->execute([':id' => $userId]);
        $this->pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = :id')
            ->execute([':id' => $userId]);
    }

    /**
     * Checks a code typed by the user and uses it up.
     *
     * @return self::FACTOR_*|null which kind of code it was, or null if invalid
     */
    public function verifyCode(int $userId, string $input): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $input));

        if (preg_match('/^\d{6}$/', $code) === 1) {
            return $this->verifyTotp($userId, $code) ? self::FACTOR_TOTP : null;
        }
        if (strlen($code) === self::RECOVERY_CODE_LENGTH) {
            return $this->consumeRecoveryCode($userId, $code) ? self::FACTOR_RECOVERY : null;
        }
        return null;
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = :id AND used_at IS NULL');
        $stmt->execute([':id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    private function verifyTotp(int $userId, string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT mfa_secret_encrypted FROM users WHERE id = :id AND mfa_enabled_at IS NOT NULL');
        $stmt->execute([':id' => $userId]);
        $sealed = $stmt->fetchColumn();
        if ($sealed === false || $sealed === null) {
            return false;
        }

        try {
            $secret = $this->unseal((string) $sealed);
        } catch (RuntimeException) {
            // A changed APP_KEY. Recovery codes still work, as they don't depend on it.
            error_log('[mfa] secret of user ' . $userId . ' cannot be decrypted');
            return false;
        }

        $step = Totp::match($secret, $code, time());
        if ($step === null) {
            return false;
        }

        // Atomic, so two requests racing with the same code can't both pass.
        $update = $this->pdo->prepare(
            'UPDATE users SET mfa_last_step = :step
             WHERE id = :id AND (mfa_last_step IS NULL OR mfa_last_step < :step2)'
        );
        $update->execute([':step' => $step, ':id' => $userId, ':step2' => $step]);
        return $update->rowCount() === 1;
    }

    private function consumeRecoveryCode(int $userId, string $code): bool
    {
        $update = $this->pdo->prepare(
            'UPDATE mfa_recovery_codes SET used_at = :now
             WHERE user_id = :id AND code_hash = :hash AND used_at IS NULL'
        );
        $update->execute([
            ':now'  => date('Y-m-d H:i:s'),
            ':id'   => $userId,
            ':hash' => $this->hashRecoveryCode($code),
        ]);
        return $update->rowCount() === 1;
    }

    private function generateRecoveryCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::RECOVERY_CODE_LENGTH; $i++) {
            $code .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
        }
        return implode('-', str_split($code, 4));
    }

    /**
     * Plain SHA-256 is enough: at 80 random bits a code can't be guessed from its
     * hash, and a slow hash would only slow down every failed sign-in.
     */
    private function hashRecoveryCode(string $code): string
    {
        // Letters people commonly type instead of the digits in the alphabet.
        $normalized = strtr(strtoupper(str_replace('-', '', $code)), 'OIL', '011');
        return hash('sha256', $normalized);
    }
}
