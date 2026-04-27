-- Optional date validity window for rules.
--
-- Use case: Christmas / sales-season banners that should activate
-- automatically. Both columns are NULL by default → rule has no time
-- constraint and matches always (current behaviour preserved).
--
-- Comparison is against today's date in the server's timezone, inclusive
-- on both ends. valid_from must be <= today, today must be <= valid_until.

ALTER TABLE rules
    ADD COLUMN valid_from  DATE DEFAULT NULL AFTER is_enabled,
    ADD COLUMN valid_until DATE DEFAULT NULL AFTER valid_from;
