-- ══════════════════════════════════════════════════════════════
-- 0008_throttle
-- ══════════════════════════════════════════════════════════════
--
-- يُشغَّل بـ`php scripts/migrate.php up`. القسمان تعليقان لا صيغة
-- خاصة، فالملف يبقى SQL صالحاً يمكن لصقه في أي عميل كما هو.

-- @UP
-- ════════════════════════════════════════════════════════════════════════════
-- Migration: خنق موحَّد لنقاط الدخول الحسّاسة + منع إعادة استخدام كود TOTP
--
-- 1) throttle_attempts — عدّاد محاولات عامّ يخدم كل نقاط الدخول.
--
--    لماذا جدول جديد بدل توسيع login_attempts؟ لأن الجدولين القائمين
--    (`login_attempts` و`admin_login_attempts`) مرتبطان بالبريد: كلاهما
--    يجيب عن سؤال «كم مرّة فشل الدخول إلى **هذا الحساب**»، والواجهة
--    تعرض الناتج للأدمن كـfailed_attempts. وهو سؤال يبقى مطلوباً.
--
--    الجدول هنا يجيب عن سؤال آخر لم يكن أحد يسأله: «كم طلباً أرسل
--    **هذا المصدر** إلى هذه النقطة». هو ما يوقف من يجرّب مليون كود
--    TOTP، أو يستدعي استعادة كلمة المرور ألف مرّة ليغرق صندوق بريد.
--    دمج السؤالين في جدول واحد كان سيجبر أحدهما على الكذب.
--
--    المفتاح مركّب لأن كل استعلام يسأل عن الثلاثة معاً: أي دلو،
--    لأي مصدر، خلال أي نافذة. الترتيب attempted_at أخيراً مقصود —
--    فهو الحقل الوحيد الذي يُسأل عنه بمدى لا بمساواة.
--
-- 2) admins.last_totp_slice — آخر شريحة زمنية استُهلكت.
--
--    verifyCode تقبل نافذة ±30 ثانية، فالكود الواحد يبقى صالحاً تسعين
--    ثانية. من يلتقط كوداً صالحاً (فوق كتف، أو من سجلّ) يعيد استعماله
--    داخل النافذة. تخزين الشريحة المستهلَكة يجعل كل كود صالحاً مرّة
--    واحدة فقط. BIGINT لا INT: الشريحة هي time()/30، وتتجاوز حدّ
--    الـINT الموقَّع سنة 2038.
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `throttle_attempts` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bucket`       VARCHAR(40)     NOT NULL
                   COMMENT 'اسم النقطة المحروسة — login, forgot, twofa …',
    `identifier`   VARCHAR(64)     NOT NULL
                   COMMENT 'المصدر المحروس — عنوان IP حالياً (يدعم IPv6)',
    `attempted_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bucket_identifier_time` (`bucket`, `identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `admins`
    ADD COLUMN `last_totp_slice` BIGINT NULL DEFAULT NULL
    COMMENT 'آخر شريحة TOTP استُهلكت — يمنع إعادة استخدام الكود نفسه'
    AFTER `totp_enabled`;

-- @DOWN
ALTER TABLE `admins` DROP COLUMN `last_totp_slice`;
DROP TABLE IF EXISTS `throttle_attempts`;
