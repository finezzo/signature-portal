<?php
declare(strict_types=1);

namespace App\Auth;

use App\Tenant\Tenant;
use PDO;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;

/**
 * Resolves an authenticated Azure OIDC identity into a portal session.
 *
 * Match precedence inside the tenant:
 *   1. existing user by entra_object_id (oid is stable across rename/email change)
 *   2. existing local user by email (link the oid to that user the first time)
 *   3. auto-provision a new user if the tenant allows it
 *
 * Superadmins (tenant_id NULL) match by oid or email globally — useful when a
 * cross-tenant admin wants to use SSO from any tenant they own.
 */
final class SsoAuthenticator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SessionManager $session,
    ) {}

    public function loginFromAzureOwner(Tenant $tenant, AzureResourceOwner $owner): SsoLoginResult
    {
        $oid   = $owner->getId(); // 'oid' claim
        $email = $this->preferredEmail($owner);
        $name  = $this->displayName($owner);

        if ($oid === null || $oid === '' || $email === null || $email === '') {
            return SsoLoginResult::failure('Token did not include an Entra object id or email address.');
        }
        $email = mb_strtolower($email);

        $user = $this->findUserByOid($oid, $tenant->id)
            ?? $this->findUserByEmail($email, $tenant->id);

        if ($user === null) {
            if (!$tenant->ssoAutoProvision) {
                return SsoLoginResult::failure(
                    'Your account is not provisioned in this tenant. Ask an administrator to add you first.'
                );
            }
            $user = $this->createUser($tenant, $email, $oid, $name);
        } else {
            if (empty($user['entra_object_id'])) {
                $this->linkOidToUser((int) $user['id'], $oid);
                $user['entra_object_id'] = $oid;
                $user['auth_provider']   = 'entra';
            }
        }

        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute([':id' => (int) $user['id']]);

        $this->session->regenerate();
        $this->session->set('user', [
            'id'        => (int) $user['id'],
            'email'     => (string) $user['email'],
            'name'      => $user['name'] !== null ? (string) $user['name'] : ($name ?: null),
            'role'      => (string) $user['role'],
            'tenant_id' => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
        ]);

        return SsoLoginResult::success();
    }

    /** @return array<string,mixed>|null */
    private function findUserByOid(string $oid, int $tenantId): ?array
    {
        // Match either inside the tenant or globally for superadmins.
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users
             WHERE entra_object_id = :oid
               AND (tenant_id = :tid OR tenant_id IS NULL)
             ORDER BY tenant_id IS NULL ASC
             LIMIT 1'
        );
        $stmt->execute([':oid' => $oid, ':tid' => $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function findUserByEmail(string $email, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users
             WHERE email = :email
               AND (tenant_id = :tid OR tenant_id IS NULL)
             ORDER BY tenant_id IS NULL ASC
             LIMIT 1'
        );
        $stmt->execute([':email' => $email, ':tid' => $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function linkOidToUser(int $userId, string $oid): void
    {
        $this->pdo->prepare(
            'UPDATE users SET entra_object_id = :oid, auth_provider = ' .
            "IF(password_hash IS NULL, 'entra', auth_provider) WHERE id = :id"
        )->execute([':oid' => $oid, ':id' => $userId]);
    }

    /** @return array<string,mixed> */
    private function createUser(Tenant $tenant, string $email, string $oid, ?string $name): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, email, entra_object_id, auth_provider, role, name)
             VALUES (:tid, :email, :oid, "entra", :role, :name)'
        );
        $stmt->execute([
            ':tid'   => $tenant->id,
            ':email' => $email,
            ':oid'   => $oid,
            ':role'  => $tenant->ssoDefaultRole,
            ':name'  => $name,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $row = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $row->execute([':id' => $id]);
        return (array) $row->fetch();
    }

    private function preferredEmail(AzureResourceOwner $owner): ?string
    {
        // OIDC v2.0: 'email' is optional and often missing. preferred_username
        // is reliable for verified work/school accounts; UPN is the legacy fallback.
        return $owner->getEmail()
            ?? $owner->getPreferredUsername()
            ?? $owner->getUpn();
    }

    private function displayName(AzureResourceOwner $owner): ?string
    {
        $given  = trim((string) ($owner->getFirstName() ?? ''));
        $family = trim((string) ($owner->getLastName()  ?? ''));
        $combined = trim($given . ' ' . $family);
        if ($combined !== '') {
            return $combined;
        }
        $name = $owner->claim('name');
        return is_string($name) && $name !== '' ? $name : null;
    }
}
