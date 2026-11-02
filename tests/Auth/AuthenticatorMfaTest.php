<?php
declare(strict_types=1);

namespace Tests\Auth;

use App\Audit\AuditLogger;
use App\Auth\AuthAttemptResult;
use App\Auth\Authenticator;
use App\Auth\MfaService;
use App\Auth\PasswordHasher;
use App\Auth\SessionManager;
use App\Auth\Totp;
use App\Config\Config;
use App\Crypto\Encryption;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MfaTestDatabase.php';

final class AuthenticatorMfaTest extends TestCase
{
    private const EMAIL    = 'x@example.com';
    private const PASSWORD = 'correct horse battery';
    private const USER     = 5;

    private PDO $pdo;
    private Authenticator $auth;
    private MfaService $mfa;
    private string $secret;
    /** @var list<string> */
    private array $recoveryCodes;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = MfaTestDatabase::create();

        $hasher = new PasswordHasher();
        $insert = $this->pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password_hash, password_changed_at, role)
             VALUES (:id, 2, :email, :hash, '2026-01-01 00:00:00', 'tenant_admin')"
        );
        $insert->execute([':id' => self::USER, ':email' => self::EMAIL, ':hash' => $hasher->hash(self::PASSWORD)]);

        $session   = new SessionManager(new Config([]));
        $this->mfa = new MfaService($this->pdo, new Encryption(Encryption::generateAppKey()));
        $this->auth = new Authenticator($this->pdo, $hasher, $session, $this->mfa, new AuditLogger($this->pdo, $session));

        $this->secret        = Totp::generateSecret();
        $this->recoveryCodes = $this->mfa->enable(self::USER, $this->secret, intdiv(time(), 30) - 5);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function currentCode(): string
    {
        return Totp::code($this->secret, intdiv(time(), 30));
    }

    /** @return array<string,mixed> */
    private function userRow(): array
    {
        return (array) $this->pdo->query('SELECT * FROM users WHERE id = ' . self::USER)->fetch();
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :a');
        $stmt->execute([':a' => $action]);
        return (int) $stmt->fetchColumn();
    }

    public function testPasswordAloneDoesNotSignIn(): void
    {
        $result = $this->auth->attempt(self::EMAIL, self::PASSWORD);

        $this->assertSame(AuthAttemptResult::STATE_MFA_REQUIRED, $result->state);
        $this->assertFalse($result->ok());
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertTrue($this->auth->hasPendingSecondFactor());
        $this->assertNull($this->userRow()['last_login_at']);
    }

    public function testWrongPasswordNeverReachesTheSecondStep(): void
    {
        $result = $this->auth->attempt(self::EMAIL, 'wrong');

        $this->assertSame(AuthAttemptResult::STATE_BAD_CREDENTIALS, $result->state);
        $this->assertFalse($this->auth->hasPendingSecondFactor());
    }

    public function testCorrectCodeSignsIn(): void
    {
        $this->auth->attempt(self::EMAIL, self::PASSWORD);

        $result = $this->auth->attemptSecondFactor($this->currentCode());

        $this->assertTrue($result->ok());
        $this->assertSame(MfaService::FACTOR_TOTP, $result->secondFactor);
        $this->assertSame(self::USER, $_SESSION['user']['id']);
        $this->assertSame('local', $_SESSION['user']['auth_method']);
        $this->assertSame('2026-01-01 00:00:00', $_SESSION['user']['pw_epoch']);
        $this->assertFalse($this->auth->hasPendingSecondFactor());
        $this->assertNotNull($this->userRow()['last_login_at']);
    }

    public function testRecoveryCodeSignsInAndLeavesATrace(): void
    {
        $this->auth->attempt(self::EMAIL, self::PASSWORD);

        $result = $this->auth->attemptSecondFactor($this->recoveryCodes[0]);

        $this->assertTrue($result->ok());
        $this->assertSame(MfaService::FACTOR_RECOVERY, $result->secondFactor);
        $this->assertSame(1, $this->auditCount('auth.mfa.recovery_used'));
    }

    public function testCodeWithoutThePasswordStepIsRefused(): void
    {
        $result = $this->auth->attemptSecondFactor($this->currentCode());

        $this->assertFalse($result->ok());
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testWrongCodesLockTheAccount(): void
    {
        $this->auth->attempt(self::EMAIL, self::PASSWORD);

        for ($i = 1; $i < Authenticator::MAX_FAILED_ATTEMPTS; $i++) {
            $this->assertSame(AuthAttemptResult::STATE_BAD_CREDENTIALS, $this->auth->attemptSecondFactor('000000')->state);
        }
        $this->assertSame(AuthAttemptResult::STATE_LOCKED, $this->auth->attemptSecondFactor('000000')->state);
        $this->assertSame(Authenticator::MAX_FAILED_ATTEMPTS, $this->auditCount('auth.mfa.failed'));

        // Locked means locked, with the correct code as with the correct password.
        $this->assertFalse($this->auth->hasPendingSecondFactor());
        $this->assertFalse($this->auth->attemptSecondFactor($this->currentCode())->ok());
        $this->assertSame(AuthAttemptResult::STATE_LOCKED, $this->auth->attempt(self::EMAIL, self::PASSWORD)->state);
    }

    /** Otherwise knowing the password would allow unlimited guesses at the code. */
    public function testEnteringThePasswordAgainDoesNotResetTheCounter(): void
    {
        for ($i = 1; $i < Authenticator::MAX_FAILED_ATTEMPTS; $i++) {
            $this->auth->attempt(self::EMAIL, self::PASSWORD);
            $this->auth->attemptSecondFactor('000000');
        }
        $this->assertSame(Authenticator::MAX_FAILED_ATTEMPTS - 1, (int) $this->userRow()['failed_login_count']);

        $this->auth->attempt(self::EMAIL, self::PASSWORD);
        $this->assertSame(Authenticator::MAX_FAILED_ATTEMPTS - 1, (int) $this->userRow()['failed_login_count']);

        $this->assertSame(AuthAttemptResult::STATE_LOCKED, $this->auth->attemptSecondFactor('000000')->state);
    }

    public function testCompletedSignInResetsTheCounter(): void
    {
        $this->auth->attempt(self::EMAIL, self::PASSWORD);
        $this->auth->attemptSecondFactor('000000');
        $this->assertSame(1, (int) $this->userRow()['failed_login_count']);

        $this->auth->attemptSecondFactor($this->currentCode());

        $this->assertSame(0, (int) $this->userRow()['failed_login_count']);
    }

    public function testPendingStepExpires(): void
    {
        $this->auth->attempt(self::EMAIL, self::PASSWORD);
        $_SESSION['mfa_pending']['at'] = time() - Authenticator::MFA_PENDING_SECONDS - 1;

        $this->assertFalse($this->auth->hasPendingSecondFactor());
        $this->assertFalse($this->auth->attemptSecondFactor($this->currentCode())->ok());
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testPasswordChangeVoidsThePendingStep(): void
    {
        $this->auth->attempt(self::EMAIL, self::PASSWORD);
        $this->pdo->exec("UPDATE users SET password_changed_at = '2026-06-01 00:00:00' WHERE id = " . self::USER);

        $this->assertFalse($this->auth->attemptSecondFactor($this->currentCode())->ok());
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertFalse($this->auth->hasPendingSecondFactor());
    }

    public function testAccountWithoutSecondFactorSignsInWithThePassword(): void
    {
        $this->mfa->disable(self::USER);

        $result = $this->auth->attempt(self::EMAIL, self::PASSWORD);

        $this->assertTrue($result->ok());
        $this->assertSame('local', $_SESSION['user']['auth_method']);
    }

    public function testASignedInSessionIsDroppedWhenAnotherAccountStartsTheSecondStep(): void
    {
        $_SESSION['user'] = ['id' => 99, 'email' => 'other@example.com', 'auth_method' => 'local'];

        $this->auth->attempt(self::EMAIL, self::PASSWORD);

        $this->assertArrayNotHasKey('user', $_SESSION);
    }
}
