-- Two-factor authentication (TOTP) for password sign-in.
--
-- - users.mfa_secret_encrypted: the TOTP secret, encrypted with APP_KEY.
-- - users.mfa_enabled_at: set once the user proved their authenticator works.
--   NULL means not enrolled.
-- - users.mfa_last_step: time step of the last accepted code, so a code that
--   was read over someone's shoulder can't be used a second time.
-- - mfa_recovery_codes: one-time codes for a lost device. Only their SHA-256
--   hashes are stored, so a DB leak does not yield usable codes.

ALTER TABLE users
    ADD COLUMN mfa_secret_encrypted TEXT DEFAULT NULL AFTER password_changed_at,
    ADD COLUMN mfa_enabled_at DATETIME DEFAULT NULL AFTER mfa_secret_encrypted,
    ADD COLUMN mfa_last_step BIGINT UNSIGNED DEFAULT NULL AFTER mfa_enabled_at;

CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    CONSTRAINT fk_mfa_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
