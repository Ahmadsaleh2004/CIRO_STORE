-- ════════════════════════════════════════════════════════════════════════════
-- Migration: Branding / Home Slider — إدارة سلايدر الصفحة الرئيسية
-- يجب تنفيذ هذا الملف يدوياً على قاعدة البيانات: ciro_db
-- الترتيب: 1) عمود صلاحية جديدة على admin_permissions
--          2) جدول home_sliders (الشرائح)
--          3) جدول home_slider_items (الصور داخل كل شريحة)
-- يعتمد على: admins, admin_permissions (موجودة مسبقاً من admin_auth.sql)
--            products (موجودة مسبقاً من سكيما المنتجات الأساسية)
-- ════════════════════════════════════════════════════════════════════════════

-- ── 1. صلاحية جديدة: إدارة السلايدر/البراندنج ────────────────────────────────
ALTER TABLE `admin_permissions`
    ADD COLUMN `can_manage_branding` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `can_edit_site_content`;

-- منح الأدمن الأساسي (Role A، أول سجل) هذه الصلاحية تلقائياً حتى لا يُحرَم منها
-- بعد الترقية — لا تفترض id=1 دائماً، حدّثه بشرط role='A'.
UPDATE `admin_permissions` ap
    JOIN `admins` a ON a.id = ap.admin_id
    SET ap.can_manage_branding = 1
    WHERE a.role = 'A';

-- ── 2. جدول الشرائح (كل صف = فريم واحد بالكاروسيل) ──────────────────────────
CREATE TABLE IF NOT EXISTS `home_sliders` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sort_order`          INT UNSIGNED NOT NULL DEFAULT 0
                          COMMENT 'ترتيب ظهور الشريحة بالكاروسيل — يُعاد بناؤه بالكامل عند كل حفظ',
    `updated_by_admin_id` INT UNSIGNED DEFAULT NULL
                          COMMENT 'آخر أدمن قام بالحفظ الكامل للسلايدر (لكل الصفحة وليس فقط هذه الشريحة)',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sort_order` (`sort_order`),
    CONSTRAINT `fk_slider_updated_by`
        FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. جدول الصور/العناصر داخل كل شريحة ──────────────────────────────────────
-- كل صف يحتفظ ببيانات وضعي Product و Manual معاً (لا يُحذف أي منهما عند التبديل)
-- والعمود active_mode يحدد أيهما "Default" فعلياً يُعرض بالموقع.
CREATE TABLE IF NOT EXISTS `home_slider_items` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slider_id`  INT UNSIGNED NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT 'ترتيب الصورة داخل نفس الشريحة (من اليسار لليمين)',

    -- أيّ الوضعين فعليّ (هذا هو "Default" الذي اختاره الأدمن) ─────────────────
    `active_mode` ENUM('product','manual') NOT NULL DEFAULT 'manual',

    -- بيانات وضع Product ────────────────────────────────────────────────────
    `product_id`          INT UNSIGNED DEFAULT NULL
                          COMMENT 'FK اختياري — يبقى NULL إذا لم يُهيّئ الأدمن هذا الوضع إطلاقاً',
    `product_link_url`    VARCHAR(500) DEFAULT NULL
                          COMMENT 'يُترك فارغاً = استخدم رابط تفاصيل المنتج الافتراضي /product?id=',
    `product_description` VARCHAR(500) DEFAULT NULL,

    -- بيانات وضع Manual ─────────────────────────────────────────────────────
    `manual_image_path`   VARCHAR(255) DEFAULT NULL
                          COMMENT 'مسار نسبي مثل images/slider_xxx.jpg — يُرفع عبر BrandingModel',
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