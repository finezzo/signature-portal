-- Per-rule enable/disable toggle.
--
-- Lets admins temporarily switch off a rule without deleting it (e.g. when
-- testing a replacement). Disabled rules are skipped by RuleEngine even if
-- their conditions match. Default is 1 so existing rules keep working
-- after the migration.

ALTER TABLE rules
    ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_fallback;
