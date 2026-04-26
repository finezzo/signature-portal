<?php
declare(strict_types=1);

namespace App\Crypto;

use RuntimeException;

/**
 * Symmetric encryption for secrets at rest, backed by libsodium's
 * crypto_secretbox (XSalsa20-Poly1305). The 32-byte key is taken from
 * config 'app_key' in the format "base64:<32-bytes-b64>".
 */
final class Encryption
{
    private string $key;

    public function __construct(string $appKey)
    {
        $this->key = self::decodeAppKey($appKey);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Encrypted payload is malformed.');
        }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong key or tampered payload).');
        }
        return $plain;
    }

    public static function generateAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    private static function decodeAppKey(string $appKey): string
    {
        if (!str_starts_with($appKey, 'base64:')) {
            throw new RuntimeException('app_key must be in "base64:..." format.');
        }
        $raw = base64_decode(substr($appKey, 7), true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('app_key must decode to exactly 32 bytes.');
        }
        return $raw;
    }
}
