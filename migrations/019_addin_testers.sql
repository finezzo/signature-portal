-- Users who test a preview or local add-in build (JSON list of lowercase
-- addresses). The stable add-in stands down for them, so the signature they see
-- always comes from the code under test. See SignatureService::resolve().

ALTER TABLE tenants
    ADD COLUMN addin_testers JSON DEFAULT NULL AFTER disclaimer_html;
