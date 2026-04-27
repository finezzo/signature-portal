-- Per-user signature overrides — exception layer above the rule engine.
--
-- "Anna gets her own signature regardless of the normal rules" use case.
-- Match key is the email address (typically the primary user's UPN). The
-- SignatureService consults this table BEFORE running RuleEngine; if a row
-- exists, that template wins outright. Rules are still consulted for
-- everyone without an override.

CREATE TABLE IF NOT EXISTS user_overrides (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_email (tenant_id, email),
    KEY idx_tenant (tenant_id),
    CONSTRAINT fk_overrides_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants(id)   ON DELETE CASCADE,
    CONSTRAINT fk_overrides_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
