-- Fixed-window rate limiting for the public add-in API (/api/sig).
--
-- One row per bucket (e.g. per tenant, or per client IP for auth failures).
-- The window resets lazily on the next hit after it elapses — no cron
-- needed, which matters on shared hosting.

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(64) NOT NULL,
    window_start DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
