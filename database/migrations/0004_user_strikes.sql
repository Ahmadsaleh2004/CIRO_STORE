-- ══════════════════════════════════════════════════════════════
-- 0004_user_strikes
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
-- Migration: user strikes — three strikes mean an automatic block, plus cancelling the
-- pending orders through OrderModel::cancelAllPendingForUser.
-- Depends on two tables: users and admins.
-- The structure matches the old project (config/schema.sql) — the issued_by_admin_id column
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `user_strikes` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`            INT UNSIGNED NOT NULL,
    `reason`             TEXT         NOT NULL,
    `issued_by_admin_id` INT UNSIGNED DEFAULT NULL,
    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    CONSTRAINT `fk_strike_user`  FOREIGN KEY (`user_id`)            REFERENCES `users`(`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_strike_admin` FOREIGN KEY (`issued_by_admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @DOWN
DROP TABLE IF EXISTS `user_strikes`;
