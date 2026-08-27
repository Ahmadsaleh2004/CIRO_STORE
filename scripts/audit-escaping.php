<?php

/**
 * scripts/audit-escaping.php
 * يستخرج كل `<?= ... ?>` من ملفات الـviews ويصنّفها حسب سياق الإخراج
 * وحاجتها للهروب (escaping).
 *
 * الاستخدام:
 *     php scripts/audit-escaping.php                ← ملخّص + أكثر الملفات
 *     php scripts/audit-escaping.php app/views --list NEEDS
 *     php scripts/audit-escaping.php app/views --list ATTR
 *
 * الفئات:
 *   SAFE   — مُهرَّبة أصلاً، أو عدد/ثابت/نص حرفي لا يحتمل HTML
 *   NEEDS  — نص عادي في جسم الصفحة قد يحمل مدخلاً من قاعدة البيانات
 *   ATTR   — داخل سمة HTML (الخطر: كسر الاقتباس والخروج منها)
 *   URL    — داخل href/src/action
 *   JS     — داخل كتلة <script>
 *
 * ⚠️ الأداة مساعِدة لا حَكَم: تصنيفها تقريبي ويحتاج مراجعة بشرية.
 * الفئات غير الـSAFE المتبقية في هذا المشروع رُوجعت يدوياً وثبت أنها
 * مصفوفات حرفية داخل الـview نفسه، أو كلاسات CSS محسوبة من match/ternary،
 * أو أعداد، أو HTML مقصود — راجع تقرير الهروب الأمني.
 */

declare(strict_types=1);

$root  = $argv[1] ?? dirname(__DIR__) . '/app/views';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $files[] = $f->getPathname();
    }
}
sort($files);

/** دوال/أنماط تجعل المخرَج آمناً بذاته. */
function isSafeExpr(string $e): bool
{
    $e = trim($e);

    $safeFns = ['htmlspecialchars', 'htmlentities', 'json_encode', 'urlencode',
                'rawurlencode', 'number_format', 'count', 'intval', 'floatval',
                'round', 'date', 'http_build_query', 'array_sum'];
    foreach ($safeFns as $fn) {
        if (str_starts_with($e, $fn . '(')) {
            return true;
        }
    }

    // صبّ صريح لعدد، أو ثابت رقمي
    if (preg_match('/^\(\s*(int|float|bool)\s*\)/', $e)) {
        return true;
    }
    if (preg_match('/^\d+$/', $e)) {
        return true;
    }

    // ثوابت المشروع ودوال توليد الوسوم الخاصة بنا
    if (preg_match('/^(URLROOT|SITENAME|BASE_URL)$/', $e)) {
        return true;
    }
    if (preg_match('/^(themeBootScript|cssBundle|pageCss)\(/', $e)) {
        return true;
    }

    // تعبير شرطي لا يُنتج إلا نصوصاً حرفية على الطرفين — لا مدخل مستخدم فيه.
    // مثال: $x === 'y' ? 'selected' : ''
    if (preg_match("/\?\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\s*:\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\s*$/", $e)) {
        return true;
    }

    // تعبير شرطي طرفاه مُهرَّبان أو نصّان حرفيان (نمط "القيمة أو شرطة")
    if (
        str_contains($e, '?')
        && preg_match_all('/htmlspecialchars\(/', $e) >= 1
        && preg_match("/:\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\s*$/", $e)
    ) {
        return true;
    }

    // متغيّرات عدّاد معروفة في هذا المشروع (كلها ints من الكنترولر)
    $intVars = 'i|p|p2|sc|stock|totalPages|currentPage|activeCount|strikesCount'
             . '|pendingOrders|newMessages|newOrders|newUsersWeek|totalStrikes'
             . '|activeUsersCount|notActiveUsersCount|blockedUsersCount|totalMessages|total';
    if (preg_match('/^\$(' . $intVars . ')$/', $e)) {
        return true;
    }
    if (preg_match('/^\$\w+\s*\+\s*\d+$/', $e)) {
        return true;
    }

    return false;
}

$rows = [];
foreach ($files as $file) {
    $src   = file_get_contents($file);
    $lines = explode("\n", $src);

    // مواضع كتل <script>
    $inScript = [];
    $depth = 0;
    foreach ($lines as $i => $l) {
        if (preg_match('/<script(?![^>]*\bsrc=)/i', $l)) {
            $depth++;
        }
        $inScript[$i] = $depth > 0;
        if (stripos($l, '</script>') !== false && $depth > 0) {
            $depth--;
        }
    }

    foreach ($lines as $i => $line) {
        if (!preg_match_all('/<\?=(.+?)\?>/', $line, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($m[1] as $k => $capture) {
            $expr   = trim($capture[0]);
            $offset = $m[0][$k][1];
            $before = substr($line, 0, $offset);

            if (isSafeExpr($expr)) {
                $kind = 'SAFE';
            } elseif ($inScript[$i]) {
                $kind = 'JS';
            } elseif (preg_match('/\b(href|src|action)\s*=\s*["\'][^"\']*$/i', $before)) {
                $kind = 'URL';
            } elseif (preg_match('/\w+\s*=\s*["\'][^"\']*$/', $before)) {
                $kind = 'ATTR';
            } else {
                $kind = 'NEEDS';
            }

            $rows[] = [
                'file' => str_replace('\\', '/', $file),
                'line' => $i + 1,
                'kind' => $kind,
                'expr' => $expr,
            ];
        }
    }
}

$counts = array_count_values(array_column($rows, 'kind'));
ksort($counts);

if (in_array('--list', $argv, true)) {
    $want = $argv[array_search('--list', $argv, true) + 1] ?? 'NEEDS';
    foreach ($rows as $r) {
        if ($r['kind'] !== $want) {
            continue;
        }
        printf("%s:%d\n    %s\n", $r['file'], $r['line'], $r['expr']);
    }
    exit(0);
}

echo "\n  تصنيف مخرجات <?= ?> في الـviews\n  " . str_repeat('-', 56) . "\n";
foreach ($counts as $k => $v) {
    printf("  %-8s %5d\n", $k, $v);
}
printf("  %s\n  %-8s %5d\n\n", str_repeat('-', 56), 'المجموع', count($rows));

// أكثر الملفات احتياجاً
$byFile = [];
foreach ($rows as $r) {
    if ($r['kind'] === 'SAFE') {
        continue;
    }
    $byFile[$r['file']] = ($byFile[$r['file']] ?? 0) + 1;
}
arsort($byFile);
echo "  الملفات الأكثر احتياجاً للمراجعة\n  " . str_repeat('-', 56) . "\n";
foreach (array_slice($byFile, 0, 15, true) as $f => $n) {
    printf("  %-50s %4d\n", str_replace('app/views/', '', $f), $n);
}
echo "\n";
