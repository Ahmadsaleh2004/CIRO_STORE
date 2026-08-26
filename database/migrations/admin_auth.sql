-- ════════════════════════════════════════════════════════════════════════════
-- Migration: Admin Auth Tables — نظام صلاحيات A/B/C/D
-- يجب تنفيذ هذا الملف على قاعدة البيانات: ciro_db
-- الترتيب مهم: admins → admin_permissions → admin_audit_log → admin_login_attempts
-- ════════════════════════════════════════════════════════════════════════════

-- ── 1. جدول الأدمنز ──────────────────────────────────────────────────────────
CREATE TABLE `admins` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `full_name`     VARCHAR(100)    NOT NULL,
    `email`         VARCHAR(150)    NOT NULL,
    `password`      VARCHAR(255)    NOT NULL COMMENT 'bcrypt hash — cost >= 12',
    `phone_number`  VARCHAR(30)     DEFAULT NULL,
    `role`          ENUM('A','B','C','D') NOT NULL DEFAULT 'B'
                    COMMENT 'A=Super Admin, B=Admin, C=Moderator, D=Support',
    `added_by`      INT UNSIGNED    DEFAULT NULL
                    COMMENT 'معرّف الأدمن الذي أنشأ هذا الحساب',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `last_activity` DATETIME        NULL
                    COMMENT 'آخر نشاط — تُحدَّث عند كل طلب محمي',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admins_email` (`email`),
    INDEX `idx_email` (`email`),
    CONSTRAINT `fk_admin_added_by`
        FOREIGN KEY (`added_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. جدول صلاحيات الأدمن (علاقة 1:1 مع admins) ────────────────────────────
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

-- ── 3. جدول سجل العمليات (Audit Log) ─────────────────────────────────────────
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

-- ── 4. جدول محاولات تسجيل دخول الأدمن (Rate Limiting) ───────────────────────
-- محتفظ به من النسخة السابقة — يدعم AdminModel::isRateLimited() وlogLoginAttempt()
CREATE TABLE `admin_login_attempts` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`        VARCHAR(150) NOT NULL,
    `ip_address`   VARCHAR(45)  NOT NULL COMMENT 'يدعم IPv6',
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `success`      TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_admin_attempts_email_time` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════════
-- Seed: أدمن رئيسي role='A' بكل الصلاحيات مفعّلة
-- ⚠️ كلمة المرور PLACEHOLDER — تُضبط عبر سكربت seed منفصل قبل الاستخدام:
--    php -r "echo password_hash('YourPassword', PASSWORD_BCRYPT, ['cost' => 12]);"
--    ثم UPDATE admins SET password='<hash>' WHERE email='admin@example.com';
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
