-- ══════════════════════════════════════════════════════════════
-- 0011_server_side_cart
-- ══════════════════════════════════════════════════════════════
--
-- Run with `php scripts/migrate.php up`. The two section markers are comments,
-- not special syntax, so the file stays valid SQL that can be pasted into any
-- client as-is.

-- @UP
-- ════════════════════════════════════════════════════════════════════════════
-- Migration: the cart follows the user, not the browser
--
-- The cart used to live entirely in `localStorage`. Which meant three things:
--
--   · somebody adding on their phone found nothing on their computer;
--   · clearing browser data — or a private window — erased a full cart. And losing a full
--     cart is a direct lost sale, not a UI annoyance;
--   · and "what do people put in and not buy?" was a question with no answer at all,
--     because the data never reached the server.
--
-- ── No guest cart — which is what keeps the table simple ────
--
-- The cart button and the "add to cart" button are both login-guarded in all three
-- templates (navbar.php · product.php · product_dit.php), and a signed-out visitor is
-- pushed to the login modal. So a guest cart does not exist in the first place.
--
-- Which is why there is no need for a session_id and no need for merge-on-sign-in logic:
-- every row has a known owner from the moment it is created.
--
-- ── No price column — deliberately ──────────────────────────
--
-- ⚠️ The table stores "what and how many" and nothing else. The price is read from the
-- database at the moment of the order (see OrderModel::placeOrder). A price column here
-- would reopen the door closed in the first phase: a monetary value stored away from its
-- source becomes, over time, a second source of truth, and one day somebody reads it.
--
-- ── The unique key is the logic ─────────────────────────────
--
-- UNIQUE(user_id, variant_id) makes "add the same colour twice" an update to one row's
-- quantity rather than a second row — through INSERT … ON DUPLICATE KEY UPDATE, that is, in
-- one round trip and with no race between two tabs.
--
-- And the variant is the key rather than the product: the same product in two colours is
-- two independent lines, which is what the cart actually shows.
--
-- ── CASCADE on all three ───────────────────────────────────
--
-- Deleting a user, a product or a variant clears the cart rows hanging off it. A cart
-- pointing at a deleted product is not data but a fault waiting for somebody to read it.
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `cart_items` (
    `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED     NOT NULL,
    `product_id` INT UNSIGNED     NOT NULL,
    `variant_id` INT UNSIGNED     NOT NULL,
    `quantity`   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- One row per (user, colour). See the comment above.
    UNIQUE KEY `uniq_user_variant` (`user_id`, `variant_id`),

    -- The one recurring query: "this user's cart".
    KEY `idx_user` (`user_id`),

    CONSTRAINT `fk_cart_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cart_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cart_variant`
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @DOWN
DROP TABLE IF EXISTS `cart_items`;
