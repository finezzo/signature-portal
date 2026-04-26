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
    public static function canAccessTenant(array $user, int $tenantId): bool
    {
        if (self::isSuperadmin($user)) {
            return true;
        }
        return ((int) ($user['tenant_id'] ?? 0)) === $tenantId;
    }
}
