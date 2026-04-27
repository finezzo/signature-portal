-- Bruteforce protection on the local-auth login.
--
-- - failed_login_count:    counter, incremented on every miss, reset on success.
-- - locked_until:          if set and in the future, login is refused until then.
--
-- Lock is triggered after MAX_FAILED_ATTEMPTS misses inside FAILURE_WINDOW —
-- both controlled in Authenticator. Counter resets if the previous failure
-- was longer than the window ago, so honest users with sticky fingers never
-- get locked.

ALTER TABLE users
    ADD COLUMN failed_login_count   INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at,
    ADD COLUMN last_failed_login_at DATETIME DEFAULT NULL              AFTER failed_login_count,
    ADD COLUMN locked_until         DATETIME DEFAULT NULL              AFTER last_failed_login_at;
