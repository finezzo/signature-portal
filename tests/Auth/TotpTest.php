<?php
declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** Base32 of the RFC 6238 test key "12345678901234567890". */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * RFC 6238 Appendix B lists 8-digit SHA-1 values. A 6-digit code is the same
     * number modulo 10^6, so these are the last six digits of each.
     *
     * @return array<string,array{int,string}>
     */
    public static function rfcVectors(): array
    {
        return [
            'T=59'          => [59, '287082'],
            'T=1111111109'  => [1111111109, '081804'],
            'T=1111111111'  => [1111111111, '050471'],
            'T=1234567890'  => [1234567890, '005924'],
            'T=2000000000'  => [2000000000, '279037'],
            'T=20000000000' => [20000000000, '353130'],
        ];
    }

    /** @dataProvider rfcVectors */
    public function testMatchesTheRfcVectors(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::code(self::RFC_SECRET, intdiv($time, 30)));
    }

    public function testAcceptsOneStepOfClockDriftAndNoMore(): void
    {
        $now  = 1700000000;
        $step = intdiv($now, 30);

        foreach ([-1, 0, 1] as $drift) {
            $this->assertSame($step + $drift, Totp::match(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $drift), $now));
        }
        foreach ([-2, 2] as $drift) {
            $this->assertNull(Totp::match(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $drift), $now));
        }
    }

    public function testRejectsMalformedCodes(): void
    {
        foreach (['', '12345', '1234567', 'abcdef'] as $code) {
            $this->assertNull(Totp::match(self::RFC_SECRET, $code, 1700000000), $code);
        }
    }

    public function testGeneratedSecretsAreBase32AndCarry160Bits(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, Totp::generateSecret());
    }

    public function testProvisioningUriEncodesTheAccount(): void
    {
        $this->assertSame(
            'otpauth://totp/Sig%20Portal:a%2Bb%40example.com?secret=ABC&issuer=Sig%20Portal',
            Totp::provisioningUri('ABC', 'a+b@example.com', 'Sig Portal')
        );
    }
}
