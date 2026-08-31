<?php

namespace Tests\Integration;

use App\Core\Controller;
use PHPUnit\Framework\TestCase;

/**
 * The CSRF contract over HTTP — the standing guard for a fault that recurred **three
 * times**.
 *
 * The story: js/core/csrf.js detected a token failure with
 *     message.startsWith('Invalid CSRF token')
 * so any endpoint wording its message differently loses the automatic retry silently.
 * That happened in WishlistController::notify ('Invalid session…') and
 * ContactController::send ('Invalid request…'), and six endpoints survived by luck alone
 * because their wording happened to begin with the same prefix.
 *
 * The answer was an explicit error_code. But an answer without a test erodes: one new
 * endpoint calling respond() directly instead of beginJsonPost() is enough to bring the
 * fault back. This file prevents that — it sweeps **every** POST endpoint from the router
 * itself, so an endpoint added tomorrow enters the check automatically with nobody having to
 * remember it.
 *
 * ⚠️ It needs the development server running. It skips itself plainly if that is not the
 * case, so CI does not fail over a missing service instead of a missing correctness.
 */
final class CsrfContractHttpTest extends TestCase
{
    /**
     * The root of the server whose endpoints are checked.
     *
     * Settable through the TEST_BASE_URL environment variable so the test works in two
     * entirely different places: XAMPP locally on a subpath (/STORE/public), and PHP's
     * built-in server in CI on a port's root (http://127.0.0.1:8080). Hard-coding the path
     * would have made the test skip itself in CI permanently — a guard that guards nothing.
     */
    private static function base(): string
    {
        return rtrim(
            getenv('TEST_BASE_URL') ?: ($_ENV['TEST_BASE_URL'] ?? 'http://localhost/STORE/public'),
            '/'
        );
    }

    /**
     * Public POST endpoints that do **not** verify CSRF — each with its reason.
     *
     * The list is deliberately short, and every addition to it is a decision to be justified
     * rather than an oversight to be forgiven.
     */
    private const DOCUMENTED_EXEMPTIONS = [
        // Two pages that render HTML and accept POST for a filter/display form, not for a
        // state change.
        '/product'  => 'A display page accepting POST for the filter form — it changes no state',
        '/contact'  => 'A display page accepting POST to refill the form — the actual send is on /contact/send',
        // A pure read: it returns the variants' stock. It writes nothing.
        '/cart/check-stock' => 'A pure stock read — it writes nothing, so there is no state to forge',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (self::request('/', 'GET') === null) {
            $this->markTestSkipped('The development server is not responding on ' . self::base());
        }

        self::clearThrottle();
    }

    /**
     * Resets the throttle counter before every case.
     *
     * This file hits every POST endpoint from one address with an invalid token — which is
     * exactly the pattern Middleware::throttle is meant to stop. So without a reset the test
     * begins by measuring the CSRF contract and ends by measuring the throttle: the endpoints
     * answer 429 with a "too many attempts" message instead of the csrf_invalid code, and the
     * test fails over correct behaviour.
     *
     * The deletion goes straight to the database rather than through Throttle::clear: that
     * clears one bucket for one source, and what is wanted here is entirely clean ground.
     */
    private static function clearThrottle(): void
    {
        try {
            \App\Core\Database::connect()->exec('DELETE FROM throttle_attempts');
        } catch (\Throwable $e) {
            // The database is unavailable — the test will skip itself or fail for a clearer
            // reason than this one. Swallowing the exception here prevents a misleading
            // message.
        }
    }

    /** @return array{status:int, body:string}|null */
    private static function request(string $path, string $method = 'POST'): ?array
    {
        $ch = curl_init(self::base() . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $body === false ? null : ['status' => $status, 'body' => (string) $body];
    }

    /** Reads the POST routes from the router itself — no hand-written list that goes stale. */
    /**
     * @return list<string>
     */
    private static function postRoutes(): array
    {
        $index = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        preg_match_all('/->post\(\s*\'([^\']+)\'/', (string) $index, $m);

        // Routes with {id} parameters need a real value — outside this contract's scope.
        return array_values(array_filter($m[1], static fn ($p) => !str_contains($p, '{')));
    }

    /**
     * The auth-guarded routes in the route table.
     *
     * They are read from public/index.php rather than from a hand-written list: moving the
     * guarding onto the route definition put the guard before the controller, which changed
     * what these endpoints answer to an unauthenticated request — and the test must follow
     * the source rather than carry an old picture of it.
     *
     * @return list<string>
     */
    private static function authGuardedPaths(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

        preg_match_all(
            "/->post\(\s*'([^']+)'[^;]*?->middleware\('auth'\)/s",
            $src,
            $m
        );

        return array_values(array_unique($m[1]));
    }

    /**
     * Every public JSON endpoint refuses a request without a token, and refuses it **with
     * the explicit code** rather than with a message's text. The code is what csrf.js reads.
     *
     * The auth-guarded endpoints are excluded: there, authentication precedes CSRF, and that
     * is the right order — checking a token that protects a session which does not exist is
     * meaningless, and an "invalid token" message to a signed-out visitor describes a symptom
     * rather than a cause. They are covered in the next test.
     */
    public function testEveryPublicJsonPostEndpointRejectsWithTheExplicitErrorCode(): void
    {
        $authGuarded = self::authGuardedPaths();
        $failures = [];
        $checked  = 0;

        foreach (self::postRoutes() as $path) {
            if (str_starts_with($path, '/admin/')) {
                continue; // Covered below — the session guard precedes the CSRF guard
            }
            if (isset(self::DOCUMENTED_EXEMPTIONS[$path]) || in_array($path, $authGuarded, true)) {
                continue;
            }

            $checked++;
            $response = self::request($path);
            $json     = json_decode($response['body'] ?? '', true);

            if (!is_array($json)) {
                $failures[] = "{$path} — it did not return JSON at all";
                continue;
            }
            if (($json['error_code'] ?? null) !== Controller::ERR_CSRF_INVALID) {
                $failures[] = sprintf(
                    '%s — error_code = %s (expected %s) · message: %s',
                    $path,
                    var_export($json['error_code'] ?? null, true),
                    Controller::ERR_CSRF_INVALID,
                    $json['message'] ?? '—'
                );
            }
        }

        $this->assertGreaterThan(8, $checked, 'The sweep did not find enough endpoints — check the router reader.');
        $this->assertSame(
            [],
            $failures,
            "Endpoints that do not honour the error_code contract (which is what js/core/csrf.js reads):
  "
            . implode("
  ", $failures)
        );
    }

    /**
     * The auth-guarded endpoints answer with **JSON and a 401** rather than a redirect to
     * HTML.
     *
     * This was a latent fault before the guarding moved onto the route:
     * Middleware::requireLogin always redirected with a 302, drawing no distinction between a
     * full page and a JSON endpoint. The fault never appeared because it was called from
     * inside the action's body — that is, after beginJsonPost had already ended the request.
     *
     * And the moment the guard came before the controller, fetch in the browser began
     * receiving a whole HTML page and trying to read it as JSON. This test caught that.
     */
    public function testAuthGuardedJsonEndpointsAnswerWithJsonNotARedirect(): void
    {
        $failures = [];
        $checked  = 0;

        foreach (self::authGuardedPaths() as $path) {
            $checked++;
            $response = self::request($path);
            $json     = json_decode($response['body'] ?? '', true);

            if (!is_array($json)) {
                $failures[] = "{$path} — it answered with something other than JSON (code {$response['status']})";
                continue;
            }
            if (($json['success'] ?? null) !== false) {
                $failures[] = "{$path} — success is not false for an unauthenticated request";
                continue;
            }
            if ($response['status'] !== 401) {
                $failures[] = "{$path} — the code is {$response['status']} (expected 401)";
            }
        }

        $this->assertGreaterThan(3, $checked, 'No auth-guarded routes were found.');
        $this->assertSame(
            [],
            $failures,
            "Auth-guarded endpoints not answering JSON/401 to an unauthenticated request:
  " . implode("
  ", $failures)
        );
    }

    /**
     * The admin surface is closed before CSRF.
     *
     * The /admin/* endpoints never reach the CSRF check because Middleware::requireAdmin
     * precedes it — and that is correct and deliberate (defence in layers). What has to be
     * established here is that none of them **succeeds** without a session: no success:true,
     * and no 200 carrying completed work.
     */
    public function testNoAdminPostEndpointSucceedsWithoutASession(): void
    {
        $leaks   = [];
        $checked = 0;

        foreach (self::postRoutes() as $path) {
            if (!str_starts_with($path, '/admin/')) {
                continue;
            }
            // The sign-in endpoints themselves must be reachable without a session — or
            // signing in would be impossible.
            if (in_array($path, ['/admin/login', '/admin/login/2fa', '/admin/forgot'], true)) {
                continue;
            }

            $checked++;
            $response = self::request($path);
            $json     = json_decode($response['body'] ?? '', true);

            if (is_array($json) && ($json['success'] ?? false) === true) {
                $leaks[] = "{$path} — it returned success:true with no admin session";
            }
        }

        $this->assertGreaterThan(20, $checked, 'The sweep did not cover the admin endpoints.');
        $this->assertSame([], $leaks, "Admin endpoints working without authentication:\n  " . implode("\n  ", $leaks));
    }

    /**
     * The code itself is fixed. Changing it breaks js/core/csrf.js silently, so it is frozen
     * here as the literal value the browser looks for.
     */
    public function testTheErrorCodeConstantMatchesWhatTheBrowserLooksFor(): void
    {
        $this->assertSame('csrf_invalid', Controller::ERR_CSRF_INVALID);

        $clientSide = file_get_contents(dirname(__DIR__, 2) . '/public/js/core/csrf.js');
        $this->assertStringContainsString(
            'csrf_invalid',
            (string) $clientSide,
            'js/core/csrf.js no longer mentions the code — the two sides of the contract have parted.'
        );
    }
}
