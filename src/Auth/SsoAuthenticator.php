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
 * Hard tenant boundary: matching is scoped to the tenant whose Entra app
 * issued the token, and only after the token's `tid` claim is verified against
 * that tenant's configured directory id. We deliberately do NOT match across
 * into tenant-NULL (superadmin) rows: the `email`/`preferred_username` claims
 * are attacker-controllable by anyone who administers a customer's Entra
 * directory (the `email` optional claim is not a verified address), so a
 * cross-tenant durchgriff would let a malicious customer admin assert a
 * superadmin's email and take over that account. Superadmins sign in with
 * local credentials instead.
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

        // The token must have been issued by THIS tenant's Entra directory.
        // Pinning the authority to the tenant GUID (see EntraProvider) already
        // restricts this, but verifying the `tid` claim is cheap defence in
        // depth against a misconfigured (multi-tenant) app registration.
        $tid = $owner->getTenantId(); // 'tid' claim
        // GUIDs are case-insensitive; Azure emits lowercase but admins may
        // store any casing. tid is not a secret, so a normalized compare is
        // fine (hash_equals keeps it tidy/constant-time regardless).
        if ($tenant->entraTenantId === null
            || !is_string($tid)
            || !hash_equals(mb_strtolower($tenant->entraTenantId), mb_strtolower($tid))) {
            return SsoLoginResult::failure('Token was not issued by this tenant\'s Entra directory.');
        }

        $user = $this->findUserByOid($oid, $tenant->id)
            ?? $this->findUserByEmail($email, $tenant->id);

        if ($user === null) {
            if (!$tenant->ssoAutoProvision) {
                return SsoLoginResult::failure(
                    'Your account is not provisioned in this tenant. Ask an administrator to add you first.'
                );
            }
            // users.email and users.entra_object_id are globally unique. If this
            // identity already belongs to a row we did NOT match above — a
            // superadmin (tenant_id NULL, who signs in locally) or a user in a
            // different tenant — auto-provisioning would hit that unique
            // constraint and throw. Detect it and return a clear message
            // instead of letting the INSERT blow up into a 500.
            if ($this->identityUsedElsewhere($email, $oid, $tenant->id)) {
                return SsoLoginResult::failure(
                    'This Microsoft account (or its email address) already belongs to another '
                    . 'SignaturePortal user and cannot be created automatically in this tenant. '
                    . 'If this is your superadmin account, sign in with your local password instead; '
                    . 'otherwise ask an administrator to add you to this tenant.'
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
            // Captured so AuthMiddleware can kill this session if the password
            // changes elsewhere (self-service reset, admin reset).
            'pw_epoch'  => (string) ($user['password_changed_at'] ?? ''),
        ]);

        return SsoLoginResult::success();
    }

    /** @return array<string,mixed>|null */
    private function findUserByOid(string $oid, int $tenantId): ?array
    {
        // Scoped strictly to this tenant — never matches tenant-NULL
        // (superadmin) rows. See class docstring for the rationale.
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users
             WHERE entra_object_id = :oid AND tenant_id = :tid
             LIMIT 1'
        );
        $stmt->execute([':oid' => $oid, ':tid' => $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function findUserByEmail(string $email, int $tenantId): ?array
    {
        // Scoped strictly to this tenant — never matches tenant-NULL
        // (superadmin) rows. See class docstring for the rationale.
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users
             WHERE email = :email AND tenant_id = :tid
             LIMIT 1'
        );
        $stmt->execute([':email' => $email, ':tid' => $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * True if this email or Entra object id already belongs to a user that the
     * tenant-scoped lookups above would NOT have matched — i.e. a superadmin
     * (tenant_id NULL) or a user in another tenant. Both columns are globally
     * unique, so provisioning over such a row would violate the constraint.
     */
    private function identityUsedElsewhere(string $email, string $oid, int $tenantId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM users
             WHERE (email = :email OR entra_object_id = :oid)
               AND (tenant_id IS NULL OR tenant_id <> :tid)
             LIMIT 1'
        );
        $stmt->execute([':email' => $email, ':oid' => $oid, ':tid' => $tenantId]);
        return $stmt->fetch() !== false;
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
