<?php
declare(strict_types=1);

namespace App\Tenant;

use App\Crypto\Encryption;
use RuntimeException;

/**
 * Tenant operations that involve crypto: API key generation/rotation,
 * Entra client-secret encryption, and api-key verification.
 */
final class TenantService
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly Encryption $encryption,
    ) {}

    /** Generates a fresh URL-safe API key, stores it encrypted, returns the plaintext. */
    public function rotateApiKey(int $tenantId): string
    {
        $plain = $this->generateApiKey();
        $this->tenants->setApiKey($tenantId, $this->encryption->encrypt($plain));
        return $plain;
    }

    /** Constant-time check of a candidate API key against the tenant's stored key. */
    public function verifyApiKey(Tenant $tenant, string $candidate): bool
    {
        if ($tenant->apiKeyEncrypted === null || $candidate === '') {
            return false;
        }
        try {
            $expected = $this->encryption->decrypt($tenant->apiKeyEncrypted);
        } catch (RuntimeException) {
            return false;
        }
        return hash_equals($expected, $candidate);
    }

    public function getApiKeyPlaintext(Tenant $tenant): ?string
    {
        if ($tenant->apiKeyEncrypted === null) {
            return null;
        }
        try {
            return $this->encryption->decrypt($tenant->apiKeyEncrypted);
        } catch (RuntimeException) {
            return null;
        }
    }

    public function encryptEntraSecret(string $plain): string
    {
        return $this->encryption->encrypt($plain);
    }

    /** 32 bytes, base64url, no padding — copy-paste safe in URLs. */
    public function generateApiKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function generateGuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
