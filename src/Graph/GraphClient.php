<?php
declare(strict_types=1);

namespace App\Graph;

use App\Crypto\Encryption;
use App\Tenant\Tenant;
use GuzzleHttp\Client as Http;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Thin Microsoft Graph client.
 *
 * Authenticates each tenant via the client_credentials flow with the
 * tenant's own Entra app registration; the tenant's encrypted client
 * secret is decrypted on demand and never persisted in plaintext beyond
 * this call. Access tokens are cached in `graph_token_cache`.
 *
 * Surfaced operations are intentionally narrow — only `getUserProfile`
 * is needed for signature delivery in Phase 3. The class throws
 * GraphException for hard errors and returns null for "user not found"
 * so callers can distinguish "shared mailbox" from "broken tenant".
 */
final class GraphClient
{
    private const TOKEN_ENDPOINT = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const GRAPH_BASE     = 'https://graph.microsoft.com/v1.0';
    private const GRAPH_SCOPE    = 'https://graph.microsoft.com/.default';
    private const USER_SELECT    = 'id,userPrincipalName,mail,displayName,givenName,surname,jobTitle,mobilePhone,businessPhones,preferredLanguage';

    public function __construct(
        private readonly Http $http,
        private readonly TokenCache $cache,
        private readonly Encryption $encryption,
    ) {}

    /** @return UserProfile|null  null if the user does not exist (HTTP 404). */
    public function getUserProfile(Tenant $tenant, string $userPrincipalName): ?UserProfile
    {
        $token = $this->getAccessToken($tenant);

        $url = self::GRAPH_BASE . '/users/' . rawurlencode($userPrincipalName)
             . '?$select=' . rawurlencode(self::USER_SELECT);

        try {
            $resp = $this->http->get($url, [
                'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
                'http_errors' => true,
                'timeout' => 5.0,
            ]);
        } catch (ClientException $e) {
            $status = $e->getResponse()?->getStatusCode();
            if ($status === 404) {
                return null;
            }
            // 401 with valid cached token usually means the app's permission was revoked.
            // Purge and let the next call try fresh.
            if ($status === 401) {
                $this->cache->purge($tenant->id);
            }
            throw new GraphException("Graph user lookup failed (HTTP {$status}) for '{$userPrincipalName}'.", 0, $e);
        } catch (GuzzleException $e) {
            throw new GraphException("Graph network error: " . $e->getMessage(), 0, $e);
        }

        $body = json_decode((string) $resp->getBody(), true);
        if (!is_array($body)) {
            throw new GraphException('Graph user response was not JSON.');
        }
        return UserProfile::fromGraphResponse($body);
    }

    private function getAccessToken(Tenant $tenant): string
    {
        if ($tenant->entraTenantId === null || $tenant->entraClientId === null
            || $tenant->entraClientSecretEncrypted === null) {
            throw new GraphException("Tenant '{$tenant->slug}' has no Entra credentials configured.");
        }

        $cached = $this->cache->get($tenant->id);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $secret = $this->encryption->decrypt($tenant->entraClientSecretEncrypted);
        } catch (RuntimeException $e) {
            throw new GraphException("Could not decrypt Entra client secret for tenant '{$tenant->slug}'. APP_KEY rotated?", 0, $e);
        }

        $url = sprintf(self::TOKEN_ENDPOINT, rawurlencode($tenant->entraTenantId));
        try {
            $resp = $this->http->post($url, [
                'form_params' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $tenant->entraClientId,
                    'client_secret' => $secret,
                    'scope'         => self::GRAPH_SCOPE,
                ],
                'headers'     => ['Accept' => 'application/json'],
                'timeout'     => 5.0,
                'http_errors' => true,
            ]);
        } catch (GuzzleException $e) {
            throw new GraphException("Graph token endpoint error: " . $e->getMessage(), 0, $e);
        }

        $body = json_decode((string) $resp->getBody(), true);
        if (!is_array($body) || empty($body['access_token']) || empty($body['expires_in'])) {
            throw new GraphException('Graph token response was malformed.');
        }

        $token   = (string) $body['access_token'];
        $ttl     = (int) $body['expires_in'];
        $this->cache->store($tenant->id, $token, $ttl);
        return $token;
    }
}
