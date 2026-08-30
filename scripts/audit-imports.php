<?php

/**
 * scripts/audit-imports.php
 * Looks for classes used inside a namespaced file with neither an import nor a
 * qualification.
 *
 * Usage:
 *     php scripts/audit-imports.php
 *
 * Why?
 * Inside namespace App\Controllers, the unqualified name Database resolves to
 * App\Controllers\Database — not to App\Core\Database. The result is a fatal error that
 * appears only the moment that particular line runs, so it can lie dormant for months on a
 * rare path. That is exactly what happened in AdminSupportController::deleteMessage:
 * "delete a support message" always failed with a fatal error.
 *
 * The analysis goes through token_get_all rather than a regex, so names appearing inside
 * comments or strings are not reported (such as new Chart(...) in the embedded Chart.js
 * script, or a mention of AdminOrdersController in a docblock).
 *
 * It returns exit code 1 when any case is found.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$dirs = ['app/Controllers', 'app/Models', 'app/Core'];

/** PHP's built-in classes — they resolve globally as a fallback, so they need no import. */
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
 * Extracts from a file: its namespace, the imported names, and the classes genuinely used
 * (outside comments and strings).
 *
 * @return array{ns: string, imported: array<string, true>, declared: array<string, true>, used: array<string, int>}
 */
function analyse(string $file): array
{
    $tokens = token_get_all(file_get_contents($file) ?: '');
    $ns = '';
    $imported = [];
    $declared = [];
    $used = [];
    $mentioned = [];

    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }

        // namespace X\Y;
        if ($t[0] === T_NAMESPACE) {
            $buf = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) {
                    $buf .= $tokens[$j][1];
                }
            }
            $ns = trim($buf, '\\');
            continue;
        }

        // use A\B\C;  |  use A\B\C as D;
        if ($t[0] === T_USE) {
            $buf = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{' || $tokens[$j] === '(') {
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) {
                    $buf .= $tokens[$j][1] . ' ';
                }
            }
            $buf = trim($buf);
            if ($buf === '' || str_starts_with($buf, 'function ') || str_starts_with($buf, 'const ')) {
                continue;
            }
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
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $declared[$tokens[$j][1]] = true;
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                break;
            }
            continue;
        }

        // Any mention of a name starting with a capital: Foo:: | new Foo( | extends Foo |
        // implements Foo | Foo $x | : Foo | #[Foo(...)] | @var Foo
        if ($t[0] === T_STRING && preg_match('/^[A-Z]/', $t[1])) {
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            // Already qualified (\App\Core\X or App\Core\X)
            $qualified = is_array($prev)
                && in_array($prev[0], [T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
            if ($qualified) {
                $mentioned[$t[1]] = true;
                continue;
            }

            $mentioned[$t[1]] = true;

            $isStatic = is_array($next) && $next[0] === T_DOUBLE_COLON;
            $isNew    = is_array($prev) && $prev[0] === T_WHITESPACE
                        && is_array($tokens[$i - 2] ?? null) && $tokens[$i - 2][0] === T_NEW;

            if ($isStatic || $isNew) {
                $used[$t[1]] = $used[$t[1]] ?? $t[2];
            }
        }

        // Names inside comments do not count as use, but an @var/@param among them may be
        // the only reason a use exists — they count as a mention so we do not suggest
        // removing it.
        if (in_array($t[0], [T_DOC_COMMENT, T_COMMENT], true)) {
            if (preg_match_all('/\b([A-Z]\w+)\b/', $t[1], $cm)) {
                foreach ($cm[1] as $name) {
                    $mentioned[$name] = true;
                }
            }
        }
    }

    return [
        'ns' => $ns, 'imported' => $imported, 'declared' => $declared,
        'used' => $used, 'mentioned' => $mentioned,
    ];
}

$files = [];
foreach ($dirs as $d) {
    if (!is_dir("$root/$d")) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}
sort($files);

$problems = [];   // A class used without an import — a latent fatal error
$unused   = [];   // An import nothing refers to — untidiness only, not a fault

foreach ($files as $file) {
    $a = analyse($file);
    if ($a['ns'] === '') {
        continue;
    }
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file);

    foreach ($a['used'] as $cls => $line) {
        if (isset($a['imported'][$cls]) || isset($a['declared'][$cls])) {
            continue;
        }
        if (in_array($cls, BUILTIN, true)) {
            continue;
        }

        // An unqualified name resolves inside the current namespace — fine if the file exists
        $sameNsPath = $root . '/' . str_replace('\\', '/', $a['ns']) . '/' . $cls . '.php';
        if (is_file($sameNsPath)) {
            continue;
        }

        $problems[] = ['file' => $rel, 'line' => $line, 'class' => $cls, 'ns' => $a['ns']];
    }

    foreach (array_keys($a['imported']) as $cls) {
        if (!isset($a['mentioned'][$cls])) {
            $unused[] = ['file' => $rel, 'class' => $cls];
        }
    }
}

if ($problems) {
    echo "\n  Unimported classes — they resolve into the current namespace and cause a fatal error when run:\n\n";
    foreach ($problems as $p) {
        printf("  ✗ %s:%d\n      %s  →  PHP looks for it in %s\\%s\n\n", $p['file'], $p['line'], $p['class'], $p['ns'], $p['class']);
    }
} else {
    echo "\n  ✓ Every class used is imported, qualified, or in the same namespace\n";
}

if ($unused) {
    echo "\n  Unused imports (untidiness, not a fault):\n";
    foreach ($unused as $u) {
        printf("  · %-46s use ...\\%s;\n", $u['file'], $u['class']);
    }
}

echo "\n";
exit($problems ? 1 : 0);
