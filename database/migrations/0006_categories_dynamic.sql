-- ══════════════════════════════════════════════════════════════
-- 0006_categories_dynamic
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
-- ══════════════════════════════════════════════════════════════
-- categories_dynamic.sql
-- Converts categories.name from a fixed ENUM to a dynamic VARCHAR, and adds an is_core
-- column marking the four core categories that cannot be deleted.
-- Run it once only, against a database where the table already exists in its ENUM form.
-- ══════════════════════════════════════════════════════════════

START TRANSACTION;

-- 1) Convert the column from ENUM to VARCHAR(50) while keeping it UNIQUE.
--    (DROP UNIQUE first, because MySQL does not allow altering an ENUM column with a
--    unique constraint directly.)
--    The current index is named 'name' (as MySQL created it automatically from the UNIQUE
--    KEY on the ENUM).
ALTER TABLE categories
    DROP INDEX `name`;

ALTER TABLE categories
    MODIFY COLUMN name VARCHAR(50) NOT NULL;

-- 2) Add the is_core column (0 = an extra category that can be deleted, 1 = a protected core one)
ALTER TABLE categories
    ADD COLUMN is_core TINYINT(1) NOT NULL DEFAULT 0 AFTER name;

-- 3) Mark the four core categories as is_core = 1
UPDATE categories
SET is_core = 1
WHERE name IN ('phone', 'computer', 'accessories', 'gaming');

-- 4) Confirm no duplicate names after the conversion (a safety check).
--    If it returns rows: the duplication must be resolved by hand before continuing.
-- SELECT name, COUNT(*) c FROM categories GROUP BY name HAVING c > 1;

-- 5) Restore the UNIQUE index on name explicitly (it was implicit with the ENUM; this reasserts it)
ALTER TABLE categories
    ADD UNIQUE KEY uq_categories_name (name);

COMMIT;

-- ══════════════════════════════════════════════════════════════
-- A post-run check — run it by hand to confirm:
-- SELECT id, name, is_core FROM categories WHERE is_core = 1 ORDER BY id;
-- Expected: 4 rows (accessories, phone, computer, gaming)
-- ══════════════════════════════════════════════════════════════

-- @DOWN
-- ⚠️ There is no safe rollback for this migration.
--
-- It converts categories.name from a fixed ENUM to a dynamic VARCHAR. Going back means
-- confining the values to the original four — so any category an admin added afterwards
-- either loses its name or stops the conversion from completing.
--
-- Rolling back here requires a human decision about those rows' fate, not a script.
-- The section was written out rather than left empty, so whoever attempts a rollback reads
-- the reason for the refusal instead of seeing a "no @DOWN section" message and taking it
-- for an oversight.
SELECT 'IRREVERSIBLE: categories_dynamic — see the comment above' AS refusal;
