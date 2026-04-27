-- Append-only activity log: who did what when, scoped per tenant.
--
-- payload_json captures action-specific detail (changed fields, prior values
-- summarised, etc.) without forcing a fixed schema per action type. Kept
-- small — never store secrets or full template HTML; reference IDs and
-- summaries instead.
--
-- ON DELETE SET NULL on user_id and tenant_id so deleting a user/tenant
-- doesn't lose the audit trail of what they did.

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED DEFAULT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    user_email VARCHAR(255) DEFAULT NULL,
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(48) DEFAULT NULL,
    target_id VARCHAR(64) DEFAULT NULL,
    summary VARCHAR(255) DEFAULT NULL,
    payload_json JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant_time (tenant_id, created_at),
    KEY idx_user_time (user_id, created_at),
    KEY idx_action (action),
    CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
