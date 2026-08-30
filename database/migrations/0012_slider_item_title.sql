-- ══════════════════════════════════════════════════════════════
-- 0012_slider_item_title
-- ══════════════════════════════════════════════════════════════
--
-- Run with `php scripts/migrate.php up`. The two section markers are comments,
-- not special syntax, so the file stays valid SQL that can be pasted into any
-- client as-is.

-- @UP
-- ════════════════════════════════════════════════════════════════════════════
-- Migration: a title line on the slider image, above the description
--
-- Each slider item used to have one piece of text drawn over the image, in its
-- `product_description` / `manual_description` column. And because it was the only one,
-- admins wrote into it whatever came to hand: on product items it was filled automatically
-- with the product's description ("Portable gaming console."), and on manual items a
-- product's **name** was typed into it ("Galaxy S24 Ultra").
--
-- Which is to say one field carried two competing roles: a title that identifies and a
-- description that explains. The result was that the image showed one of them and never the
-- other.
--
-- ── Why a column rather than deriving from products.name ────
--
-- Because a product's name is written for the catalogue, not for the slider. A name like
-- «Apple AirPods Pro (2nd generation) with MagSafe Charging Case»
-- is right on the product page, and on a slider image it wraps to three lines and covers it.
--
-- So the column gives the admin a shorter form for display **without touching the
-- product's name**. It is the same contract as the neighbouring fields: filled
-- automatically and left editable.
--
-- ── And why NULL with no backfill ───────────────────────────
--
-- Because the read uses COALESCE(NULLIF(product_title,''), p.name).
-- So every existing slide shows its product's name the moment the migration is applied,
-- without a single row of data
-- being written — and the column stays empty until an admin decides on a shorter form.
--
-- And copying the name in a backfill would have produced a second copy that goes stale:
-- the product gets renamed and the slider keeps the old name, with nobody the wiser.
--
-- ⚠️ manual_title has no fallback, deliberately: there is no product to derive a name
-- from, so a manual item without a title is drawn with its description alone. And that
-- describes the reality: the manual items today carry their text in the description field,
-- and moving it to the title is an editorial decision belonging to the admin, not to the
-- migration.
-- ════════════════════════════════════════════════════════════════════════════

ALTER TABLE `home_slider_items`
    ADD COLUMN `product_title` varchar(200) DEFAULT NULL
        COMMENT 'A title drawn above the description — empty means: use products.name'
        AFTER `product_link_url`,
    ADD COLUMN `manual_title` varchar(200) DEFAULT NULL
        COMMENT 'A manual item''s title — it has no fallback, so empty means no title at all'
        AFTER `manual_link_url`;

-- @DOWN
ALTER TABLE `home_slider_items`
    DROP COLUMN `product_title`,
    DROP COLUMN `manual_title`;
