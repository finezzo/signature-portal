-- Add 'primary' as a third option for the shared-mailbox display mode.
--
-- Before: shared (use the shared address) / derived (fabricate a personal-looking one).
-- After:  + primary (use the actual primary-user email from Graph).
--
-- The new option is for the common case where the team mailbox sends but
-- recipients should reply to the real human directly. Unlike 'derived',
-- the address is real (it exists in Entra) — no fabricated aliases.
--
-- MySQL extends ENUM in place without rewriting existing rows.

ALTER TABLE tenants
    MODIFY COLUMN shared_display_mode ENUM('shared','primary','derived')
        NOT NULL DEFAULT 'shared';
