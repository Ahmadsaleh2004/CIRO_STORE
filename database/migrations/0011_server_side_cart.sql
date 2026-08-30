-- ══════════════════════════════════════════════════════════════
-- 0011_server_side_cart
-- ══════════════════════════════════════════════════════════════
--
-- Run with `php scripts/migrate.php up`. The two section markers are comments,
-- not special syntax, so the file stays valid SQL that can be pasted into any
-- client as-is.

-- @UP
-- ════════════════════════════════════════════════════════════════════════════
-- Migration: السلّة تتبع المستخدم لا المتصفّح
--
-- كانت السلّة كلّها في `localStorage`. وهذا يعني ثلاثة أشياء:
--
--   · من يضيف على هاتفه لا يجد شيئاً على حاسوبه.
--   · مسحُ بيانات المتصفّح — أو نافذة خاصة — يمحو سلّة مليئة. وضياع
--     سلّة مليئة خسارة بيع مباشرة لا إزعاج واجهة.
--   · و«ما الذي يضعه الناس ولا يشترونه؟» سؤال لا جواب له إطلاقاً،
--     لأن البيانات لم تصل الخادم قط.
--
-- ── لا سلّة زائر — وهذا ما يبسّط الجدول ──────────────────────
--
-- زرّ السلّة وزرّ «أضف للسلّة» كلاهما محروس بتسجيل الدخول في القوالب
-- الثلاثة (navbar.php · product.php · product_dit.php)، وغير المسجَّل
-- يُدفع إلى نافذة الدخول. فلا وجود لسلّة زائر أصلاً.
--
-- ولذلك لا حاجة إلى session_id ولا إلى منطق دمج عند الدخول: لكل صفّ
-- مالكٌ معروف منذ لحظة إنشائه.
--
-- ── لا عمود سعر — عمداً ──────────────────────────────────────
--
-- ⚠️ الجدول يخزّن «ماذا وكم» فقط. السعر يُقرأ من القاعدة لحظة الطلب
-- (راجع OrderModel::placeOrder). وعمود سعر هنا يعيد فتح الباب الذي
-- أُغلق في المرحلة الأولى: قيمة مالية مخزَّنة خارج مصدرها تصير مع
-- الوقت مصدرَ حقيقة ثانياً، ثم يقرأها أحدهم يوماً.
--
-- ── المفتاح الفريد هو المنطق ────────────────────────────────
--
-- UNIQUE(user_id, variant_id) يجعل «أضف نفس اللون مرّتين» تحديثاً
-- لكميّة صفّ واحد لا صفّاً ثانياً — بـINSERT ... ON DUPLICATE KEY
-- UPDATE، أي في رحلة واحدة وبلا سباق بين تبويبين.
--
-- والـvariant هو المفتاح لا المنتج: نفس المنتج بلونين سطران مستقلّان،
-- وهو ما تعرضه السلّة فعلاً.
--
-- ── CASCADE على الثلاثة ─────────────────────────────────────
--
-- حذف مستخدم أو منتج أو variant يمسح صفوف السلّة المعلّقة به. سلّة
-- تشير إلى منتج محذوف ليست بياناً بل عطلاً ينتظر من يقرأه.
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `cart_items` (
    `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED     NOT NULL,
    `product_id` INT UNSIGNED     NOT NULL,
    `variant_id` INT UNSIGNED     NOT NULL,
    `quantity`   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- سطر واحد لكل (مستخدم، لون). راجع التعليق أعلاه.
    UNIQUE KEY `uniq_user_variant` (`user_id`, `variant_id`),

    -- الاستعلام الوحيد المتكرّر: «سلّة هذا المستخدم».
    KEY `idx_user` (`user_id`),

    CONSTRAINT `fk_cart_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cart_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cart_variant`
        FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @DOWN
DROP TABLE IF EXISTS `cart_items`;
