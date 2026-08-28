-- ══════════════════════════════════════════════════════════════
-- 0009_mail_queue
-- ══════════════════════════════════════════════════════════════
--
-- يُشغَّل بـ`php scripts/migrate.php up`. القسمان تعليقان لا صيغة
-- خاصة، فالملف يبقى SQL صالحاً يمكن لصقه في أي عميل كما هو.

-- @UP
-- ════════════════════════════════════════════════════════════════════════════
-- Migration: طابور البريد — إخراج SMTP من مسار الطلب
--
-- كان Mailer::send يفتح اتصال Gmail SMTP **داخل الطلب نفسه**: يتصل
-- ويصادق ويرسل قبل أن يرى الزائر أي استجابة. أثر ذلك ثلاثي:
--
--   · تسجيل دخول الأدمن ينتظر Gmail في كل مرّة.
--   · تباطؤ SMTP أو سقوطه يعلّق خيوط PHP لا يبطّئها فقط.
--   · وأخطرها مع /auth/forgot: كل طلب = اتصال SMTP جديد، فالإغراق
--     لا يستنزف الحصّة وحدها بل خيوط الخادم معها.
--
-- الطابور يفصل «قرار الإرسال» عن «فعل الإرسال»: الطلب يكتب صفّاً
-- ويعود، والعامل (scripts/mail-worker.php) يرسل خارج مسار الطلب.
--
-- ملاحظات على البنية:
--
--   · status كـENUM لا VARCHAR: القيم الثلاث معروفة ومغلقة، وENUM
--     يمنع كتابة حالة رابعة بخطأ مطبعي تبقى معلّقة إلى الأبد.
--
--   · attempts وlast_error معاً: بلا العدّاد تدور رسالة فاشلة دائمة
--     في الطابور بلا نهاية، وبلا نصّ الخطأ لا يُعرف لماذا فشلت.
--
--   · الفهرس على (status, id): العامل يسأل سؤالاً واحداً — «أقدم
--     الرسائل المعلّقة» — والفهرس المركّب يجيبه بلا مسح الجدول.
--
--   · body هو MEDIUMTEXT: قوالب HTML مع روابط طويلة تتجاوز TEXT
--     في الحالات الحدّية، والفرق في التخزين لا يُذكر.
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `mail_queue` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `to_email`   VARCHAR(190)    NOT NULL,
    `to_name`    VARCHAR(150)    NOT NULL DEFAULT '',
    `subject`    VARCHAR(255)    NOT NULL,
    `body`       MEDIUMTEXT      NOT NULL,
    `status`     ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(255)    DEFAULT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at`    DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_status_id` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @DOWN
DROP TABLE IF EXISTS `mail_queue`;
