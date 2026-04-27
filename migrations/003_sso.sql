-- Phase 4 — Entra SSO for the portal.
--
-- - tenants.sso_enabled:           toggle the per-tenant SSO login link.
-- - tenants.sso_auto_provision:    if 1, unknown Entra users are created on first login.
-- - tenants.sso_default_role:      role assigned to auto-provisioned users.
-- - users.entra_object_id:         Entra "oid" claim. Stable across email changes; preferred match key.
-- - users.auth_provider:           'local' | 'entra'. Local users have a password_hash; Entra users may not.
--
-- The Entra app registration (client_id / tenant_id / client_secret) lives on tenants
-- already (added in 001/002). Reusing those credentials for both Graph and SSO so admins
-- only configure one app per tenant.

ALTER TABLE tenants
    ADD COLUMN sso_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER manifest_guid,
    ADD COLUMN sso_auto_provision TINYINT(1) NOT NULL DEFAULT 0 AFTER sso_enabled,
    ADD COLUMN sso_default_role ENUM('tenant_admin','tenant_editor') NOT NULL DEFAULT 'tenant_editor' AFTER sso_auto_provision;

ALTER TABLE users
    ADD COLUMN entra_object_id CHAR(36) DEFAULT NULL AFTER email,
    ADD COLUMN auth_provider ENUM('local','entra') NOT NULL DEFAULT 'local' AFTER entra_object_id,
    MODIFY COLUMN password_hash VARCHAR(255) DEFAULT NULL,
    ADD UNIQUE KEY uniq_entra_oid (entra_object_id);
