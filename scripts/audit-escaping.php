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

// المسار هو أوّل وسيط **ليس علماً**.
//
// كان `$argv[1]` مباشرةً، فكان `php audit-escaping.php --gate` يحاول
// فتح مجلد اسمه "--gate" وينهار برسالة لا علاقة لها بالسبب. والاستعمال
// الموثَّق في رأس هذا الملف يفرض كتابة المسار قبل كل علم — شرطٌ لا
// يذكره أحد ولا يخطر لمن يكتب العلم وحده.
$root = dirname(__DIR__) . '/app/views';
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        $root = $arg;
        break;
    }
}
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
    // ── دوال المشروع التي تُصدر HTML بطبعها ──────────────────────
    //
    // هذه لا «تحتاج هروباً» بل **يفسدها** الهروب: تهريب ناتج
    // vendorJs() يطبع `&lt;script ...&gt;` نصّاً على الصفحة بدل أن
    // يحمّل المكتبة. فتصنيفها SAFE ليس تساهلاً بل تصحيح تصنيف.
    //
    // والقائمة تُحدَّث عند إضافة أي مولّد وسوم جديد — وإلا امتلأ
    // التقرير بإيجابيات كاذبة حتى يصير عديم القيمة، وهو ما يقتل أي
    // أداة تدقيق: ضجيجٌ يُدرَّب القارئ على تجاهله.
    //
    // ⚠️ ما يدخل هنا يجب أن يكون مولِّدَ وسمٍ يبنيه المشروع من قيم
    // يملكها هو — لا دالةً تمرّر مدخلاً خارجياً.
    $htmlEmitters = 'themeBootScript|cssBundle|pageCss|jsBundle|jsTag'
                  . '|vendorJs|vendorCss|pageData';
    if (preg_match('/^(' . $htmlEmitters . ')\(/', $e)) {
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

    // نصّ مُهرَّب ثم مُحوَّل أسطرُه إلى <br>. الترتيب هو ما يجعله آمناً:
    // htmlspecialchars أولاً على المدخل، ثم nl2br تضيف وسومها هي فوق
    // نصٍّ لم يعد يحمل وسوماً. (العكس — nl2br ثم تهريب — يطبع <br>
    // نصّاً، وهو خطأ شائع.)
    if (str_starts_with($e, 'nl2br(htmlspecialchars(')) {
        return true;
    }

    // دوال المشروع التي تُرجع رمزاً حرفياً من خريطة داخلية.
    if (preg_match('/^(categoryEmoji|stockBadge|productTag)\(/', $e)) {
        return true;
    }

    // تعبير شرطي طرفاه: صبٌّ صريح لعدد، ونصّ حرفي.
    // مثال: $log['target_id'] ? '#' . (int)$log['target_id'] : '—'
    if (
        str_contains($e, '?')
        && preg_match('/\(int\)/', $e)
        && preg_match("/:\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\s*$/", $e)
    ) {
        return true;
    }

    // ── فتحات HTML التي يملؤها الكنترولر ────────────────────────
    //
    // هذه **HTML مقصود** لا نصّ: الكنترولر يمرّر وسم <link> أو <script>
    // كاملاً. تهريبها يطبع الوسم نصّاً على الصفحة.
    //
    // ⚠️ وهي أيضاً آخر مكان يبقى فيه حقن HTML ممكناً في الـviews. أمانها
    // مشروط بشرط واحد: **ألّا يبني كنترولر قيمتها من مدخل مستخدم**.
    // اليوم كلها سلاسل حرفية مبنية حول URLROOT (مفحوص). من يضيف قيمة
    // جديدة هنا مسؤول عن التحقّق من ذلك.
    if (preg_match('/^\$(extraHead|extraScripts|bareHead|bareScripts)\s*\?\?\s*\x27\x27$/', $e)) {
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

            // ── إعفاء صريح مع سببه ──────────────────────────────
            //
            // بعض الحالات لا يستطيع أي تحليل ثابت أن يحكم عليها: متغيّر
            // حلقة مربوط بمصفوفة نصوص حرفية معرَّفة في الـview نفسه
            // مثلاً. المحلّل يرى `$label` ولا يرى من أين جاء.
            //
            // الحلّ نفس ما يفعله المشروع مع semgrep: إعفاء **مكتوب في
            // موضعه ومعه سببه**، لا استثناء في ملف إعداد بعيد لا يقرأه
            // من يعدّل السطر.
            //
            //     ضع في الـview تعليق PHP يحمل:
            //         @escaping-safe: نصوص حرفية معرَّفة في هذا الملف
            //     على السطر نفسه أو السطر الذي قبله.
            //
            // ⚠️ السبب إلزامي. الإعفاء بلا سبب يُعامَل كأنه غير موجود —
            // فمن يريد إسكات الأداة عليه أن يكتب لماذا، ومن يقرأ بعده
            // يستطيع أن يحكم على ما كُتب.
            $previous = $lines[$i - 1] ?? '';
            $exempt   = preg_match('/@escaping-safe:\s*\S/u', $line)
                     || preg_match('/@escaping-safe:\s*\S/u', $previous);

            if (isSafeExpr($expr) || $exempt) {
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

// ══════════════════════════════════════════════════════════════
// بوّابة: --gate
// ══════════════════════════════════════════════════════════════
//
// ── لماذا بوّابة أصلاً ─────────────────────────────────────────
//
// كانت هذه الأداة **تقريراً**: تطبع أرقاماً ويقرؤها من يتذكّر
// تشغيلها. وتقرير لا يُفشل شيئاً يتحوّل مع الوقت إلى رقم يكبر بهدوء —
// كان 36 موضعاً غير مُهرَّب حين بدأت هذه المرحلة، ولم يكن أحد يعرف
// أيّها جديد وأيّها مراجَع سلفاً. وهذا هو الفرق الذي يقيمه هذا المشروع
// في كل مرحلة: «مفروض آلياً لا بالانضباط الشخصي».
//
// ── لماذا NEEDS عند صفر وATTR/URL بسقف ────────────────────────
//
// NEEDS هو الإخراج في **جسم** الصفحة — حيث `<script>` محقون ينفَّذ
// مباشرة. صُفِّرت كلها (مُهرَّبة، أو مُعفاة بسبب مكتوب في موضعها).
// فالصفر هنا حالة قائمة يُحرَس بقاؤها، لا هدف بعيد.
//
// وATTR وURL أضيق خطراً (تحتاج كسر اقتباس أو مخطّط javascript:)
// وأكثر عدداً، وتصفيرها عمل مرحلة مستقلّة. فالسقف يمنع نموّها اليوم
// ريثما تُعالَج — وهو أصدق من ادّعاء تغطية لا توجد.
//
// ⚠️ السقف يُخفَّض عند كل إصلاح، ولا يُرفع أبداً. رفعه يعني أن البوّابة
// تتبع الكود بدل أن يتبعها.
const GATE_LIMITS = [
    'NEEDS' => 0,
    'ATTR'  => 27,
    'URL'   => 23,
];

if (in_array('--gate', $argv, true)) {
    $failed = false;

    foreach (GATE_LIMITS as $kind => $limit) {
        $actual = $counts[$kind] ?? 0;

        if ($actual > $limit) {
            fwrite(STDERR, sprintf(
                "  ✗ %s: %d موضعاً — السقف %d\n",
                $kind,
                $actual,
                $limit
            ));
            $failed = true;
            continue;
        }

        if ($actual < $limit) {
            // نقصٌ عن السقف خبر جيّد، لكنه يعني أن السقف صار قديماً.
            // التذكير هنا لا في المستقبل: من يُصلح موضعاً اليوم هو من
            // يعرف أنه أصلحه.
            fwrite(STDOUT, sprintf(
                "  ↓ %s: %d — أنقص من السقف (%d). خفّض GATE_LIMITS.\n",
                $kind,
                $actual,
                $limit
            ));
        }
    }

    if ($failed) {
        fwrite(STDERR, "\n  شغّل: composer audit:escaping -- app/views --list NEEDS\n\n");
        exit(1);
    }

    echo "  ✓ الهروب ضمن الحدود (NEEDS = 0)\n";
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
