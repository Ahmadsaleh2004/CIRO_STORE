<?php

namespace App\Core;

/**
 * Log — one JSON line per operational event.
 *
 * ══════════════════════════════════════════════════════════════
 * The problem it solves
 * ══════════════════════════════════════════════════════════════
 *
 * `storage/php-error.log` mixed two kinds of thing with nothing in common:
 *
 *     [27-Aug-2026 13:02:35] PHP Fatal error: Cannot override final …
 *     [27-Aug-2026 13:03:40] [Cairo Store] 405: POST /about — allowed: GET
 *     [27-Aug-2026 16:24:07] [Cairo Store] 404: GET /definitely-not-a-page
 *
 * The first is a fault worth waking up for. The second and third are **the router
 * behaving correctly**: somebody asked for a page that does not exist, and that is
 * what ought to happen.
 *
 * And when the two look identical, sorting them apart takes a human reading — so
 * the file goes unread entirely. Which is precisely what happened: the checkout
 * page was completely broken from the baseline commit onward, with the log open in
 * front of everybody.
 *
 * ══════════════════════════════════════════════════════════════
 * Why JSON on a single line
 * ══════════════════════════════════════════════════════════════
 *
 * One line so `grep` and `tail` keep working exactly as they are, and JSON so the
 * sorting becomes mechanical rather than visual:
 *
 *     grep '"level":"error"' storage/php-error.log
 *     grep '"event":"http_404"' storage/php-error.log | wc -l
 *
 * And any log aggregator (or Sentry) reads this shape without a custom parser.
 *
 * ── Why error_log rather than a file of its own ──────────────
 *
 * Because `error_log` is where everything else already goes: PHP fatals, engine
 * warnings, and every existing `error_log` call in the models. A second log means
 * two files that must be read together to understand a single minute — which is
 * what makes logs go unread.
 *
 * The destination is the same, then; the shape is what changed.
 *
 * ══════════════════════════════════════════════════════════════
 * What must not go into the context
 * ══════════════════════════════════════════════════════════════
 *
 * ⚠️ Do not pass passwords, tokens or 2FA codes in `$context`. The log gets read,
 * copied and pasted into support tickets — it is the least protected place in any
 * system. The rule here: record **what happened**, not **what it happened with**.
 */
final class Log
{
    /** A routine event that deserves a trace, not attention. */
    public const INFO = 'info';

    /** Something unexpected, but the application handled it correctly. */
    public const WARNING = 'warning';

    /** A fault — this alone deserves an alert. */
    public const ERROR = 'error';

    /**
     * Writes a single log line.
     *
     * @param string               $level   One of the constants above
     * @param string               $event   A short, stable identifier (`http_404`)
     *                                      — it is what machines sort on, so do not
     *                                      phrase it as a sentence that can be reworded
     * @param array<string,scalar|null> $context Sortable facts
     */
    public static function write(string $level, string $event, array $context = []): void
    {
        $line = [
            'ts'    => date('c'),
            'level' => $level,
            'event' => $event,
        ];

        // The path and method are added automatically: almost every event needs them,
        // and leaving them to the caller means forgetting them in half the places.
        if (PHP_SAPI !== 'cli') {
            $line['method'] = $_SERVER['REQUEST_METHOD'] ?? '?';
            $line['path']   = strtok($_SERVER['REQUEST_URI'] ?? '?', '?') ?: '?';
        } else {
            $line['sapi'] = 'cli';
        }

        $encoded = json_encode(
            $line + $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // An encoding failure must not swallow the event: an incomplete log beats silence.
        error_log($encoded !== false ? $encoded : $level . ' ' . $event);
    }

    /** @param array<string,scalar|null> $context */
    public static function info(string $event, array $context = []): void
    {
        self::write(self::INFO, $event, $context);
    }

    /** @param array<string,scalar|null> $context */
    public static function warning(string $event, array $context = []): void
    {
        self::write(self::WARNING, $event, $context);
    }

    /** @param array<string,scalar|null> $context */
    public static function error(string $event, array $context = []): void
    {
        self::write(self::ERROR, $event, $context);
    }
}
