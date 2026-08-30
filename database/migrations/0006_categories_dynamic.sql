-- ══════════════════════════════════════════════════════════════
-- 0006_categories_dynamic
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
-- ══════════════════════════════════════════════════════════════
-- categories_dynamic.sql
-- يحوّل categories.name من ENUM ثابت إلى VARCHAR ديناميكي
-- ويضيف عمود is_core لتمييز الأربع الكاتوجريز الأساسية غير القابلة للحذف
-- شغّله مرة واحدة فقط على قاعدة بيانات موجودة فيها الجدول أصلاً بصيغة ENUM.
-- ══════════════════════════════════════════════════════════════

START TRANSACTION;

-- 1) تحويل العمود من ENUM إلى VARCHAR(50) مع الإبقاء على UNIQUE
--    (DROP UNIQUE أولاً لأن MySQL لا يسمح تعديل ENUM column مع unique constraint مباشرة)
--    اسم الـ index الحالي هو 'name' (كما أنشأه MySQL تلقائياً من UNIQUE KEY على ENUM)
ALTER TABLE categories
    DROP INDEX `name`;

ALTER TABLE categories
    MODIFY COLUMN name VARCHAR(50) NOT NULL;

-- 2) إضافة عمود is_core (0 = كاتوجري إضافية قابلة للحذف، 1 = أساسية محمية)
ALTER TABLE categories
    ADD COLUMN is_core TINYINT(1) NOT NULL DEFAULT 0 AFTER name;

-- 3) تعليم الأربع الأساسية كـ is_core = 1
UPDATE categories
SET is_core = 1
WHERE name IN ('phone', 'computer', 'accessories', 'gaming');

-- 4) التأكد من عدم وجود تكرار بالاسم بعد التحويل (safety check)
--    إذا رجع صفوف: يجب حل التكرار يدوياً قبل الاستمرار
-- SELECT name, COUNT(*) c FROM categories GROUP BY name HAVING c > 1;

-- 5) إعادة UNIQUE index على name صراحةً (كان ضمنياً بالـ ENUM، نعيد تأكيده)
ALTER TABLE categories
    ADD UNIQUE KEY uq_categories_name (name);

COMMIT;

-- ══════════════════════════════════════════════════════════════
-- تحقق ما بعد التنفيذ — شغّله يدوياً للتأكد:
-- SELECT id, name, is_core FROM categories WHERE is_core = 1 ORDER BY id;
-- المتوقع: 4 صفوف (accessories, phone, computer, gaming)
-- ══════════════════════════════════════════════════════════════

-- @DOWN
-- ⚠️ لا تراجع آمناً عن هذه الهجرة.
--
-- تحوّل categories.name من ENUM ثابت إلى VARCHAR ديناميكي. والعودة
-- تعني حصر القيم في الأربع الأصلية — فأي تصنيف أضافه أدمن بعدها
-- يفقد اسمه أو يمنع التحويل من إتمامه.
--
-- التراجع هنا يتطلّب قراراً بشرياً عن مصير تلك الصفوف، لا سكربتاً.
-- كُتب القسم صراحةً بدل تركه فارغاً كي يقرأ من يحاول التراجعَ سببَ
-- المنع بدل أن يرى رسالة «بلا قسم @DOWN» فيظنّها سهواً.
SELECT 'IRREVERSIBLE: categories_dynamic — راجع التعليق أعلاه' AS refusal;
