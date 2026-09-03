-- Local user-profile store — write-through cache in front of Microsoft Graph.
--
-- Microsoft's login gateway can block the hosting IP outright (and shared
-- hosting IPs do get blocked), which turns every Graph token request into a
-- GraphException and every GET /api/sig into a 502 — no signatures at all.
--
-- Every successful Graph profile fetch is therefore written through to this
-- table, and the resolver falls back to it when Graph is unreachable. Rows
-- never expire: a stale signature beats no signature during an outage.
-- Profiles can also be seeded without Graph (CSV import or manual entry) via
-- `source`; a later successful Graph fetch always wins and flips the row to
-- 'graph'.
--
-- `profile_json` holds the Graph-shaped raw response verbatim so
-- UserProfile::fromGraphResponse() can rebuild an identical object from cache
-- (including `assignedLicenses`, which drives shared-mailbox detection). It is
-- stored in plaintext by decision: it is directory data the add-in already
-- publishes in every signature, not a secret like the Entra client secret.
--
-- No `ON UPDATE CURRENT_TIMESTAMP` on updated_at — the repository sets it
-- explicitly in every UPDATE so the SQLite test schema behaves identically.

CREATE TABLE IF NOT EXISTS user_profiles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    source ENUM('graph','csv','manual') NOT NULL,
    profile_json MEDIUMTEXT NOT NULL,
    graph_synced_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_email (tenant_id, email),
    KEY idx_tenant (tenant_id),
    CONSTRAINT fk_profiles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
