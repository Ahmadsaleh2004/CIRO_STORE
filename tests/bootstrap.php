<?php
/**
 * tests/bootstrap.php
 * تهيئة بيئة الاختبار.
 *
 * مسؤوليتان فقط:
 *   1. تحميل الـautoloader وثوابت المشروع (APPROOT، URLROOT، DB_*).
 *   2. بناء قاعدة اختبار **منفصلة** وتجهيز اتصال إليها.
 *
 * ⚠️ القاعدة المستعملة هي `<DB_NAME>_test` لا قاعدة التطوير. الفصل
 * ليس احتياطاً: اختبارات التكامل تُفرّغ الجداول بين كل اختبار
 * (TRUNCATE)، فتشغيلها على قاعدة التطوير يمحو بياناتها كلها في أول
 * تشغيل. الاسم يُشتقّ لا يُكتب، كي لا ينفصل عن .env إن تغيّر.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "الاختبارات تعمل على CLI فقط.\n");
    exit(1);
}

/** اسم قاعدة الاختبار — مشتقّ من قاعدة المشروع بلاحقة _test. */
define('TEST_DB_NAME', DB_NAME . '_test');

/**
 * يبني اتصالاً بخادم MySQL بلا اختيار قاعدة.
 * يُرجع null إن تعذّر الاتصال — فتتخطّى اختبارات التكامل نفسها بدل
 * أن يفشل التشغيل كله. اختبارات الوحدة لا تحتاج قاعدة أصلاً.
 */
function testServerConnection(): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($tried) return $pdo;
    $tried = true;

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        $pdo = null;
    }

    return $pdo;
}

/**
 * ينشئ قاعدة الاختبار ويحمّل مخطّطها مرّة واحدة لكل تشغيل.
 *
 * المخطّط من tests/fixtures/schema.sql — نسخة بنيوية بلا بيانات من
 * القاعدة الحقيقية. يُعاد توليدها بـ`composer test:schema` كلما تغيّر
 * المخطّط؛ وإن نسي أحد ذلك تفشل اختبارات التكامل بوضوح بدل أن تمرّ
 * على بنية قديمة.
 */
function prepareTestDatabase(): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($tried) return $pdo;
    $tried = true;

    $server = testServerConnection();
    if ($server === null) return null;

    $schemaFile = __DIR__ . '/fixtures/schema.sql';
    if (!is_file($schemaFile)) return null;

    $name = TEST_DB_NAME;

    // اسم القاعدة مشتقّ من .env لا من مدخل مستخدم، لكن الاقتباس
    // بالعلامات الخلفية يبقى صحيحاً لأسماء تحوي محارف خاصة.
    $quoted = '`' . str_replace('`', '``', $name) . '`';

    $server->exec("DROP DATABASE IF EXISTS {$quoted}");
    $server->exec("CREATE DATABASE {$quoted} CHARACTER SET " . DB_CHARSET);

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $name . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // تُنفَّذ عبارات المخطّط دفعة واحدة. mysqldump يفصلها بفواصل منقوطة
    // في نهايات أسطر، وexec يقبل عبارات متعددة على اتصال MySQL.
    $pdo->exec(file_get_contents($schemaFile));

    return $pdo;
}
