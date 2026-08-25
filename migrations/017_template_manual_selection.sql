-- Per-template "manually selectable" flag.
--
-- Templates with this flag appear in the Outlook add-in's "Choose signature"
-- taskpane, where users can insert them on demand. Default 0: nothing is
-- exposed to end users until an admin opts a template in.

ALTER TABLE templates
    ADD COLUMN is_manually_selectable TINYINT(1) NOT NULL DEFAULT 0 AFTER html;
