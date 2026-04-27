-- Tenant-level preference for the {email} token when the FROM is a shared
-- mailbox.
--
--   'shared'  — use the shared mailbox address as-is (e.g. sales@acme.com).
--               Standard Microsoft pattern; signature shows the team mailbox
--               so replies come back to the team.
--   'derived' — compute a personal-looking address from the original sender's
--               name and the from-domain (e.g. s.mueller@acme.com).
--               Useful when the team mailbox should appear personal.
--
-- Defaults to 'shared' for new and existing tenants; admins can switch per
-- tenant in the portal.

ALTER TABLE tenants
    ADD COLUMN shared_display_mode ENUM('shared','derived') NOT NULL DEFAULT 'shared'
        AFTER sso_default_role;
