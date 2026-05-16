-- Per-domain override of the tenants.shared_display_mode setting.
--
-- A tenant can own several email domains (firma.de, muster.de, …) and
-- different domains often use different mailbox naming conventions:
--
--   firma.de → mustermann@firma.de   (Primary user mode)
--   muster.de → m.mustermann@muster.de  (Derived alias mode)
--
-- Format: JSON map of `domain` (lowercase) → mode value
-- (`shared` | `primary` | `derived`).  Domains not listed fall back to
-- the tenant's global `shared_display_mode`.
--
-- Example value:
--   {"firma.de": "primary", "muster.de": "derived"}

ALTER TABLE tenants
    ADD COLUMN shared_display_mode_per_domain JSON DEFAULT NULL
        AFTER shared_display_mode;
