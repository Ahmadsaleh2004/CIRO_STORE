<?php

namespace Tests\Support;

use App\Core\ResponseSent;

/**
 * The base for tests that call a controller action directly, in this process.
 *
 * ── Why in this process ──────────────────────────────────────
 *
 * CsrfContractHttpTest already proves the endpoints over real HTTP, and that test stays.
 * But it cannot raise coverage by a single statement: the controller runs inside the
 * server's process while the coverage driver watches PHPUnit's. Everything under
 * app/Controllers — 5,094 statements, 40% of what the gate measures — therefore read 0%
 * however thoroughly it was exercised.
 *
 * Calling the action here executes the same code where it can be seen.
 *
 * ── How an action that never returns is called ───────────────
 *
 * Controller::respond() ends a request, and in a web request it does so with `exit`.
 * Under the CLI it throws ResponseSent instead — a seam restricted to the CLI in the same
 * way, and for the same reason, as Database::setConnection(). So `call()` runs the action
 * inside a try/catch, buffers what it printed, and hands back the decoded JSON.
 *
 * An action that returns normally without responding is not an error either: some render a
 * view instead. Its output is captured just the same.
 *
 * ⚠️ What this does **not** cover: 42 places in the controllers that write their response
 * and `exit` inline rather than through respond(). Those still stop a test dead, and an
 * action containing one has to be tested over HTTP until it is moved onto respond().
 */
abstract class ControllerTestCase extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The same single-session pattern SessionTestCase documents: session_start() cannot
        // be called twice in one process, and session_destroy() breaks the next call — so
        // one session is started and its contents are emptied between tests. It is not
        // inherited from that class only because the database setup above is also needed
        // and PHP has no multiple inheritance.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
        parent::tearDown();
    }

    /**
     * Signs the request with a valid CSRF token — the real one, from the real helper.
     *
     * Deliberately not a bypass: beginJsonPost() verifies the token on every POST, and a
     * test that skipped the check would be testing a path no browser takes. This produces
     * the token the same way the page does, so the action runs its genuine sequence.
     *
     * @param  array<string, mixed> $post
     * @return array<string, mixed>
     */
    protected function withCsrf(array $post = []): array
    {
        $post['csrf_token'] = generateCsrfToken();
        return $post;
    }

    /**
     * Calls an action and returns whatever it printed, decoded.
     *
     * @param callable            $action Usually [new SomeController(), 'method'].
     * @param array<string, mixed> $post
     * @param array<string, mixed> $get
     * @return array{json: array<string, mixed>|null, body: string, responded: bool}
     */
    protected function call(callable $action, array $post = [], array $get = [], string $method = 'POST'): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = $post;
        $_GET  = $get;

        $responded = false;

        ob_start();
        try {
            $action();
        } catch (ResponseSent) {
            // The ordinary outcome of an action that answered. Not a failure.
            $responded = true;
        } finally {
            $body = (string) ob_get_clean();
        }

        $json = json_decode($body, true);

        return [
            'json'      => is_array($json) ? $json : null,
            'body'      => $body,
            'responded' => $responded,
        ];
    }

    /**
     * Calls an action and asserts it answered with JSON, returning that JSON.
     *
     * @param  array<string, mixed> $post
     * @param  array<string, mixed> $get
     * @return array<string, mixed>
     */
    protected function callJson(callable $action, array $post = [], array $get = [], string $method = 'POST'): array
    {
        $result = $this->call($action, $post, $get, $method);

        $this->assertIsArray(
            $result['json'],
            'The action did not answer with JSON. It printed: ' . substr($result['body'], 0, 300)
        );

        return $result['json'];
    }
}
