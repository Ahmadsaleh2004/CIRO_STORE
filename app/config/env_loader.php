<?php

/**
 * app/config/env_loader.php
 * A simple .env reader — line by line, with no PHP interpretation of brackets or
 * reserved words.
 *
 * Loaded from config.php before anything else, so every entry point
 * (public/index.php and every script under scripts/) gets the environment without
 * having to remember to call it.
 */

function loadEnv(string $path): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim(trim($value), "\"'");

        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

/**
 * Reads an environment variable, with a default.
 *
 * ⚠️ **An empty value is treated as absent**, so the default is returned. This is
 * deliberate rather than an oversight: a key written with no value (`APP_ENV=`) is
 * in reality a key nobody has filled in yet — an unfinished copy of
 * .env.example. Were "" returned instead, `env('APP_ENV', 'production')` would
 * yield an empty string that does not equal 'production', and debug mode would
 * open on a production server **silently**. Which is precisely what must not
 * happen.
 *
 * The single exception today is an empty DB_PASSWORD (the root password under
 * XAMPP), whose default is '' as well — so the outcome is the same either way and
 * nothing changes.
 *
 * The previous version carried an operator-precedence fault:
 *     return $_ENV[$key] ?? getenv($key) ?: $default;
 * it parses as `$_ENV[$key] ?? (getenv($key) ?: $default)` — meaning ?? returns
 * an empty "" as a valid value and skips the default entirely. The fault never
 * surfaced because nothing called the function at all (zero callers, verified).
 *
 * ── Why @template rather than mixed ──────────────────────────
 *
 * The return is not `mixed` but **exactly one of two things**: the variable's
 * string if it exists, or the default exactly as given. `mixed` throws away half
 * of that, so from the analyser's point of view `env('DB_PORT', 3306)` becomes
 * "something" when it is certainly `string|int`.
 *
 * The template preserves the default's type: a caller passing `null` receives
 * `string|null`, and one passing `'production'` receives `string` — with no
 * redundant checking at the call site.
 *
 * @template TDefault
 * @param  TDefault $default
 * @return string|TDefault
 */
function env(string $key, $default = null)
{
    // ?? already skips null, and getenv returns string|false — so there is no way
    // for $value to be null here. Checking for it was a condition that never held.
    $value = $_ENV[$key] ?? getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

/**
 * Reads an environment variable as a boolean.
 *
 * Why a function of its own? Because every .env value is a string, and
 * `(bool) "false"` is **true** in PHP. So APP_DEBUG=false would have opened debug
 * mode rather than closing it — the most dangerous kind of fault there is: one
 * that does the opposite of what its reader reads.
 */
function envBool(string $key, bool $default = false): bool
{
    $value = env($key);

    if ($value === null) {
        return $default;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}
