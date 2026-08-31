<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The security headers are declared in public/.htaccess.
 *
 * ══════════════════════════════════════════════════════════════
 * Why a test over a configuration file
 * ══════════════════════════════════════════════════════════════
 *
 * Because all of this project's response protection lives in one file that no PHP code and
 * no test passes through: the CSP, nosniff, X-Frame-Options, Referrer-Policy,
 * Permissions-Policy and HSTS are all in `.htaccess`.
 *
 * Which means deleting one line of it — or the whole block — fails nothing: the tests are
 * green, PHPStan is clean, and the site works. The only difference is that the visitor is no
 * longer protected.
 *
 * This test guards **what we promise**. And it is accompanied in `composer smoke` by a check
 * that asks a live server what it actually sends — covering the other fault: the file being
 * sound while the server never reads it (nginx, or Apache without AllowOverride, or without
 * mod_headers). Both are needed: this one runs in CI without a server, and that one catches
 * what no reader of the code can see.
 */
final class SecurityHeadersTest extends TestCase
{
    private string $htaccess;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 2) . '/public/.htaccess';
        $this->assertFileExists($path, 'public/.htaccess is missing — the web root has neither protection nor routing.');

        $this->htaccess = (string) file_get_contents($path);
    }

    /**
     * The name alone is not enough: `Content-Security-Policy: default-src *` is a header
     * that exists and is worth nothing. So each carries a string that must appear in its
     * line.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function requiredHeaders(): array
    {
        return [
            'nosniff'            => ['X-Content-Type-Options', 'nosniff'],
            'clickjacking'       => ['X-Frame-Options', 'SAMEORIGIN'],
            'referrer'           => ['Referrer-Policy', 'strict-origin-when-cross-origin'],
            'permissions'        => ['Permissions-Policy', 'camera=()'],
            'hsts'               => ['Strict-Transport-Security', 'max-age=31536000'],
            'csp'                => ['Content-Security-Policy', "default-src 'self'"],
        ];
    }

    #[DataProvider('requiredHeaders')]
    public function testTheHeaderIsDeclaredWithItsValue(string $header, string $needle): void
    {
        $this->assertStringContainsString(
            $header,
            $this->htaccess,
            "The {$header} header is not declared in public/.htaccess."
        );

        $this->assertStringContainsString(
            $needle,
            $this->htaccess,
            "The {$header} header is declared without \"{$needle}\"."
        );
    }

    /**
     * `always` is not a detail: without it the header is not added to error responses.
     * And a 404 or 500 page needs the protection just as a successful page does — and error
     * pages are exactly where unexpected content is displayed.
     */
    public function testEverySecurityHeaderUsesAlways(): void
    {
        preg_match_all('/^\s*Header\s+(\S+)\s+set\s+(\S+)/mi', $this->htaccess, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'There is no `Header set` at all in public/.htaccess.');

        foreach ($matches as [$line, $modifier, $header]) {
            $this->assertSame(
                'always',
                strtolower($modifier),
                "The {$header} header is set without `always` — so it is absent from error responses."
            );
        }
    }

    // ════════════════════════════════════════════════════════
    // What must not come back
    // ════════════════════════════════════════════════════════

    /**
     * `unsafe-inline` in script-src nullifies the CSP in practice: any text injected into
     * the page becomes executable, and that is the attack the policy exists for.
     *
     * And the project actually paid the price of removing it — fourteen embedded <script>
     * blocks and thirty-three onclick handlers left the views. This test prevents that price
     * being thrown away by one line.
     */
    public function testScriptSrcHasNoUnsafeInline(): void
    {
        preg_match('/script-src([^;"]*)/', $this->htaccess, $m);

        $this->assertNotEmpty($m, 'There is no script-src in the CSP.');
        $this->assertStringNotContainsString("'unsafe-inline'", $m[1]);
        $this->assertStringNotContainsString("'unsafe-eval'", $m[1]);
    }

    /**
     * `style-src` without unsafe-inline as well — the last step completed, and its cost was
     * 234 inline style attributes moved into utilities and classes. A policy that permits
     * inline styles cannot prevent an injected one.
     */
    public function testStyleSrcHasNoUnsafeInline(): void
    {
        preg_match('/style-src([^;"]*)/', $this->htaccess, $m);

        $this->assertNotEmpty($m, 'There is no style-src in the CSP.');
        $this->assertStringNotContainsString("'unsafe-inline'", $m[1]);
    }

    /**
     * Every host in script-src is an origin able to execute JavaScript on every page, the
     * checkout page and the control panel included. So the list is read as a trust list
     * rather than a configuration list, and no host enters it without a standing reason.
     *
     * `code.jquery.com` was dropped once it was established that jQuery is not used at all.
     * Its return would mean either the library coming back or — more dangerously — a CSP
     * line copied from an external source without being read.
     */
    public function testNoUnusedScriptHostIsAllowed(): void
    {
        preg_match('/script-src([^;"]*)/', $this->htaccess, $m);

        $this->assertStringNotContainsString('code.jquery.com', $m[1] ?? '');
    }

    /**
     * The tags loading an external library must carry its digest.
     *
     * Allowing a host in the CSP does not mean trusting any file it sends: a compromised
     * package on the CDN passes the CSP unchallenged. And `integrity` is what makes the
     * browser reject a file whose content has changed.
     *
     * The check is on assets_helper.php rather than the views: the tags were hand-written at
     * seven sites and only one of them carried a digest — and because they were scattered,
     * nobody could see the difference. Their source is now one.
     */
    public function testEveryVendorAssetCarriesAnIntegrityHash(): void
    {
        $helper = dirname(__DIR__, 2) . '/app/helpers/assets_helper.php';
        $source = (string) file_get_contents($helper);

        preg_match('/const VENDOR_ASSETS = \[(.*?)\n\];/s', $source, $m);
        $this->assertNotEmpty($m, 'VENDOR_ASSETS is not defined in assets_helper.php.');

        $urls = preg_match_all("/'url'\s*=>\s*'([^']+)'/", $m[1], $urlMatches);
        $sris = preg_match_all("/'sri'\s*=>\s*'(sha(?:256|384|512)-[^']+)'/", $m[1], $sriMatches);

        $this->assertGreaterThan(0, $urls, 'No external library is defined.');
        $this->assertSame(
            $urls,
            $sris,
            'An external library with no SRI digest — see VENDOR_ASSETS.'
        );

        // An open version range (@11) makes a digest impossible in the first place: the
        // content changes without the URL changing. So pinning is a precondition of the
        // digest rather than an option beside it.
        foreach ($urlMatches[1] as $url) {
            $this->assertMatchesRegularExpression(
                '/@\d+\.\d+\.\d+/',
                $url,
                "A version that is not fully pinned in: {$url}"
            );
        }
    }

    /**
     * The theme boot script's digest in the CSP matches themeBootScript()'s content.
     *
     * ══════════════════════════════════════════════════════════
     * Why this test exists
     * ══════════════════════════════════════════════════════════
     *
     * Because the warning comment above the digest in .htaccess did not stop it drifting.
     * It read `sha256-n33GJ97…` while the function produced `sha256-6TLKQaFc…` — which is to
     * say the script was blocked on **every** page load, across the store and the control
     * panel alike.
     *
     * And that is the worst kind of fault: no blank page, no exception and no red test. Only
     * a white flash on every navigation in dark mode, and a `Refused to execute inline
     * script` line in a console nobody reads.
     *
     * The digest is computed over the tag's content — what lies between <script> and
     * </script> — character for character: every space and every newline inside it. Which is
     * why merely reformatting the function invalidates it, and which is what makes the
     * comment a powerless guard and the test a real one.
     */
    public function testTheThemeBootScriptHashMatchesItsSource(): void
    {
        $this->assertTrue(
            function_exists('themeBootScript'),
            'themeBootScript() is not loaded — see app/helpers/assets_helper.php.'
        );

        $this->assertSame(
            1,
            preg_match('#<script>(.*?)</script>#s', themeBootScript(), $m),
            'themeBootScript() no longer produces a single <script> tag — the digest loses its meaning.'
        );

        $expected = 'sha256-' . base64_encode(hash('sha256', $m[1], true));

        $this->assertStringContainsString(
            "'{$expected}'",
            $this->htaccess,
            "The theme script's digest in the CSP does not match themeBootScript()'s content.\n"
            . "Expected: '{$expected}'\n"
            . 'Put it in script-src inside public/.htaccess. Until you do, the script is '
            . 'blocked on every page and the theme flashes on every navigation.'
        );
    }
}
