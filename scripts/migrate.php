<?php

/**
 * scripts/migrate.php
 * واجهة الطرفية للمهاجر.
 *
 *   php scripts/migrate.php status              الحالة
 *   php scripts/migrate.php up [--pretend]      تطبيق المعلّق
 *   php scripts/migrate.php down [n] [--pretend] تراجع عن آخر n
 *   php scripts/migrate.php baseline            تسجيل الموجود كمطبَّق
 *   php scripts/migrate.php make <name>         ملف هجرة جديد
 *
 * ⚠️ `baseline` تُستدعى مرّة واحدة على قاعدة بُنيت من
 * tests/fixtures/schema.sql: خطّ الأساس يحوي أثر الهجرات السبع فعلاً،
 * وتنفيذها عليه يفشل بـ«الجدول موجود».
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

use App\Core\Database;
use App\Core\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$directory = ROOTPATH . '/database/migrations';
$argvList  = $argv ?? [];
$command   = $argvList[1] ?? 'status';
$pretend   = in_array('--pretend', $argvList, true);

/** يطبع سطراً ملوّناً حسب الحالة. */
function line(string $symbol, string $text): void
{
    echo '  ' . $symbol . ' ' . $text . PHP_EOL;
}

// ── make لا تحتاج اتصالاً بالقاعدة ────────────────────────────
if ($command === 'make') {
    $name = $argvList[2] ?? '';
    if ($name === '') {
        fwrite(STDERR, "الاستعمال: php scripts/migrate.php make <name>\n");
        exit(1);
    }

    $name = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($name)) ?? 'migration';

    $existing = glob($directory . '/*.sql') ?: [];
    $next = 1;
    foreach ($existing as $file) {
        if (preg_match('/^(\d{4})_/', basename($file), $m)) {
            $next = max($next, (int) $m[1] + 1);
        }
    }

    $version = str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    $path    = $directory . '/' . $version . '_' . $name . '.sql';

    file_put_contents($path, implode("\n", [
        '-- ══════════════════════════════════════════════════════════════',
        '-- ' . $version . '_' . $name,
        '-- ══════════════════════════════════════════════════════════════',
        '--',
        '-- اشرح هنا **لماذا** هذا التغيير، لا ماذا يفعل — الـSQL أدناه',
        '-- يقول ماذا. وبعد تطبيقه شغّل `composer test:schema` كي يلحق',
        '-- المخطّط المرجعي بالتغيير.',
        '',
        '-- @UP',
        '',
        '',
        '-- @DOWN',
        '-- إن كان التراجع مستحيلاً فاكتب سببه هنا صراحةً بدل ترك القسم',
        '-- فارغاً — الفراغ يُقرأ سهواً، والسبب يُقرأ قراراً.',
        '',
    ]) . "\n");

    line('✓', 'أُنشئت: ' . str_replace(ROOTPATH . DIRECTORY_SEPARATOR, '', $path));
    exit(0);
}

$migrator = new Migrator(Database::connect(), $directory);

echo PHP_EOL . '  الهجرات — ' . DB_NAME . PHP_EOL . PHP_EOL;

try {
    switch ($command) {
        case 'status':
            $applied = $migrator->applied();
            $pending = $migrator->pending();
            $drift   = $migrator->drifted();

            foreach ($migrator->available() as $migration) {
                $key = $migration['version'];
                if (isset($applied[$key])) {
                    line('✓', $key . '_' . $migration['name'] . '  — طُبِّقت ' . $applied[$key]['applied_at']);
                } else {
                    line('·', $key . '_' . $migration['name'] . '  — معلّقة');
                }
            }

            echo PHP_EOL;
            line('', 'مطبَّقة: ' . count($applied) . '   معلّقة: ' . count($pending));

            if ($drift !== []) {
                echo PHP_EOL;
                line('⚠', 'انحراف — ملفات تغيّرت بعد تطبيقها:');
                foreach ($drift as $item) {
                    line(' ', $item);
                }
                exit(1);
            }
            break;

        case 'up':
            $done = $migrator->up($pretend);
            if ($done === []) {
                line('✓', 'لا شيء معلّق.');
                break;
            }
            foreach ($done as $name) {
                line($pretend ? '·' : '✓', ($pretend ? 'ستُطبَّق: ' : 'طُبِّقت: ') . $name);
            }
            break;

        case 'down':
            $steps = (int) ($argvList[2] ?? 1);
            $steps = $steps > 0 ? $steps : 1;
            $done  = $migrator->down($steps, $pretend);

            if ($done === []) {
                line('·', 'لا شيء للتراجع عنه.');
                break;
            }
            foreach ($done as $name) {
                line($pretend ? '·' : '✓', ($pretend ? 'سيُتراجَع: ' : 'تُراجِع عن: ') . $name);
            }
            break;

        case 'baseline':
            $count = $migrator->baseline();
            line('✓', 'سُجِّلت ' . $count . ' هجرة كمطبَّقة (بلا تنفيذ).');
            if ($count > 0) {
                line('', 'خطّ الأساس يحويها فعلاً — راجع تعليق Migrator::baseline.');
            }
            break;

        default:
            fwrite(STDERR, "أمر غير معروف: {$command}\n");
            fwrite(STDERR, "المتاح: status | up | down [n] | baseline | make <name>\n");
            exit(1);
    }
} catch (Throwable $e) {
    echo PHP_EOL;
    fwrite(STDERR, '  ✗ ' . $e->getMessage() . PHP_EOL . PHP_EOL);
    exit(1);
}

echo PHP_EOL;
exit(0);
