-- ════════════════════════════════════════════════════════════════════════
-- Migration: Admin Notifications — رسائل فردية + Broadcast بين الأدمنية
-- يعتمد على جدول admins (موجود بـ admin_auth.sql)
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE `admin_notifications` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id`         INT UNSIGNED NOT NULL COMMENT 'المستلم',
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
