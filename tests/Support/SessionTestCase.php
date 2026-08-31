<?php

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The base for tests that touch $_SESSION.
 *
 * The session is started **once** per run, and its contents are cleared between tests. The
 * reason: session_start() cannot be called twice in the same process, and session_destroy()
 * makes the next call fail — so the one workable pattern is a single session with clean
 * contents.
 *
 * And this matters specifically because csrf_helper and auth_helper call session_start()
 * themselves when no session exists, so leaving the state dirty between two tests makes the
 * outcome depend on the order.
 */
abstract class SessionTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_NONE) {
            // A file handler in a temporary directory — the real server's sessions stay clean.
            session_start();
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }
}
