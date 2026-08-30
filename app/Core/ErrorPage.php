<?php

namespace App\Core;

/**
 * ErrorPage — the single renderer for error pages.
 *
 * This class exists because the project had **three** different ways of answering
 * "page not found", and not one of them was an actual page:
 *
 *   1. Controller::view()  → die("View file [full server path] not found!")
 *   2. AdminAuthController → echo "View not found: {$viewPath}"
 *   3. Router::dispatch()  → echo "404 - Page Not Found"
 *
 * The first and second went away in phase 4, and the third here. Now there is one
 * path: a correct 404 status · a complete HTML page · and the diagnostic details to
 * the PHP error log and nowhere else.
 *
 * Why a class of its own rather than a method on Controller? Because Router does
 * not extend Controller and should not — putting the renderer on the parent class
 * would have forced one of them to keep its own copy, which is precisely what this
 * solves.
 */
final class ErrorPage
{
    /**
     * Sends a complete 404 page and halts.
     *
     * @param string|null $logDetail A diagnostic detail for the developer — it goes
     *        to the log alone and is never printed in the browser. Leaking server paths
     *        or file names to a visitor discloses the project's structure for nothing.
     */
    public static function notFound(?string $logDetail = null): never
    {
        if ($logDetail !== null && $logDetail !== '') {
            Log::info('http_404', ['detail' => $logDetail]);
        }

        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
        }

        // Fallback: if the 404 file itself is missing, print a small inline page rather
        // than calling view() — calling it from inside a "missing view" handler is a
        // potential infinite loop.
        $page = APPROOT . '/views/errors/404.php';
        if (is_file($page)) {
            require $page;
        } else {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
               . '<title>404 — Page Not Found</title></head><body>'
               . '<h1>404</h1><p>The page you requested could not be found.</p>'
               . '</body></html>';
        }

        exit;
    }

    /**
     * Sends a complete 403 page and halts.
     *
     * It exists for the same reason as notFound(): the refusal response in
     * BackupController and AdminManageAdminsController used to be
     * `http_response_code(403); die('Unauthorized — Root admin only (ID=1)')`
     * — raw text with no <head>, no layout and no way back. And it discloses the
     * permission rule to the visitor for nothing; the message shown is now generic and
     * the detail goes to the log.
     *
     * @param string|null $logDetail A diagnostic detail — to the log alone, never
     *        printed in the browser.
     * @param string|null $backUrl   Where the back button leads. It defaults to the site
     *        root; the admin pages pass their own so an admin is not dropped into the
     *        store front.
     * @param string|null $backLabel The back button's text.
     */
    public static function forbidden(
        ?string $logDetail = null,
        ?string $backUrl = null,
        ?string $backLabel = null
    ): never {
        if ($logDetail !== null && $logDetail !== '') {
            Log::warning('http_403', ['detail' => $logDetail]);
        }

        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
        }

        // Both available to the view.
        //
        // ⚠️ The destination is confined to the site root deliberately. Every caller
        // today passes a constant built on URLROOT, but the signature accepts a string —
        // and a later caller passing user input would have planted a `javascript:` in
        // the href. htmlspecialchars in the view escapes characters; it does not stop a
        // hostile scheme.
        $backUrl   = $backUrl   ?? URLROOT . '/';
        if (!str_starts_with($backUrl, URLROOT)) {
            Log::warning('unsafe_back_url', ['back_url' => $backUrl]);
            $backUrl = URLROOT . '/';
        }
        $backLabel = $backLabel ?? 'Back to home';

        // The same fallback as notFound(): if the page file is missing, print an inline
        // replacement rather than calling view() — a potential loop inside an error
        // handler.
        $page = APPROOT . '/views/errors/403.php';
        if (is_file($page)) {
            require $page;
        } else {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
               . '<title>403 — Access Denied</title></head><body>'
               . '<h1>403</h1><p>You do not have permission to access this page.</p>'
               . '<p><a href="' . htmlspecialchars($backUrl) . '">'
               . htmlspecialchars($backLabel) . '</a></p>'
               . '</body></html>';
        }

        exit;
    }

    /**
     * Sends a 405 "method not allowed" and halts.
     *
     * It exists because the router used to answer **404** to a POST at a path
     * registered for GET alone. That is a misleading lie: the page exists, and it is
     * the method that is wrong. A 404 tells the developer "check the path spelling", so
     * they look in the wrong place, while a 405 points straight at the cause.
     *
     * The Allow header is not decoration: the standard (RFC 9110 §15.5.6) requires it
     * with every 405, and API tools read it to learn what is permitted.
     *
     * @param list<string> $allowed The methods actually registered for this path.
     */
    public static function methodNotAllowed(array $allowed, ?string $logDetail = null): never
    {
        if ($logDetail !== null && $logDetail !== '') {
            Log::info('http_405', ['detail' => $logDetail, 'allowed' => implode(',', $allowed)]);
        }

        if (!headers_sent()) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $allowed));
            header('Content-Type: text/html; charset=utf-8');
        }

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>405 — Method Not Allowed</title></head>'
           . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:60px">'
           . '<h1>405</h1><p>The request method is not supported for this resource.</p>'
           . '<p><a href="' . htmlspecialchars(URLROOT) . '/">Back to home</a></p>'
           . '</body></html>';

        exit;
    }

    /**
     * Sends a 429 "too many requests" and halts.
     *
     * Called from Middleware::throttle alone. The message shown states neither the
     * limit nor how many attempts remain — anyone who knows the number tunes their rate
     * to sit just under it, and the throttle becomes a guide for the attacker rather
     * than a barrier.
     *
     * Retry-After is not decoration here either: RFC 9110 §15.5.28 recommends it with
     * every 429, and it is what an honest client reads to learn when to come back
     * instead of guessing. It is sent in seconds because the numeric form is what
     * libraries understand.
     *
     * @param int         $retryAfterSeconds How many seconds before a retry is worthwhile.
     * @param string|null $logDetail A diagnostic detail — to the log alone.
     */
    public static function tooManyRequests(int $retryAfterSeconds, ?string $logDetail = null): never
    {
        if ($logDetail !== null && $logDetail !== '') {
            Log::warning('http_429', ['detail' => $logDetail]);
        }

        if (!headers_sent()) {
            http_response_code(429);
            header('Retry-After: ' . max(1, $retryAfterSeconds));
            header('Content-Type: text/html; charset=utf-8');
            // A throttle response must not be cached anywhere — otherwise the refusal
            // gets served to somebody it was not meant for.
            header('Cache-Control: no-store');
        }

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>429 — Too Many Requests</title></head>'
           . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:60px">'
           . '<h1>429</h1><p>Too many requests in a short time. Please wait a moment and try again.</p>'
           . '<p><a href="' . htmlspecialchars(URLROOT) . '/">Back to home</a></p>'
           . '</body></html>';

        exit;
    }

    /**
     * Sends a complete 500 page and halts.
     *
     * It exists for the same reason as notFound(). A failed database connection used
     * to be handled in Database::__construct with
     *     die("Database connection error: " . $e->getMessage())
     * and a PDO message carries **the host name, the database name and the user name**
     * verbatim. Which means the first connection error in production handed the visitor
     * half the credentials, with no page and no correct status code (die returns 200).
     *
     * Three differences from its siblings:
     *
     *   1. **CLI mode**: the scripts under scripts/ run in a terminal without
     *      composer's autoload — so printing an HTML page there is meaningless, and
     *      calling the layout functions is a fatal error. The CLI branch prints plain
     *      text to STDERR and exits with code 1 so any calling script can detect it.
     *
     *   2. **The inline fallback is wider**: this page may be called while the
     *      database is down, so it depends on nothing that reads from it. head-bare.php
     *      does not touch the database (verified), but the fallback stays entirely
     *      self-contained — no external CSS and no helper functions.
     *
     *   3. **503 rather than 500 on a connection failure**: the service is
     *      temporarily unavailable, not "a server error". The difference matters to
     *      search engines and monitoring tools.
     *
     * @param string|null $logDetail A diagnostic detail — to the log alone, never
     *        printed in the browser.
     * @param int         $status    503 (temporarily unavailable) or 500.
     */
    public static function serverError(?string $logDetail = null, int $status = 500): never
    {
        if ($logDetail !== null && $logDetail !== '') {
            Log::error('http_' . $status, ['detail' => $logDetail]);
        }

        // Terminal: no HTML and no headers. Text to STDERR and a non-zero exit code.
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Error: the operation could not be completed. Details are in the error log.
");
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            // A temporary error page must not be cached anywhere.
            header('Cache-Control: no-store');
            if ($status === 503) {
                header('Retry-After: 60');
            }
        }

        $page = APPROOT . '/views/errors/500.php';
        if (is_file($page)) {
            require $page;
        } else {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
               . '<meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>' . $status . ' — Service Unavailable</title></head>'
               . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:60px">'
               . '<h1>' . $status . '</h1>'
               . '<p>The service is temporarily unavailable. Please try again shortly.</p>'
               . '</body></html>';
        }

        exit;
    }
}
