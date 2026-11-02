<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * Time-based one-time passwords (RFC 6238) with the settings every authenticator
 * app understands: HMAC-SHA1, 6 digits, 30-second steps. The secret is passed
 * around base32-encoded, the form the apps display.
 */
final class Totp
{
    private const STEP_SECONDS = 30;
    private const DIGITS       = 6;
    /** Steps accepted either side of "now", to tolerate a phone clock that is slightly off. */
    private const DRIFT_STEPS  = 1;
    private const SECRET_BYTES = 20;
    private const BASE32       = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    public static function code(string $secret, int $step): string
    {
        $hash   = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value  = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** The time step this code belongs to, or null if it is not valid around $now. */
    public static function match(string $secret, string $code, int $now): ?int
    {
        $current = intdiv($now, self::STEP_SECONDS);
        $matched = null;
        for ($step = $current - self::DRIFT_STEPS; $step <= $current + self::DRIFT_STEPS; $step++) {
            if (hash_equals(self::code($secret, $step), $code)) {
                $matched = $step;
            }
        }
        return $matched;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function base32Decode(string $encoded): string
    {
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }
        return $out;
    }
}
