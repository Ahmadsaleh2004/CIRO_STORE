-- ══════════════════════════════════════════════════════════════
-- 0001_admin_auth
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
-- Migration: admin auth tables — the A/B/C/D permission system.
-- This file must be run against the ciro_db database.
-- The order matters: admins → admin_permissions → admin_audit_log → admin_login_attempts
-- ════════════════════════════════════════════════════════════════════════════

-- ── 1. The admins table ─────────────────────────────────────────────────────
CREATE TABLE `admins` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `full_name`     VARCHAR(100)    NOT NULL,
    `email`         VARCHAR(150)    NOT NULL,
    `password`      VARCHAR(255)    NOT NULL COMMENT 'bcrypt hash — cost >= 12',
    `phone_number`  VARCHAR(30)     DEFAULT NULL,
    `role`          ENUM('A','B','C','D') NOT NULL DEFAULT 'B'
                    COMMENT 'A=Super Admin, B=Admin, C=Moderator, D=Support',
    `added_by`      INT UNSIGNED    DEFAULT NULL
                    COMMENT 'The id of the admin who created this account',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `last_activity` DATETIME        NULL
                    COMMENT 'Last activity — updated on every protected request',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admins_email` (`email`),
    INDEX `idx_email` (`email`),
    CONSTRAINT `fk_admin_added_by`
        FOREIGN KEY (`added_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. The admin permissions table (a 1:1 relation with admins) ─────────────
CREATE TABLE `admin_permissions` (
    `id`                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id`                     INT UNSIGNED NOT NULL,
    `can_manage_admins`            TINYINT(1)   NOT NULL DEFAULT 0,
    `can_manage_products`          TINYINT(1)   NOT NULL DEFAULT 0,
    `can_manage_users`             TINYINT(1)   NOT NULL DEFAULT 0,
    `can_view_dashboard`           TINYINT(1)   NOT NULL DEFAULT 0,
    `can_manage_support`           TINYINT(1)   NOT NULL DEFAULT 0,
    `can_edit_site_content`        TINYINT(1)   NOT NULL DEFAULT 0,
    `can_manage_checkout_settings` TINYINT(1)   NOT NULL DEFAULT 0,
    `can_manage_orders`            TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_perm_admin` (`admin_id`),
    CONSTRAINT `fk_perm_admin`
        FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. The audit log table ──────────────────────────────────────────────────
CREATE TABLE `admin_audit_log` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `admin_id`    INT UNSIGNED  NOT NULL,
    `action`      VARCHAR(100)  NOT NULL,
    `target_type` VARCHAR(50)   DEFAULT NULL,
    `target_id`   INT UNSIGNED  DEFAULT NULL,
    `details`     TEXT          DEFAULT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_admin` (`admin_id`),
    INDEX `idx_created` (`created_at`),
    CONSTRAINT `fk_audit_admin`
        FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. The admin sign-in attempts table (rate limiting) ─────────────────────
-- Kept from the previous version — it backs AdminModel::isRateLimited() and logLoginAttempt()
CREATE TABLE `admin_login_attempts` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`        VARCHAR(150) NOT NULL,
    `ip_address`   VARCHAR(45)  NOT NULL COMMENT 'Wide enough for IPv6',
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `success`      TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_admin_attempts_email_time` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════════
-- Seed: a root admin with role='A' and every permission granted.
-- ⚠️ The password is a PLACEHOLDER — set it through a separate seed script before use:
--    php -r "echo password_hash('YourPassword', PASSWORD_BCRYPT, ['cost' => 12]);"
--    then UPDATE admins SET password='<hash>' WHERE email='admin@example.com';
-- ════════════════════════════════════════════════════════════════════════════
INSERT INTO `admins`
    (`full_name`, `email`, `password`, `phone_number`, `role`, `added_by`)
VALUES
    ('Admin User', 'admin@example.com', 'PLACEHOLDER', '+00000000000', 'A', NULL);

INSERT INTO `admin_permissions`
    (`admin_id`,
     `can_manage_admins`, `can_manage_products`, `can_manage_users`,
     `can_view_dashboard`, `can_manage_support`, `can_edit_site_content`,
     `can_manage_checkout_settings`, `can_manage_orders`)
VALUES
    (1, 1, 1, 1, 1, 1, 1, 1, 1);

-- @DOWN
DROP TABLE IF EXISTS `admin_login_attempts`;
DROP TABLE IF EXISTS `admin_audit_log`;
DROP TABLE IF EXISTS `admin_permissions`;
DROP TABLE IF EXISTS `admins`;
