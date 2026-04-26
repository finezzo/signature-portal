-- Phase 2 schema additions.
--
-- - tenants.email_domains:  JSON array of strings ("acme.com", "acme.de") — used
--   by the rule engine to classify recipients as internal/external.
-- - tenants.api_key_encrypted: replaces api_key_hash (we need the raw key back to
--   compare against incoming /api/sig requests in constant time, so we store it
--   encrypted with APP_KEY rather than hashed).
-- - tenants.manifest_guid: stable GUID embedded in the generated Outlook
--   add-in manifest. Generated once at tenant creation; never changes.
-- - rules.recipient_scope: 'all' | 'external' | 'internal'. Controls whether a
--   rule matches based on whether recipients are inside the tenant's domain set.

ALTER TABLE tenants
    ADD COLUMN email_domains JSON DEFAULT NULL AFTER name,
    ADD COLUMN api_key_encrypted TEXT DEFAULT NULL AFTER entra_client_secret_encrypted,
    ADD COLUMN manifest_guid CHAR(36) DEFAULT NULL AFTER api_key_last_rotated_at,
    DROP COLUMN api_key_hash;

ALTER TABLE rules
    ADD COLUMN recipient_scope ENUM('all','external','internal') NOT NULL DEFAULT 'all' AFTER mailbox_type;
