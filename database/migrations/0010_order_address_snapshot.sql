-- ══════════════════════════════════════════════════════════════
-- 0010_order_address_snapshot
-- ══════════════════════════════════════════════════════════════
--
-- Run with `php scripts/migrate.php up`. The two section markers are comments,
-- not special syntax, so the file stays valid SQL that can be pasted into any
-- client as-is.

-- @UP
-- ════════════════════════════════════════════════════════════════════════════
-- Migration: an order's address is a snapshot, not a reference
--
-- `orders.address_id` used to be a live foreign key into `user_addresses` with
-- `ON DELETE SET NULL`. Which is to say the order's address did not belong to the order: it
-- followed the row it pointed at wherever that went.
--
-- And the effect is not theoretical:
--
--   · a user edits their address after delivery → the address of an order that was
--     **actually delivered** changes retroactively. The record says the shipment went
--     somewhere it never went.
--   · a user deletes an old address → `SET NULL`, and completed orders lose their address
--     **permanently**. With no copy anywhere else.
--
-- And this is the class of error that appears in no functional test and on no screen — it
-- appears the day somebody asks "where was this order sent?" and the question has no answer.
--
-- ── Why a snapshot rather than a history table ───────────────
--
-- A history table (a copy per address edit) solves the same problem and adds to it a join
-- on every display, a table that grows without bound, and "which version was in effect at
-- the moment of the order" logic that must be written and tested.
--
-- And an order needs none of that: it needs **where it was sent**, once, unchanging.
-- So flat text in the order's row is both cheaper and more honest.
--
-- ── Why address_id stays ─────────────────────────────────────
--
-- Removing it would have broken the admin screens that join the order to the user's
-- address to show their live details. The key stays for the relationship, and the snapshot
-- is the historical record — and where they conflict, the snapshot always wins.
--
-- ── The columns are NULL deliberately ────────────────────────
--
-- Orders existing before this migration have no snapshot, and filling them with an empty
-- string would lie: "the address is known and it is blank" is not "no address on record".
-- The UPDATE below fills them from whatever live references remain — the best that can be
-- recovered — and leaves NULL for those whose reference is already gone.
-- ════════════════════════════════════════════════════════════════════════════

ALTER TABLE `orders`
    ADD COLUMN `address_snapshot`      TEXT        DEFAULT NULL AFTER `address_id`,
    ADD COLUMN `address_phone_snapshot` VARCHAR(30) DEFAULT NULL AFTER `address_snapshot`;

-- Filling the existing orders from the addresses not yet deleted.
-- CONCAT_WS skips NULLs on its own, so no dangling commas appear.
UPDATE `orders` o
    JOIN `user_addresses` a ON a.id = o.address_id
SET
    o.`address_snapshot`       = CONCAT_WS(', ',
        NULLIF(a.label, ''),
        NULLIF(a.full_address, ''),
        NULLIF(a.city, ''),
        NULLIF(a.country, '')
    ),
    o.`address_phone_snapshot` = a.phone_number
WHERE o.`address_snapshot` IS NULL;

-- @DOWN
ALTER TABLE `orders`
    DROP COLUMN `address_phone_snapshot`,
    DROP COLUMN `address_snapshot`;
