<?php
declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\QrCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QrCodeTest extends TestCase
{
    private const URI = 'otpauth://totp/SignaturePortal:user%40example.com?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=SignaturePortal';

    /**
     * Frozen output. This matrix, along with one at the capacity limit of every
     * version 1 to 20, was decoded correctly by macOS Vision and CoreImage when
     * the encoder was written. A different hash means the output changed and has
     * to be scanned with a real decoder again.
     */
    public function testOutputForAProvisioningUriIsUnchanged(): void
    {
        $rows = array_map(
            static fn(array $row): string => implode('', array_map(static fn(bool $dark): string => $dark ? '1' : '0', $row)),
            QrCode::matrix(self::URI)
        );

        $this->assertCount(45, $rows);
        $this->assertSame('74e62f1ccbba1b7eeefea392210987e42f757fabbbd22c66b22ecc6e50729c04', hash('sha256', implode("\n", $rows)));
    }

    /** @return array<string,array{int,int}> */
    public static function capacityLimits(): array
    {
        return [
            'version 1'  => [14, 21],
            'version 2'  => [15, 25],
            'version 10' => [213, 57],
            'version 20' => [666, 97],
        ];
    }

    /** @dataProvider capacityLimits */
    public function testPicksTheSmallestVersionThatFits(int $bytes, int $expectedSize): void
    {
        $this->assertCount($expectedSize, QrCode::matrix(str_repeat('x', $bytes)));
    }

    public function testRefusesTextThatDoesNotFit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QrCode::matrix(str_repeat('x', 667));
    }

    public function testSvgInlinesSafelyAndKeepsAQuietZone(): void
    {
        $svg = QrCode::svg('<script>alert(1)</script>');

        $this->assertStringStartsWith('<svg ', $svg);
        $this->assertStringNotContainsString('script', $svg);
        $this->assertStringNotContainsString('alert', $svg);

        // Version 2 is 25 modules wide, plus a four-module margin on each side.
        $this->assertStringContainsString('viewBox="0 0 33 33"', $svg);
        $this->assertStringContainsString('d="M4,4h', $svg);
    }
}
