-- ══════════════════════════════════════════════════════════════
-- 0007_branding_sliders
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
-- ════════════════════════════════════════════════════════════════════════════
-- Migration: branding / the home slider — managing the home page's slider.
-- This file must be run by hand against the ciro_db database.
-- The order: 1) a new permission column on admin_permissions
--            2) the home_sliders table (the slides)
--            3) the home_slider_items table (the images inside each slide)
-- Depends on: admins and admin_permissions (already created by admin_auth.sql)
--             products (already created by the base product schema)
-- ════════════════════════════════════════════════════════════════════════════

-- ── 1. A new permission: managing the slider and branding ───────────────────
ALTER TABLE `admin_permissions`
    ADD COLUMN `can_manage_branding` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `can_edit_site_content`;

-- Grant the root admin (rank A, the first record) this permission automatically so they
-- are not left without it after the upgrade — do not assume id=1; update on the condition
-- role='A'.
UPDATE `admin_permissions` ap
    JOIN `admins` a ON a.id = ap.admin_id
    SET ap.can_manage_branding = 1
    WHERE a.role = 'A';

-- ── 2. The slides table (each row is one carousel frame) ────────────────────
CREATE TABLE IF NOT EXISTS `home_sliders` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sort_order`          INT UNSIGNED NOT NULL DEFAULT 0
                          COMMENT 'The slide order in the carousel — rebuilt entirely on every save',
    `updated_by_admin_id` INT UNSIGNED DEFAULT NULL
                          COMMENT 'The last admin to perform a full slider save (for the whole page, not this slide alone)',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sort_order` (`sort_order`),
    CONSTRAINT `fk_slider_updated_by`
        FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. The images / items table inside each slide ───────────────────────────
-- Each row holds the data for both the Product and Manual modes (neither is deleted when
-- switching), and the active_mode column decides which is genuinely the "default" shown on
-- the site.
CREATE TABLE IF NOT EXISTS `home_slider_items` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slider_id`  INT UNSIGNED NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT 'The image order within its slide (left to right)',

    -- Which of the two modes is in effect (the "default" the admin chose) ────────
    `active_mode` ENUM('product','manual') NOT NULL DEFAULT 'manual',

    -- The Product mode's data ───────────────────────────────────────────────
    `product_id`          INT UNSIGNED DEFAULT NULL
                          COMMENT 'An optional foreign key — it stays NULL if the admin never configured this mode',
    `product_link_url`    VARCHAR(500) DEFAULT NULL
                          COMMENT 'Left empty means: use the default product details link, /product?id=',
    `product_description` VARCHAR(500) DEFAULT NULL,

    -- The Manual mode's data ────────────────────────────────────────────────
    `manual_image_path`   VARCHAR(255) DEFAULT NULL
                          COMMENT 'A relative path such as images/slider_xxx.jpg — uploaded through BrandingModel',
    `manual_link_url`     VARCHAR(500) DEFAULT NULL,
    `manual_description`  VARCHAR(500) DEFAULT NULL,

    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_slider` (`slider_id`),
    INDEX `idx_product` (`product_id`),
    CONSTRAINT `fk_item_slider`
        FOREIGN KEY (`slider_id`)  REFERENCES `home_sliders`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_item_product`
        FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @DOWN
DROP TABLE IF EXISTS `home_slider_items`;
DROP TABLE IF EXISTS `home_sliders`;
ALTER TABLE `admin_permissions` DROP COLUMN `can_manage_branding`;
