<?php
/**
 * scripts/audit-imports.php
 * يبحث عن كلاسات مستعملة داخل ملف ذي namespace بلا استيراد وبلا تأهيل.
 *
 * الاستخدام:
 *     php scripts/audit-imports.php
 *
 * لماذا؟
 * داخل namespace App\Controllers، الاسم غير المؤهَّل Database يُحلّ إلى
 * App\Controllers\Database — لا إلى App\Core\Database. النتيجة خطأ Fatal
 * لا يظهر إلا لحظة تنفيذ ذلك السطر بالذات، فقد يبقى كامناً شهوراً في
 * مسار نادر. هذا ما حدث فعلاً في AdminSupportController::deleteMessage:
 * "حذف رسالة دعم" كان يفشل دائماً بـFatal error.
 *
 * التحليل يمرّ عبر token_get_all لا عبر regex، فلا يُبلَّغ عن أسماء واردة
 * داخل التعليقات أو النصوص (مثل new Chart(...) في سكربت Chart.js
 * المضمّن، أو ذكر AdminOrdersController في docblock).
 *
 * يُرجِع exit code 1 عند وجود أي حالة.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$dirs = ['app/Controllers', 'app/Models', 'app/Core'];

/** كلاسات PHP المدمجة — تُحلّ عالمياً كـfallback فلا تحتاج استيراداً. */
const BUILTIN = [
    'PDO', 'PDOException', 'PDOStatement', 'Exception', 'Throwable', 'Error',
    'TypeError', 'ValueError', 'ArgumentCountError', 'JsonException',
    'InvalidArgumentException', 'RuntimeException', 'LogicException',
    'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval',
    'ArrayObject', 'Closure', 'Generator', 'stdClass', 'SplFileInfo',
    'RecursiveIteratorIterator', 'RecursiveDirectoryIterator', 'FilesystemIterator',
    'ReflectionClass', 'IntlDateFormatter', 'NumberFormatter', 'ZipArchive',
];

/**
 * يستخرج من ملف: النيسبيس، الأسماء المستوردة، والكلاسات المستعملة
 * استعمالاً حقيقياً (خارج التعليقات والنصوص).
 */
function analyse(string $file): array
{
    $tokens = token_get_all(file_get_contents($file) ?: '');
    $ns = '';
    $imported = [];
    $declared = [];
    $used = [];

    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) continue;

        // namespace X\Y;
        if ($t[0] === T_NAMESPACE) {
            $buf = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{') break;
                if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) $buf .= $tokens[$j][1];
            }
            $ns = trim($buf, '\\');
            continue;
        }

        // use A\B\C;  |  use A\B\C as D;
        if ($t[0] === T_USE) {
            $buf = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{' || $tokens[$j] === '(') break;
                if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) $buf .= $tokens[$j][1] . ' ';
            }
            $buf = trim($buf);
            if ($buf === '' || str_starts_with($buf, 'function ') || str_starts_with($buf, 'const ')) continue;
            if (preg_match('/\bas\s+(\w+)$/i', $buf, $m)) {
                $imported[$m[1]] = true;
            } else {
                $parts = explode('\\', str_replace(' ', '', $buf));
                $imported[end($parts)] = true;
            }
            continue;
        }

        // class X | interface X | trait X | enum X
        if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $declared[$tokens[$j][1]] = true; break; }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
                break;
            }
            continue;
        }

        // Foo::  |  new Foo(
        if ($t[0] === T_STRING && preg_match('/^[A-Z]/', $t[1])) {
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            // مؤهَّل بالفعل (\App\Core\X أو App\Core\X)
            $qualified = is_array($prev)
                && in_array($prev[0], [T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
            if ($qualified) continue;

            $isStatic = is_array($next) && $next[0] === T_DOUBLE_COLON;
            $isNew    = is_array($prev) && $prev[0] === T_WHITESPACE
                        && is_array($tokens[$i - 2] ?? null) && $tokens[$i - 2][0] === T_NEW;

            if ($isStatic || $isNew) {
                $used[$t[1]] = $used[$t[1]] ?? $t[2];
            }
        }
    }

    return ['ns' => $ns, 'imported' => $imported, 'declared' => $declared, 'used' => $used];
}

$files = [];
foreach ($dirs as $d) {
    if (!is_dir("$root/$d")) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') $files[] = $f->getPathname();
    }
}
sort($files);

$problems = [];
foreach ($files as $file) {
    $a = analyse($file);
    if ($a['ns'] === '') continue;

    foreach ($a['used'] as $cls => $line) {
        if (isset($a['imported'][$cls]) || isset($a['declared'][$cls])) continue;
        if (in_array($cls, BUILTIN, true)) continue;

        // اسم غير مؤهَّل يُحلّ داخل النيسبيس الحالي — سليم إن كان الملف موجوداً
        $sameNsPath = $root . '/' . str_replace('\\', '/', $a['ns']) . '/' . $cls . '.php';
        if (is_file($sameNsPath)) continue;

        $problems[] = [
            'file'  => str_replace($root . DIRECTORY_SEPARATOR, '', $file),
            'line'  => $line,
            'class' => $cls,
            'ns'    => $a['ns'],
        ];
    }
}

if (!$problems) {
    echo "\n  ✓ كل الكلاسات المستعملة مستوردة أو مؤهَّلة أو في نفس النيسبيس\n\n";
    exit(0);
}

echo "\n  كلاسات غير مستوردة — تُحلّ إلى النيسبيس الحالي فتُسبّب Fatal عند التنفيذ:\n\n";
foreach ($problems as $p) {
    printf("  ✗ %s:%d\n      %s  →  يبحث عنه PHP في %s\\%s\n\n", $p['file'], $p['line'], $p['class'], $p['ns'], $p['class']);
}
exit(1);
