<?php
declare(strict_types=1);

namespace App\Auth;

use InvalidArgumentException;

/**
 * QR codes for the two-factor enrolment page (ISO/IEC 18004: byte mode, error
 * correction level M, versions 1 to 20, which holds up to 666 bytes).
 *
 * Kept in-house because the project avoids new runtime dependencies and the only
 * candidates either pull in non-default PHP extensions or far more than this
 * needs. The output is SVG built from numbers alone, so it is safe to inline.
 */
final class QrCode
{
    private const MAX_VERSION = 20;
    private const QUIET_ZONE  = 4;

    /** Error correction level M, indexed by version - 1. */
    private const ECC_CODEWORDS_PER_BLOCK = [10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26];
    private const BLOCK_COUNT             = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16];

    private readonly int $size;
    /** @var list<list<bool>> Rows of modules, true means dark. */
    private array $modules;
    /** @var list<list<bool>> Modules taken by function patterns, off limits to data and masks. */
    private array $reserved;

    private function __construct(private readonly int $version)
    {
        $this->size     = $version * 4 + 17;
        $blank          = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->modules  = $blank;
        $this->reserved = $blank;
    }

    public static function svg(string $text): string
    {
        $rows = self::matrix($text);
        $size = count($rows) + 2 * self::QUIET_ZONE;

        $path = '';
        foreach ($rows as $y => $row) {
            $x = 0;
            while ($x < count($row)) {
                if (!$row[$x]) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < count($row) && $row[$x]) {
                    $x++;
                }
                $path .= 'M' . ($start + self::QUIET_ZONE) . ',' . ($y + self::QUIET_ZONE) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges" role="img" aria-label="QR code">'
            . '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    /** @return list<list<bool>> Rows of modules, true means dark. */
    public static function matrix(string $text): array
    {
        $version = self::smallestVersionFor(strlen($text));
        $qr      = new self($version);

        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->addErrorCorrection($qr->dataCodewords($text)));
        $qr->applyBestMask();

        return $qr->modules;
    }

    private static function smallestVersionFor(int $bytes): int
    {
        for ($version = 1; $version <= self::MAX_VERSION; $version++) {
            $bits = 4 + self::countBits($version) + 8 * $bytes;
            if ($bits <= 8 * self::dataCapacity($version)) {
                return $version;
            }
        }
        throw new InvalidArgumentException('Text is too long for a QR code.');
    }

    private static function countBits(int $version): int
    {
        return $version <= 9 ? 8 : 16;
    }

    private static function rawCodewords(int $version): int
    {
        $modules = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $alignment = intdiv($version, 7) + 2;
            $modules  -= (25 * $alignment - 10) * $alignment - 55;
            if ($version >= 7) {
                $modules -= 36;
            }
        }
        return intdiv($modules, 8);
    }

    private static function dataCapacity(int $version): int
    {
        return self::rawCodewords($version)
            - self::ECC_CODEWORDS_PER_BLOCK[$version - 1] * self::BLOCK_COUNT[$version - 1];
    }

    // ---------- data ----------

    /** @return list<int> */
    private function dataCodewords(string $text): array
    {
        $capacity = self::dataCapacity($this->version);

        $bits = '0100' . str_pad(decbin(strlen($text)), self::countBits($this->version), '0', STR_PAD_LEFT);
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, 8 * $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        $codewords = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($codewords) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }
        return $codewords;
    }

    /**
     * @param list<int> $data
     * @return list<int> Data and error correction codewords, interleaved block by block.
     */
    private function addErrorCorrection(array $data): array
    {
        $eccLength = self::ECC_CODEWORDS_PER_BLOCK[$this->version - 1];
        $blocks    = self::BLOCK_COUNT[$this->version - 1];
        $raw       = self::rawCodewords($this->version);
        $short     = $blocks - $raw % $blocks;
        $shortData = intdiv($raw, $blocks) - $eccLength;
        $divisor   = self::reedSolomonDivisor($eccLength);

        $dataBlocks = [];
        $eccBlocks  = [];
        $offset     = 0;
        for ($i = 0; $i < $blocks; $i++) {
            $length       = $shortData + ($i < $short ? 0 : 1);
            $block        = array_slice($data, $offset, $length);
            $dataBlocks[] = $block;
            $eccBlocks[]  = self::reedSolomonRemainder($block, $divisor);
            $offset      += $length;
        }

        $out = [];
        for ($i = 0; $i <= $shortData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $eccLength; $i++) {
            foreach ($eccBlocks as $block) {
                $out[] = $block[$i];
            }
        }
        return $out;
    }

    /** @return list<int> */
    private static function reedSolomonDivisor(int $degree): array
    {
        $divisor = array_fill(0, $degree, 0);
        $divisor[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $divisor[$j] = self::gfMultiply($divisor[$j], $root);
                if ($j + 1 < $degree) {
                    $divisor[$j] ^= $divisor[$j + 1];
                }
            }
            $root = self::gfMultiply($root, 2);
        }
        return $divisor;
    }

    /**
     * @param list<int> $data
     * @param list<int> $divisor
     * @return list<int>
     */
    private static function reedSolomonRemainder(array $data, array $divisor): array
    {
        $remainder = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($remainder);
            $remainder[] = 0;
            foreach ($divisor as $i => $coefficient) {
                $remainder[$i] ^= self::gfMultiply($coefficient, $factor);
            }
        }
        return $remainder;
    }

    /** Multiplication in GF(2^8) modulo x^8 + x^4 + x^3 + x^2 + 1. */
    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    // ---------- drawing ----------

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        $far = $this->size - 4;
        $this->drawFinder(3, 3);
        $this->drawFinder($far, 3);
        $this->drawFinder(3, $far);

        $positions = $this->alignmentPositions();
        $last      = count($positions) - 1;
        foreach ($positions as $i => $x) {
            foreach ($positions as $j => $y) {
                $onFinder = ($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0);
                if (!$onFinder) {
                    $this->drawAlignment($x, $y);
                }
            }
        }

        $this->drawFormatBits(0);
        $this->drawVersionBits();
    }

    private function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                    $distance = max(abs($dx), abs($dy));
                    $this->setFunction($x, $y, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $count = intdiv($this->version, 7) + 2;
        $step  = intdiv($this->version * 4 + $count * 2 + 1, $count * 2 - 2) * 2;

        $positions = [6];
        for ($i = $count - 2, $position = $this->size - 7; $i >= 0; $i--, $position -= $step) {
            $positions[$i + 1] = $position;
        }
        ksort($positions);
        return array_values($positions);
    }

    private function drawFormatBits(int $mask): void
    {
        // Level M is 00 in the format field, so only the mask shows up in the data.
        $remainder = $mask;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($mask << 10) | $remainder) ^ 0x5412;
        $bit  = static fn(int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $this->size - 8, true);
    }

    private function drawVersionBits(): void
    {
        if ($this->version < 7) {
            return;
        }
        $remainder = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $remainder;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a    = $this->size - 11 + $i % 3;
            $b    = intdiv($i, 3);
            $this->setFunction($a, $b, $dark);
            $this->setFunction($b, $a, $dark);
        }
    }

    /** @param list<int> $codewords */
    private function drawCodewords(array $codewords): void
    {
        $total = count($codewords) * 8;
        $i     = 0;
        // Two-module-wide columns from right to left, in a zigzag. The vertical
        // timing line in column 6 is skipped entirely.
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            $upward = (($right + 1) & 2) === 0;
            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                $y = $upward ? $this->size - 1 - $vertical : $vertical;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if (!$this->reserved[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x]  = $dark;
        $this->reserved[$y][$x] = true;
    }

    // ---------- masking ----------

    private function applyBestMask(): void
    {
        $best        = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();
            if ($penalty < $bestPenalty) {
                $best        = $mask;
                $bestPenalty = $penalty;
            }
            $this->applyMask($mask); // XOR again undoes it
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->reserved[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** The four penalty rules from the standard. A lower score scans more reliably. */
    private function penalty(): int
    {
        $lines = [];
        $dark  = 0;
        for ($y = 0; $y < $this->size; $y++) {
            $row = '';
            $col = '';
            for ($x = 0; $x < $this->size; $x++) {
                $row .= $this->modules[$y][$x] ? '1' : '0';
                $col .= $this->modules[$x][$y] ? '1' : '0';
            }
            $dark   += substr_count($row, '1');
            $lines[] = $row;
            $lines[] = $col;
        }

        $penalty = 0;
        foreach ($lines as $line) {
            preg_match_all('/0{5,}|1{5,}/', $line, $runs);
            foreach ($runs[0] as $run) {
                $penalty += strlen($run) - 2;
            }
            $penalty += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
        }

        for ($y = 0; $y < $this->size - 1; $y++) {
            for ($x = 0; $x < $this->size - 1; $x++) {
                $color = $this->modules[$y][$x];
                if ($color === $this->modules[$y][$x + 1]
                    && $color === $this->modules[$y + 1][$x]
                    && $color === $this->modules[$y + 1][$x + 1]) {
                    $penalty += 3;
                }
            }
        }

        $total = $this->size ** 2;
        return $penalty + 10 * max(0, (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1);
    }
}
