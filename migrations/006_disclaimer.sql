-- Tenant-wide disclaimer/legal footer.
--
-- Appended to every rendered signature, so admins don't have to copy
-- their HRB / Geschäftsführer / impressum lines into every template.
-- Stored as HTML; sanitized by HTML Purifier on save like templates.
-- Empty / NULL means "no footer".

ALTER TABLE tenants
    ADD COLUMN disclaimer_html MEDIUMTEXT DEFAULT NULL AFTER shared_display_mode;
