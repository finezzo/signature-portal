<?php
declare(strict_types=1);

namespace App\Portal;

/**
 * Tiny RBAC helper, used by controllers to gate tenant access.
 *
 * Roles:
 *  - superadmin: cross-tenant; can create/delete tenants
 *  - tenant_admin / tenant_editor: scoped to users.tenant_id
 */
final class AccessControl
{
    /** @param array<string,mixed> $user */
    public static function isSuperadmin(array $user): bool
    {
        return ($user['role'] ?? null) === 'superadmin';
    }

    /** @param array<string,mixed> $user */
    public static function isTenantAdmin(array $user): bool
    {
        return ($user['role'] ?? null) === 'tenant_admin';
    }

    /**
     * Read/content access to a tenant: superadmins always, otherwise any user
     * (admin or editor) scoped to that tenant. Use for viewing and for
     * editing tenant *content* (templates, rules, overrides, assets).
     *
     * @param array<string,mixed> $user
     */
    public static function canAccessTenant(array $user, int $tenantId): bool
    {
        if (self::isSuperadmin($user)) {
            return true;
        }
        return ((int) ($user['tenant_id'] ?? 0)) === $tenantId;
    }

    /**
     * Administrative access to a tenant: superadmins always, otherwise only
     * tenant_admin scoped to that tenant. Gate anything that touches secrets
     * or security posture behind this — API key rotation/disclosure (manifest
     * download), Entra client secret, SSO settings, email domains.
     * tenant_editor is deliberately limited to content and must NOT pass here.
     *
     * @param array<string,mixed> $user
     */
    public static function canAdministerTenant(array $user, int $tenantId): bool
    {
        if (self::isSuperadmin($user)) {
            return true;
        }
        return self::isTenantAdmin($user)
            && ((int) ($user['tenant_id'] ?? 0)) === $tenantId;
    }
}
