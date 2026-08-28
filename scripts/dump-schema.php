<?php

/**
 * scripts/dump-schema.php
 * يعيد توليد tests/fixtures/schema.sql — بنية القاعدة بلا بيانات.
 *
 * ── لماذا سكربت بدل سطر واحد ────────────────────────────────
 *
 * كان الأمر معرَّفاً في composer.json هكذا:
 *
 *     mysqldump --no-data ... > tests/fixtures/schema.sql
 *
 * وفيه عطلان، وقعا فعلاً:
 *
 *   1. `mysqldump` ليس على PATH في تثبيت XAMPP الافتراضي (هو في
 *      C:\xampp\mysql\bin)، فالأمر يفشل.
 *
 *   2. **وإعادة التوجيه `>` تُفرِغ الملف قبل أن يبدأ الأمر.** فحين
 *      يفشل، لا يكون قد كُتب شيء — لكن الملف الأصلي قد أُتلف سلفاً.
 *      أي أن الأمر المصمَّم لتحديث المخطّط المرجعي كان **يمحوه** عند
 *      أول فشل.
 *
 * ولهذا يكتب هذا السكربت إلى ملف مؤقّت، ويتحقّق أن الناتج يشبه مخطّطاً
 * فعلاً، ثم — وعندها فقط — ينقله فوق الملف الحقيقي. الفشل يترك
 * المخطّط القائم كما هو.
 *
 * الاستعمال:  composer test:schema
 * مسار mysqldump غير المعتاد:  MYSQLDUMP=/path/to/mysqldump composer test:schema
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * يبحث عن mysqldump: متغيّر البيئة أولاً، ثم PATH، ثم المواضع المعتادة.
 */
function locateMysqldump(): ?string
{
    $fromEnv = getenv('MYSQLDUMP');
    if (is_string($fromEnv) && $fromEnv !== '' && is_executable($fromEnv)) {
        return $fromEnv;
    }

    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $probe     = $isWindows ? 'where mysqldump' : 'command -v mysqldump';

    $output = [];
    $status = 0;
    @exec($probe . ' 2>' . ($isWindows ? 'NUL' : '/dev/null'), $output, $status);
    if ($status === 0 && isset($output[0]) && trim($output[0]) !== '') {
        return trim($output[0]);
    }

    $candidates = $isWindows
        ? ['C:\\xampp\\mysql\\bin\\mysqldump.exe', 'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe']
        : ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/homebrew/bin/mysqldump'];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

$binary = locateMysqldump();

if ($binary === null) {
    fwrite(STDERR, PHP_EOL . "  ✗ لم يُعثر على mysqldump." . PHP_EOL);
    fwrite(STDERR, "    مرّر مساره صراحةً: MYSQLDUMP=/path/to/mysqldump composer test:schema" . PHP_EOL . PHP_EOL);
    exit(1);
}

$target = ROOTPATH . '/tests/fixtures/schema.sql';
$temp   = $target . '.tmp';

// كلمة السر عبر ملف خيارات لا سطر أوامر: سطر الأوامر مقروء لأي مستخدم
// على النظام عبر قائمة العمليات. (القرار نفسه المتّخذ في BackupModel.)
$optionsFile = tempnam(sys_get_temp_dir(), 'cairo-dump-');
if ($optionsFile === false) {
    fwrite(STDERR, "  ✗ تعذّر إنشاء ملف خيارات مؤقّت." . PHP_EOL);
    exit(1);
}

$quote = static fn (string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';

file_put_contents($optionsFile, implode("\n", [
    '[client]',
    'host=' . $quote(DB_HOST),
    'port=' . $quote((string) DB_PORT),
    'user=' . $quote(DB_USER),
    'password=' . $quote(DB_PASS),
]) . "\n");
@chmod($optionsFile, 0600);

$command = escapeshellarg($binary)
    . ' --defaults-extra-file=' . escapeshellarg($optionsFile)
    . ' --no-data --skip-comments --skip-add-locks --skip-set-charset'
    . ' --routines=false --triggers=false'
    . ' ' . escapeshellarg(DB_NAME)
    . ' > ' . escapeshellarg($temp);

$status = 0;
// ⚠️ إنذار كاذب موثَّق. الأمر مبنيّ بالكامل من ثوابت الإعداد، وكل جزء
// متغيّر فيه يمرّ من escapeshellarg — بما فيها مسار الثنائي واسم
// القاعدة والملفان المؤقتان (انظر البناء أعلاه سطراً سطراً). ولا مدخل
// من الشبكة يصل إلى هنا أصلاً: هذا سكربت طرفية يرفض العمل خارج CLI.
// nosemgrep: php.lang.security.exec-use.exec-use
@exec($command, $ignored, $status);

// ملف الخيارات يحمل كلمة مرور القاعدة، فحذفه فوراً بعد الاستعمال
// مقصود — وهو سبب وجوده أصلاً (بدلاً من تمرير كلمة السر في سطر الأوامر
// حيث يراها أي `ps`). المسار من tempnam() لا من مدخل، فلا شيء يوجّهه.
// nosemgrep: php.lang.security.unlink-use.unlink-use
unlink($optionsFile);

// ── التحقّق قبل أي كتابة فوق الملف الحقيقي ───────────────────
$dump = is_file($temp) ? (string) file_get_contents($temp) : '';

if ($status !== 0 || $dump === '' || !str_contains($dump, 'CREATE TABLE')) {
    // $temp من tempnam() لا من مدخل — كسابقتها.
    // nosemgrep: php.lang.security.unlink-use.unlink-use
    @unlink($temp);
    fwrite(STDERR, PHP_EOL . "  ✗ فشل التوليد (كود {$status}) — المخطّط القائم لم يُمسّ." . PHP_EOL . PHP_EOL);
    exit(1);
}

// نهايات الأسطر تُوحَّد على LF: .gitattributes يخزّن كذلك، فترك CRLF
// هنا يجعل كل توليد على Windows يبدو تغييراً كاملاً في الـdiff.
$dump = str_replace("\r\n", "\n", $dump);
file_put_contents($target, $dump);
// nosemgrep: php.lang.security.unlink-use.unlink-use
@unlink($temp);

$tables = substr_count($dump, 'CREATE TABLE');
echo PHP_EOL . '  ✓ ' . $tables . ' جدولاً → tests/fixtures/schema.sql' . PHP_EOL . PHP_EOL;
exit(0);
