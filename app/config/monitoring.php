<?php

/**
 * app/config/monitoring.php
 * Error monitoring setup (Sentry) and the capture handlers.
 *
 * ══════════════════════════════════════════════════════════════
 * Why
 * ══════════════════════════════════════════════════════════════
 *
 * All that used to happen on an error was a line in `storage/php-error.log` — a
 * file nobody opens. Which means a fault on the checkout page at two in the
 * morning is discovered when a customer complains, if they complain.
 *
 * And this is not hypothetical: the checkout page was **entirely broken** from
 * the baseline commit onward — a field-name mismatch that made every cart item
 * drop out — and nobody noticed. Had monitoring been in place, the alert would
 * have arrived with the first attempted purchase.
 *
 * ══════════════════════════════════════════════════════════════
 * Three conditions to run — and absence is the default
 * ══════════════════════════════════════════════════════════════
 *
 * Nothing here runs unless `SENTRY_DSN` exists in `.env`. A developer who clones
 * the repository needs no account and pays for no network request, and the whole
 * file collapses to a single `return`.
 *
 * Tests are excluded explicitly: `APP_ENV=testing` prevents initialisation.
 * Without that, every deliberately thrown test exception would be sent to Sentry,
 * drowning the project in noise and spending the account's quota on errors that
 * are not errors.
 *
 * CLI is included deliberately: the scripts under scripts/ (the migrator, the
 * mail queue) run with no human watching — which makes them the first candidates
 * for monitoring, not the first for exemption.
 */

declare(strict_types=1);

/**
 * Keys that never leave the server.
 *
 * ⚠️ This is the most important list in the file. Sentry sends the request
 * context with every error, and the request context includes `$_POST` — that is,
 * passwords, CSRF tokens, 2FA codes and reset keys. Sending those to a third
 * party is not a possible leak but a leak by design.
 *
 * `send_default_pii => false` below blocks the basics (cookies, IP address,
 * authentication headers). This list covers what is specific to this project and
 * unknown to the default sender.
 *
 * Whoever adds a new sensitive field to any form is responsible for adding it here.
 */
const MONITORING_SCRUB_KEYS = [
    'password',
    'password_confirmation',
    'confirm_password',
    'current_password',
    'new_password',
    'csrf_token',
    'token',
    'totp_code',
    'totp_secret',
    'code',
    'h-captcha-response',
    'secret',
    'authorization',
];

/**
 * Scrubs sensitive values out of an array — recursively.
 *
 * The recursion is not decoration: a JSON body may carry the field inside a
 * nested object, and scrubbing the top level alone gives a feeling of safety
 * without the safety.
 *
 * @param  array<string,mixed> $data
 * @return array<string,mixed>
 */
function monitoringScrub(array $data): array
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = monitoringScrub($value);
            continue;
        }

        if (in_array(strtolower((string) $key), MONITORING_SCRUB_KEYS, true)) {
            $data[$key] = '[scrubbed]';
        }
    }

    return $data;
}

/**
 * Initialises Sentry and wires the capture handlers — or does nothing at all.
 */
function initMonitoring(): void
{
    $dsn = env('SENTRY_DSN');

    if ($dsn === null || $dsn === '') {
        return;
    }

    // Tests throw exceptions deliberately. Sending them drowns the project in noise
    // and spends the quota on what is not a fault.
    //
    // Read from env() rather than the APP_ENV constant: tests/phpstan-bootstrap.php
    // defines the constant as 'testing' so the analyser can see the constants, which
    // makes it conclude the comparison is always true and report it as an error.
    // Reading from the source is also more correct logically — it does not depend on
    // config.php having defined the constant yet.
    $environment = (string) (env('APP_ENV', 'production') ?? 'production');

    if ($environment === 'testing') {
        return;
    }

    if (!class_exists(\Sentry\SentrySdk::class)) {
        // The package is not installed (an install without composer install, say).
        // Its absence must not bring the application down: monitoring serves the
        // running system, it is not a precondition for it.
        error_log('SENTRY_DSN is set but the sentry/sentry package is not installed.');
        return;
    }

    \Sentry\init([
        'dsn'         => $dsn,
        'environment' => $environment,

        // ⚠️ false explicitly rather than relying on the default: it blocks sending
        // the IP address, the cookies and the authentication headers. The cookie here
        // carries the session id, and whoever holds it holds the session.
        'send_default_pii' => false,

        // Performance tracing is off by default: it samples **every** request rather
        // than errors alone, so it burns through the quota quickly with no standing
        // need for it.
        'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', '0.0'),

        // The last filter before the data leaves the server.
        'before_send' => static function (\Sentry\Event $event): \Sentry\Event {
            $request = $event->getRequest();

            if ($request !== []) {
                if (isset($request['data']) && is_array($request['data'])) {
                    $request['data'] = monitoringScrub($request['data']);
                }
                // The URL may carry a reset or email-verification token in its query string.
                if (isset($request['query_string']) && is_string($request['query_string'])) {
                    parse_str($request['query_string'], $parsed);
                    $request['query_string'] = http_build_query(monitoringScrub($parsed));
                }
                unset($request['cookies'], $request['headers']);
                $event->setRequest($request);
            }

            return $event;
        },
    ]);

    registerMonitoringHandlers();
}

/**
 * Wires up uncaught exceptions and fatal errors.
 *
 * ══════════════════════════════════════════════════════════════
 * ⚠️ A measured and fixed fault: three events for one exception
 * ══════════════════════════════════════════════════════════════
 *
 * The first version of this function did three things that compounded:
 *
 *   1. it captured the exception itself,
 *   2. then delegated to `$previousException` — which is **Sentry's own handler**,
 *      registered inside init(), so it captured it a second time,
 *   3. then the exception became an E_ERROR and the shutdown handler captured it a
 *      third time.
 *
 * Actually measured: `before_send` was called **three times** for one exception.
 * Which is three alerts, triple the quota, and a set of events that looks like
 * three faults while being one.
 *
 * And worse than the duplication: delegating to Sentry's handler **suppressed the
 * error page**. That handler lets PHP print "Fatal error: Uncaught …" and never
 * returns, so the ErrorPage line below was never reached — while the old comment
 * explicitly promised "a clean 500 page". Measured: an uncaught exception printed
 * the raw trace, not a page.
 *
 * ── The fix ──────────────────────────────────────────────────
 *
 * No delegation. This handler discharges its responsibility itself: it captures
 * once, logs, then renders a 500 page and exits. Its exit stops the entire handler
 * chain from running — which is the point, since that chain is the source of the
 * duplication.
 *
 * The shutdown handler guards what this one cannot see: memory exhaustion, a
 * timeout, a parse error in an included file. Those end the request before it
 * reaches any `catch` or any exception handler, and they are precisely the
 * production faults nobody sees. The `$alreadyReported` guard stops it repeating
 * what the first handler already captured.
 */
function registerMonitoringHandlers(): void
{
    // Shared between the two handlers: whichever reports first stops the other repeating.
    $alreadyReported = new ArrayObject(['done' => false]);

    set_exception_handler(static function (\Throwable $e) use ($alreadyReported): void {
        $alreadyReported['done'] = true;

        \Sentry\captureException($e);
        \Sentry\SentrySdk::getCurrentHub()->getClient()?->flush();

        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage());

        // ⚠️ No delegating to the previous handler. See the comment above: that
        // handler is Sentry's, and delegating to it both duplicates the event and
        // cancels the error page.
        \App\Core\ErrorPage::serverError($e->getMessage(), 500);
    });

    register_shutdown_function(static function () use ($alreadyReported): void {
        if ($alreadyReported['done']) {
            return;
        }

        $error = error_get_last();

        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        \Sentry\captureException(new \ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        ));

        // Sending is asynchronous by nature; an explicit flush guarantees the event
        // leaves before the process dies. Without it, the most important faults are
        // the ones lost.
        //
        // ⚠️ And it is not guaranteed in one case: memory exhaustion. The handler
        // itself needs memory to serialise the event and send it, and there may be
        // none. Measured: the fault is always written locally, and only there is its
        // arrival at Sentry uncertain.
        \Sentry\SentrySdk::getCurrentHub()->getClient()?->flush();
    });
}

/**
 * Reports to Sentry an exception that was caught and will not be rethrown.
 *
 * ══════════════════════════════════════════════════════════════
 * The gap it closes
 * ══════════════════════════════════════════════════════════════
 *
 * `initMonitoring` wires two handlers: one for uncaught exceptions and one for
 * fatal errors. Both of them only run when the error **propagates** to the top.
 *
 * But this project does not let errors propagate: some hundred and fifty places
 * across `app/Models/*` and `app/Core/*` catch `Exception`, write an `error_log`,
 * and return `false` or `[]`. That is a sound approach — the interface should not
 * collapse because a query failed — but its effect is that Sentry is blind to
 * every database failure in the whole application. The monitoring dashboard is
 * green while requests fail silently.
 *
 * ══════════════════════════════════════════════════════════════
 * Why a function rather than a repeated line
 * ══════════════════════════════════════════════════════════════
 *
 * Calling `\Sentry\captureException($e)` directly would mean a `class_exists`
 * before it at every site — otherwise the application blows up on an install
 * without `composer install`, and monitoring becomes the cause of the fault rather
 * than what reveals it. One site forgetting the guard is enough.
 *
 * It is silent by design in every absence case: monitoring serves the running
 * system, it is not a precondition for it.
 *
 * ⚠️ And it does not replace `error_log`. The local log remains the source that
 * works with no network, no DSN, and in the test environment — and `before_send`
 * here scrubs the sensitive fields, which means what reaches Sentry is
 * deliberately less complete than what is in the log.
 */
function reportException(\Throwable $e): void
{
    if (!class_exists(\Sentry\SentrySdk::class)) {
        return;
    }

    // No hub ⇒ initMonitoring was never called, or was called and returned early
    // (no DSN, or the test environment). A capture then has nowhere to go.
    if (\Sentry\SentrySdk::getCurrentHub()->getClient() === null) {
        return;
    }

    \Sentry\captureException($e);
}
