-- ══════════════════════════════════════════════════════════════
-- 0002_admin_last_modified_by
-- ══════════════════════════════════════════════════════════════
--
-- Ordering lives in the file name, not in a comment. The dependency used to be
-- written as prose ("depends on admin_auth.sql") with nothing enforcing it — so
-- execution order followed the file system's order, which differs per machine.
--
-- Run with `php scripts/migrate.php up`. The file stays valid SQL that can be
-- pasted into any client as-is — the two section markers are comments, not
-- special syntax.

-- @UP
ALTER TABLE `admins`
    ADD COLUMN `last_modified_by` INT UNSIGNED DEFAULT NULL
        COMMENT 'Admin ID who most recently added or edited this record'
        AFTER `added_by`,
    ADD CONSTRAINT `fk_admin_last_modified_by`
        FOREIGN KEY (`last_modified_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

-- @DOWN
ALTER TABLE `admins`
    DROP FOREIGN KEY `fk_admin_last_modified_by`,
    DROP COLUMN `last_modified_by`;
