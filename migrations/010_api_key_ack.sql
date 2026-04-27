-- Tracks whether the current API key has been "acknowledged" by an admin
-- after the most recent rotation — i.e. somebody confirmed they redistributed
-- the new manifest. The banner stays visible until acknowledged.
--
-- Default NULL means the rotation that produced the current key has not been
-- acknowledged yet. New tenants set this on creation so the banner does not
-- appear until they actually rotate again.

ALTER TABLE tenants
    ADD COLUMN api_key_acknowledged_at DATETIME DEFAULT NULL AFTER api_key_last_rotated_at;
