<?php

/**
 * scripts/audit-escaping.php
 * Extracts every `<?= ... ?>` from the view files and classifies them by their output
 * context and their need for escaping.
 *
 * Usage:
 *     php scripts/audit-escaping.php                ← a summary plus the worst files
 *     php scripts/audit-escaping.php app/views --list NEEDS
 *     php scripts/audit-escaping.php app/views --list ATTR
 *
 * The categories:
 *   SAFE   — already escaped, or a number/constant/literal that cannot carry HTML
 *   NEEDS  — plain text in the page's body that may carry input from the database
 *   ATTR   — inside an HTML attribute (the danger: breaking the quote and escaping it)
 *   URL    — inside href/src/action
 *   JS     — inside a <script> block
 *
 * ⚠️ The tool assists, it does not rule: its classification is approximate and needs a
 * human review. The non-SAFE categories remaining in this project were reviewed by hand and
 * shown to be literal arrays inside the view itself, or CSS classes computed from a
 * match/ternary, or numbers, or deliberate HTML.
 */

declare(strict_types=1);

// The path is the first argument that is **not a flag**.
//
// It used to be `$argv[1]` directly, so `php audit-escaping.php --gate` tried to open a
// directory named "--gate" and fell over with a message unrelated to the cause. And the
// usage documented at the top of this file requires writing the path before every flag —
// a condition nobody states and nobody thinks of when they type the flag alone.
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

/** Functions and patterns that make the output safe in itself. */
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

    // An explicit numeric cast, or a numeric literal
    if (preg_match('/^\(\s*(int|float|bool)\s*\)/', $e)) {
        return true;
    }
    if (preg_match('/^\d+$/', $e)) {
        return true;
    }

    // The project's constants and our own tag-generating functions
    if (preg_match('/^(URLROOT|SITENAME|BASE_URL)$/', $e)) {
        return true;
    }
    // ── The project's functions that emit HTML by nature ─────────
    //
    // These do not "need escaping" — escaping **breaks** them: escaping the output of
    // vendorJs() prints `&lt;script ...&gt;` as text on the page instead of loading the
    // library. So classifying them SAFE is not leniency but a corrected classification.
    //
    // And the list is updated whenever a new tag generator is added — otherwise the report
    // fills with false positives until it is worthless, which is what kills any audit tool:
    // noise that trains its reader to ignore it.
    //
    // ⚠️ What goes in here must be a tag generator the project builds from values it owns
    // — not a function that passes external input through.
    $htmlEmitters = 'themeBootScript|cssBundle|pageCss|jsBundle|jsTag'
                  . '|vendorJs|vendorCss|pageData';
    if (preg_match('/^(' . $htmlEmitters . ')\(/', $e)) {
        return true;
    }

    // A conditional whose two branches are both literals — no user input in it.
    // For example: $x === 'y' ? 'selected' : ''
    if (preg_match("/\?\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\s*:\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\s*$/", $e)) {
        return true;
    }

    // A conditional whose branches are escaped or literal (the "value or dash" pattern)
    if (
        str_contains($e, '?')
        && preg_match_all('/htmlspecialchars\(/', $e) >= 1
        && preg_match("/:\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\s*$/", $e)
    ) {
        return true;
    }

    // Text escaped and then line-broken into <br>. The order is what makes it safe:
    // htmlspecialchars first over the input, then nl2br adds its own tags over text that no
    // longer carries any. (The reverse — nl2br then escaping — prints <br> as text, a
    // common mistake.)
    if (str_starts_with($e, 'nl2br(htmlspecialchars(')) {
        return true;
    }

    // The project's functions that return a literal symbol from an internal map.
    if (preg_match('/^(categoryEmoji|stockBadge|productTag)\(/', $e)) {
        return true;
    }

    // A conditional whose branches are an explicit numeric cast and a literal.
    // For example: $log['target_id'] ? '#' . (int)$log['target_id'] : '—'
    if (
        str_contains($e, '?')
        && preg_match('/\(int\)/', $e)
        && preg_match("/:\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\s*$/", $e)
    ) {
        return true;
    }

    // ── The HTML slots the controller fills ─────────────────────
    //
    // These are **deliberate HTML**, not text: the controller passes a complete <link> or
    // <script> tag. Escaping them prints the tag as text on the page.
    //
    // ⚠️ They are also the last place where HTML injection remains possible in the views.
    // Their safety rests on one condition: **that no controller builds their value from
    // user input**. Today they are all literal strings built around URLROOT (verified).
    // Whoever adds a new value here is responsible for checking that.
    if (preg_match('/^\$(extraHead|extraScripts|bareHead|bareScripts)\s*\?\?\s*\x27\x27$/', $e)) {
        return true;
    }

    // Counter variables known in this project (all of them ints from the controller)
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

    // Where the <script> blocks are
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

            // ── An explicit exemption, with its reason ──────────
            //
            // Some cases no static analysis can rule on: a loop variable bound to an array
            // of literals defined in the view itself, for instance. The analyser sees
            // `$label` and cannot see where it came from.
            //
            // The answer is what the project does with semgrep: an exemption **written
            // where it applies, carrying its reason** — not an exclusion in a distant
            // configuration file that whoever edits the line never reads.
            //
            //     Put a PHP comment in the view carrying:
            //         @escaping-safe: literals defined in this file
            //     on the same line or the line before it.
            //
            // ⚠️ The reason is mandatory. An exemption without one is treated as absent —
            // anyone wanting to silence the tool has to write why, and whoever reads it
            // afterwards can judge what was written.
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
// The gate: --gate
// ══════════════════════════════════════════════════════════════
//
// ── Why a gate at all ─────────────────────────────────────────
//
// This tool used to be **a report**: it printed numbers that were read by whoever
// remembered to run it. And a report that fails nothing turns, over time, into a number
// that grows quietly — there were 36 unescaped sites when this phase began, and nobody knew
// which were new and which had already been reviewed. That is the distinction this project
// draws at every phase: "enforced automatically, not by personal discipline".
//
// ── Why NEEDS at zero and ATTR/URL under a ceiling ────────────
//
// NEEDS is output in the page's **body** — where an injected `<script>` runs immediately.
// All of them were driven to zero (escaped, or exempted with a reason written in place).
// So the zero here is a standing state to be guarded, not a distant goal.
//
// ATTR and URL are narrower dangers (they need a broken quote or a javascript: scheme) and
// more numerous, and zeroing them is a phase of its own. So the ceiling stops them growing
// today until they are dealt with — which is more honest than claiming coverage that does
// not exist.
//
// ⚠️ The ceiling is lowered with every fix and never raised. Raising it means the gate
// follows the code rather than the code following the gate.
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
                "  ✗ %s: %d sites — the ceiling is %d\n",
                $kind,
                $actual,
                $limit
            ));
            $failed = true;
            continue;
        }

        if ($actual < $limit) {
            // Falling under the ceiling is good news, but it means the ceiling has gone
            // stale. The reminder belongs here and not in the future: whoever fixes a site
            // today is the one who knows they fixed it.
            fwrite(STDOUT, sprintf(
                "  ↓ %s: %d — under the ceiling (%d). Lower GATE_LIMITS.\n",
                $kind,
                $actual,
                $limit
            ));
        }
    }

    if ($failed) {
        fwrite(STDERR, "\n  Run: composer audit:escaping -- app/views --list NEEDS\n\n");
        exit(1);
    }

    echo "  ✓ Escaping is within its limits (NEEDS = 0)\n";
    exit(0);
}

echo "\n  Classification of the <?= ?> outputs in the views\n  " . str_repeat('-', 56) . "\n";
foreach ($counts as $k => $v) {
    printf("  %-8s %5d\n", $k, $v);
}
printf("  %s\n  %-8s %5d\n\n", str_repeat('-', 56), 'Total', count($rows));

// The files needing the most attention
$byFile = [];
foreach ($rows as $r) {
    if ($r['kind'] === 'SAFE') {
        continue;
    }
    $byFile[$r['file']] = ($byFile[$r['file']] ?? 0) + 1;
}
arsort($byFile);
echo "  The files most in need of review\n  " . str_repeat('-', 56) . "\n";
foreach (array_slice($byFile, 0, 15, true) as $f => $n) {
    printf("  %-50s %4d\n", str_replace('app/views/', '', $f), $n);
}
echo "\n";
