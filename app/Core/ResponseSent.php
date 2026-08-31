<?php

namespace App\Core;

use RuntimeException;

/**
 * Thrown in place of `exit` when a controller has finished writing its response — **under
 * the CLI alone**.
 *
 * ── Why it exists ────────────────────────────────────────────
 *
 * Controller::respond() ends a request with `exit`, which is correct in a web server and
 * fatal in a test: calling any of the 225 actions that end that way would take PHPUnit down
 * with it. So the controllers — 5,094 statements, 40% of everything the coverage gate
 * measures — could not be executed by a test at all, and sat at 0%.
 *
 * The alternative was to test them over HTTP against a live server, the way
 * CsrfContractHttpTest does. That is a real test and it stays, but it cannot raise
 * coverage: the code runs in the server's process, and the coverage driver is watching
 * PHPUnit's.
 *
 * ── Why it is restricted to the CLI ──────────────────────────
 *
 * The same reasoning as Database::setConnection(), and the same restriction. In a web
 * request the behaviour is byte-identical to what it was: `exit`, no exception, no handler
 * to get it wrong. Under the CLI — where the only caller is the test suite — the response
 * is written and control returns to the test, which reads it.
 *
 * A throw is a legitimate way for a `never`-returning function to end, so respond()'s
 * signature is unchanged and every caller still knows execution stops there.
 *
 * ⚠️ It is not an error, and it must never be reported as one. Catching it means "the
 * controller answered", which is the ordinary outcome of a successful action.
 */
final class ResponseSent extends RuntimeException
{
    public function __construct(public readonly string $body = '')
    {
        parent::__construct('The controller has sent its response.');
    }

    /**
     * Ends the request, once, for everything that ends one.
     *
     * Defined here rather than repeated at each site so the CLI condition exists in a
     * single place: a second copy that drifted would be a path where a test still dies,
     * and finding it would mean reading every `exit` in the project.
     *
     * Callers are Controller::respond() and the refusals in Middleware — a guard that
     * refuses is the commonest way an action ends, and leaving it on a bare `exit` would
     * have meant no guarded action could be tested at all.
     */
    public static function end(string $body = ''): never
    {
        if (PHP_SAPI === 'cli') {
            throw new self($body);
        }

        exit;
    }
}
