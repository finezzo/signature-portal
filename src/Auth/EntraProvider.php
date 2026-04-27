<?php
declare(strict_types=1);

namespace App\Auth;

use App\Crypto\Encryption;
use App\Tenant\Tenant;
use RuntimeException;
use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Builds a fully configured Azure OIDC provider for a tenant.
 *
 * Each tenant supplies its own Entra app registration (tenant_id, client_id,
 * encrypted client_secret). The portal reuses the same credentials for both
 * Microsoft Graph (signature delivery) and SSO login — admins only configure
 * one Azure app per tenant.
 *
 * Endpoint version is pinned to v2.0 so we receive an OIDC id_token with
 * standard claims (oid, preferred_username, email, given_name, family_name).
 */
final class EntraProvider
{
    public function __construct(
        private readonly Encryption $encryption,
        private readonly string $baseUrl,
    ) {}

    public function isConfigured(Tenant $tenant): bool
    {
        return $tenant->ssoEnabled
            && $tenant->entraTenantId !== null
            && $tenant->entraClientId !== null
            && $tenant->entraClientSecretEncrypted !== null;
    }

    public function redirectUri(Tenant $tenant): string
    {
        return rtrim($this->baseUrl, '/') . '/portal/sso/' . $tenant->slug . '/callback';
    }

    public function build(Tenant $tenant): Azure
    {
        if (!$this->isConfigured($tenant)) {
            throw new RuntimeException('Entra SSO is not configured for this tenant.');
        }

        try {
            $secret = $this->encryption->decrypt((string) $tenant->entraClientSecretEncrypted);
        } catch (RuntimeException) {
            throw new RuntimeException('Stored Entra client secret could not be decrypted.');
        }

        $provider = new Azure([
            'clientId'              => $tenant->entraClientId,
            'clientSecret'          => $secret,
            'redirectUri'           => $this->redirectUri($tenant),
            'tenant'                => $tenant->entraTenantId,
            'defaultEndPointVersion'=> Azure::ENDPOINT_VERSION_2_0,
            'scopes'                => ['openid', 'profile', 'email'],
        ]);
        // The Azure provider uses Graph as default resource for v1.0 tokens;
        // for v2.0 + scopes above we just need OIDC, no resource override.
        return $provider;
    }
}
