-- ══════════════════════════════════════════════════════════════
-- 0002_admin_last_modified_by
-- ══════════════════════════════════════════════════════════════
--
-- الترتيب في اسم الملف لا في تعليق. كان الاعتماد مكتوباً نصّاً
-- («يعتمد على admin_auth.sql») ولا شيء يفرضه — فترتيب التنفيذ
-- كان يتبع ترتيب نظام الملفات، وهو يختلف بين جهاز وآخر.
--
-- يُشغَّل بـ`php scripts/migrate.php up`. والملف يبقى SQL صالحاً
-- يمكن لصقه في أي عميل كما هو — القسمان تعليقان لا صيغة خاصة.

-- @UP
ALTER TABLE `admins`
    ADD COLUMN `last_modified_by` INT UNSIGNED DEFAULT NULL
        COMMENT 'Admin ID who most recently added or edited this record'
        AFTER `added_by`,
    ADD CONSTRAINT `fk_admin_last_modified_by`
        FOREIGN KEY (`last_modified_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

-- @DOWN
ALTER TABLE `admins`
    DROP FOREIGN KEY `fk_admin_last_modified_by`,
    DROP COLUMN `last_modified_by`;
