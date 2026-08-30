-- ══════════════════════════════════════════════════════════════
-- 0003_admin_notifications
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
-- Migration: admin notifications — direct messages and broadcasts between admins.
-- Depends on the admins table (created in admin_auth.sql)
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE `admin_notifications` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id`         INT UNSIGNED NOT NULL COMMENT 'The recipient',
    `title`            VARCHAR(200) NOT NULL,
    `message`          TEXT         NOT NULL,
    `type`             VARCHAR(50)  NOT NULL DEFAULT 'system',
    `related_type`     VARCHAR(50)  DEFAULT NULL,
    `related_id`       INT UNSIGNED DEFAULT NULL,
    `sender_admin_id`  INT UNSIGNED DEFAULT NULL,
    `is_read`          TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_admin_read` (`admin_id`, `is_read`),
    INDEX `idx_created` (`created_at`),
    CONSTRAINT `fk_adminnotif_admin`
        FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_adminnotif_sender`
        FOREIGN KEY (`sender_admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @DOWN
DROP TABLE IF EXISTS `admin_notifications`;
