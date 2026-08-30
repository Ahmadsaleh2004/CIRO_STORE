-- ══════════════════════════════════════════════════════════════
-- 0005_order_expiry_log
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
-- ════════════════════════════════════════════════════════════════════════
-- Migration: the order expiry log — a record of the orders returned automatically to
-- not_taken after the 3-hour deadline passed without delivery (used by
-- OrderModel::releaseExpiredTakenOrders()).
-- Depends on two tables: orders and admins
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `order_expiry_log` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`          INT UNSIGNED NOT NULL,
    `previous_admin_id` INT UNSIGNED DEFAULT NULL COMMENT 'The admin holding the order before the deadline passed',
    `taken_at`          DATETIME NOT NULL COMMENT 'When the order was originally taken',
    `reverted_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When it was released automatically',
    PRIMARY KEY (`id`),
    INDEX `idx_order` (`order_id`),
    INDEX `idx_reverted` (`reverted_at`),
    CONSTRAINT `fk_expirylog_order`  FOREIGN KEY (`order_id`)          REFERENCES `orders`(`order_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_expirylog_admin`  FOREIGN KEY (`previous_admin_id`) REFERENCES `admins`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @DOWN
DROP TABLE IF EXISTS `order_expiry_log`;
