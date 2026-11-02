<?php
declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\MfaService;
use App\Auth\Totp;
use App\Crypto\Encryption;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MfaTestDatabase.php';

final class MfaServiceTest extends TestCase
{
    private const USER = 7;

    private PDO $pdo;
    private MfaService $mfa;
    private string $secret;

    protected function setUp(): void
    {
        $this->pdo = MfaTestDatabase::create();
        $this->pdo->exec('INSERT INTO users (id, email) VALUES (' . self::USER . ", 'x@example.com'), (8, 'y@example.com')");
        $this->mfa    = new MfaService($this->pdo, new Encryption(Encryption::generateAppKey()));
        $this->secret = Totp::generateSecret();
    }

    private function step(): int
    {
        return intdiv(time(), 30);
    }

    public function testSecretAndRecoveryCodesAreNotStoredInClear(): void
    {
        $codes = $this->mfa->enable(self::USER, $this->secret, $this->step() - 5);

        $stored = (string) $this->pdo->query('SELECT mfa_secret_encrypted FROM users WHERE id = ' . self::USER)->fetchColumn();
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($this->secret, $stored);
        $this->assertSame($this->secret, $this->mfa->unseal($stored));

        $hashes = $this->pdo->query('SELECT code_hash FROM mfa_recovery_codes')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(10, $hashes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^([0-9A-HJKMNP-TV-Z]{4}-){3}[0-9A-HJKMNP-TV-Z]{4}$/', $code);
            $this->assertNotContains($code, $hashes);
        }
    }

    public function testCodeFromTheAuthenticatorIsAccepted(): void
    {
        $this->mfa->enable(self::USER, $this->secret, $this->step() - 5);

        $this->assertSame(MfaService::FACTOR_TOTP, $this->mfa->verifyCode(self::USER, Totp::code($this->secret, $this->step())));
    }

    public function testCodeCannotBeUsedTwice(): void
    {
        $this->mfa->enable(self::USER, $this->secret, $this->step() - 5);
        $code = Totp::code($this->secret, $this->step());

        $this->assertNotNull($this->mfa->verifyCode(self::USER, $code));
        $this->assertNull($this->mfa->verifyCode(self::USER, $code));
    }

    public function testOlderCodeIsRefusedOnceANewerOneWasUsed(): void
    {
        $this->mfa->enable(self::USER, $this->secret, $this->step() - 5);

        $this->assertNotNull($this->mfa->verifyCode(self::USER, Totp::code($this->secret, $this->step())));
        $this->assertNull($this->mfa->verifyCode(self::USER, Totp::code($this->secret, $this->step() - 1)));
    }

    public function testTheCodeThatConfirmedEnrolmentCannotSignIn(): void
    {
        $step = $this->step();
        $this->mfa->enable(self::USER, $this->secret, $step);

        $this->assertNull($this->mfa->verifyCode(self::USER, Totp::code($this->secret, $step)));
    }

    public function testRecoveryCodeWorksOnceInAnyNotation(): void
    {
        $codes = $this->mfa->enable(self::USER, $this->secret, $this->step());

        $this->assertSame(10, $this->mfa->remainingRecoveryCodes(self::USER));
        $this->assertSame(MfaService::FACTOR_RECOVERY, $this->mfa->verifyCode(self::USER, $codes[0]));
        $this->assertNull($this->mfa->verifyCode(self::USER, $codes[0]));
        $this->assertSame(9, $this->mfa->remainingRecoveryCodes(self::USER));

        $sloppy = ' ' . strtolower(str_replace('-', ' ', $codes[1])) . ' ';
        $this->assertSame(MfaService::FACTOR_RECOVERY, $this->mfa->verifyCode(self::USER, $sloppy));
    }

    public function testRecoveryCodeOfAnotherUserIsRefused(): void
    {
        $codes = $this->mfa->enable(self::USER, $this->secret, $this->step());
        $this->mfa->enable(8, Totp::generateSecret(), $this->step());

        $this->assertNull($this->mfa->verifyCode(8, $codes[0]));
        $this->assertSame(10, $this->mfa->remainingRecoveryCodes(self::USER));
    }

    public function testEnrollingAgainInvalidatesTheOldRecoveryCodes(): void
    {
        $old = $this->mfa->enable(self::USER, $this->secret, $this->step());
        $this->mfa->enable(self::USER, Totp::generateSecret(), $this->step());

        $this->assertNull($this->mfa->verifyCode(self::USER, $old[0]));
        $this->assertSame(10, $this->mfa->remainingRecoveryCodes(self::USER));
    }

    public function testDisableRemovesEverything(): void
    {
        $codes = $this->mfa->enable(self::USER, $this->secret, $this->step() - 5);
        $this->mfa->disable(self::USER);

        $this->assertNull($this->mfa->verifyCode(self::USER, Totp::code($this->secret, $this->step())));
        $this->assertNull($this->mfa->verifyCode(self::USER, $codes[0]));
        $this->assertSame(
            [null, null, null],
            array_values((array) $this->pdo->query('SELECT mfa_secret_encrypted, mfa_enabled_at, mfa_last_step FROM users WHERE id = ' . self::USER)->fetch())
        );
    }

    public function testRecoveryCodesStillWorkAfterTheAppKeyChanged(): void
    {
        $codes = $this->mfa->enable(self::USER, $this->secret, $this->step() - 5);
        $other = new MfaService($this->pdo, new Encryption(Encryption::generateAppKey()));

        $previousLog = ini_set('error_log', '/dev/null');
        $totp = $other->verifyCode(self::USER, Totp::code($this->secret, $this->step()));
        ini_set('error_log', (string) $previousLog);

        $this->assertNull($totp);
        $this->assertSame(MfaService::FACTOR_RECOVERY, $other->verifyCode(self::USER, $codes[0]));
    }

    public function testGarbageIsRefused(): void
    {
        $this->mfa->enable(self::USER, $this->secret, $this->step());

        foreach (['', '123', 'not a code', str_repeat('A', 16), "' OR 1=1 --"] as $input) {
            $this->assertNull($this->mfa->verifyCode(self::USER, $input), $input);
        }
    }
}
