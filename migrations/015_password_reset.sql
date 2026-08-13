-- Self-service password reset + session invalidation on password change.
--
-- - password_resets: one row per requested reset link. The token itself is
--   never stored — only its SHA-256 hash — so a DB leak does not yield
--   usable reset links. Rows are single-use (used_at) and expire quickly.
-- - users.password_changed_at: bumped on every password change/reset.
--   AuthMiddleware compares it against the value captured at login, so all
--   other sessions of the user die immediately after a change.

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_token (token_hash),
    KEY idx_user (user_id),
    CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN password_changed_at DATETIME DEFAULT NULL AFTER password_hash;
