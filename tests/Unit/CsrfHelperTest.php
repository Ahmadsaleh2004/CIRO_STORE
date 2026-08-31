<?php

namespace Tests\Unit;

use Tests\Support\SessionTestCase;

/**
 * csrf_helper — the only guard between every POST endpoint in the project and
 * cross-site requests. 45 JSON endpoints depend on it.
 */
final class CsrfHelperTest extends SessionTestCase
{
    public function testGenerateProducesA64CharHexToken(): void
    {
        $token = generateCsrfToken();

        // 32 random bytes in hex = 64 characters. The length is not a cosmetic detail:
        // shortening it reduces the guessing space exponentially.
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGenerateIsStableWithinTheSameSession(): void
    {
        // The token must stay fixed within the session, or every form open in another tab
        // is invalidated by each new request.
        $this->assertSame(generateCsrfToken(), generateCsrfToken());
    }

    public function testVerifyAcceptsTheGeneratedToken(): void
    {
        $this->assertTrue(verifyCsrfToken(generateCsrfToken()));
    }

    public function testVerifyRejectsAWrongToken(): void
    {
        generateCsrfToken();
        $this->assertFalse(verifyCsrfToken(str_repeat('a', 64)));
    }

    /**
     * The most dangerous case in the whole file: a session with no token.
     *
     * Were verifyCsrfToken to return true here, **every** POST endpoint would be open to any
     * visitor without a session — which is exactly what an attacker has. The
     * `!empty($_SESSION['csrf_token'])` check is what prevents that, and this test guards
     * it.
     */
    public function testVerifyRejectsEverythingWhenSessionHasNoToken(): void
    {
        $_SESSION = [];

        $this->assertFalse(verifyCsrfToken(''));
        $this->assertFalse(verifyCsrfToken('anything'));
        $this->assertFalse(verifyCsrfToken(str_repeat('0', 64)));
    }

    public function testVerifyRejectsAnEmptyTokenEvenWhenSessionHasOne(): void
    {
        generateCsrfToken();
        $this->assertFalse(verifyCsrfToken(''));
    }

    /**
     * The comparison must be against the whole value rather than its prefix.
     *
     * A naive comparison with str_starts_with or substr would accept a truncated token —
     * which is a practical attack: the attacker guesses one character at a time.
     */
    public function testVerifyRejectsATruncatedPrefixOfTheRealToken(): void
    {
        $token = generateCsrfToken();

        $this->assertFalse(verifyCsrfToken(substr($token, 0, 63)));
        $this->assertFalse(verifyCsrfToken(substr($token, 0, 32)));
        $this->assertFalse(verifyCsrfToken($token . 'x'));
    }

    public function testTwoSeparateSessionsDoNotShareAToken(): void
    {
        $first = generateCsrfToken();

        // Simulating a second session: the same thing that happens when another user opens
        // the site.
        $_SESSION = [];
        $second = generateCsrfToken();

        $this->assertNotSame($first, $second);
        $this->assertFalse(verifyCsrfToken($first));
        $this->assertTrue(verifyCsrfToken($second));
    }

    // ════════════════════════════════════════════════════════
    // Rotation when the privilege level changes
    // ════════════════════════════════════════════════════════

    /**
     * The hole rotation closes:
     *
     * `session_regenerate_id(true)` replaces the session's id and keeps its contents — the
     * `csrf_token` among them. So an anonymous visitor's token from before sign-in stayed
     * valid, character for character, inside the authenticated session afterwards.
     *
     * And the contradiction is plain: the id is replaced precisely because we assume what
     * came before authentication may be known to an attacker — and then the token is
     * inherited.
     */
    public function testRotateInvalidatesThePreviousToken(): void
    {
        $before = generateCsrfToken();

        $after = rotateCsrfToken();

        $this->assertNotSame($before, $after);
        $this->assertFalse(verifyCsrfToken($before), 'The old token is still accepted after rotation.');
        $this->assertTrue(verifyCsrfToken($after));
    }

    public function testRotateReturnsAWellFormedTokenReadyToSend(): void
    {
        generateCsrfToken();

        $rotated = rotateCsrfToken();

        // The value is returned rather than read from the session by the caller: the
        // endpoints that rotate send the new token in the response body so js/core/csrf.js
        // picks it up immediately — without it the client discovers the invalidation through
        // a failed request and then retries, that is, two requests instead of one.
        $this->assertSame(64, strlen($rotated));
        $this->assertSame($_SESSION['csrf_token'], $rotated);
    }

    public function testRotateWorksWhenNoTokenExistsYet(): void
    {
        // It is called immediately after session_regenerate_id on a path that may have had
        // no token at all (a Google sign-in, for instance). It must issue one rather than
        // fall over.
        $_SESSION = [];

        $token = rotateCsrfToken();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertTrue(verifyCsrfToken($token));
    }
}
