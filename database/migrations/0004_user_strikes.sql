-- ══════════════════════════════════════════════════════════════
-- 0004_user_strikes
-- ══════════════════════════════════════════════════════════════
--
-- الترتيب في اسم الملف لا في تعليق. كان الاعتماد مكتوباً نصّاً
-- («يعتمد على admin_auth.sql») ولا شيء يفرضه — فترتيب التنفيذ
-- كان يتبع ترتيب نظام الملفات، وهو يختلف بين جهاز وآخر.
--
-- يُشغَّل بـ`php scripts/migrate.php up`. والملف يبقى SQL صالحاً
-- يمكن لصقه في أي عميل كما هو — القسمان تعليقان لا صيغة خاصة.

-- @UP
-- ════════════════════════════════════════════════════════════════════════
-- Migration: User Strikes — إنذارات المستخدمين (3 إنذارات = حظر تلقائي
-- + إلغاء الطلبات المعلّقة عبر OrderModel::cancelAllPendingForUser)
-- يعتمد على جدولين: users + admins
-- بنية مطابقة للمشروع القديم (config/schema.sql) — العمود issued_by_admin_id
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
