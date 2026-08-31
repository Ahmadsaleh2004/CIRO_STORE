<?php

namespace Tests\Unit;

use App\Core\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Totp — two-factor authentication for the admin accounts.
 *
 * It is tested against **the reference vectors of RFC 6238** rather than against itself. The
 * difference is fundamental: a broken TOTP implementation stays perfectly consistent with
 * itself (it generates a code and accepts it), so a "generate then verify" test passes over
 * code that does not work with Google Authenticator at all. The reference vectors alone
 * reveal that.
 */
final class TotpTest extends TestCase
{
    /** The reference secret in RFC 6238: "12345678901234567890" in Base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private function generateCodeAt(string $secret, int $timeSlice): string
    {
        $m = new ReflectionMethod(Totp::class, 'generateCode');
        $m->setAccessible(true);
        return $m->invoke(null, $secret, $timeSlice);
    }

    /**
     * The RFC 6238 vectors (SHA-1). The values in the standard are eight digits; the
     * project uses six, so the last six digits are taken.
     *
     * @return array<string, array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            'T=59'          => [59,          '287082'],
            'T=1111111109'  => [1111111109,  '081804'],
            'T=1111111111'  => [1111111111,  '050471'],
            'T=1234567890'  => [1234567890,  '005924'],
            'T=2000000000'  => [2000000000,  '279037'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function testMatchesRfc6238ReferenceVectors(int $unixTime, string $expected): void
    {
        $timeSlice = (int) floor($unixTime / 30);

        $this->assertSame(
            $expected,
            $this->generateCodeAt(self::RFC_SECRET, $timeSlice),
            "The code at T={$unixTime} does not match the RFC 6238 vector — the implementation will not work with Google Authenticator."
        );
    }

    public function testGeneratedSecretIsValidBase32OfTheExpectedLength(): void
    {
        $secret = Totp::generateSecret();

        $this->assertSame(20, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{20}$/', $secret);
    }

    public function testGeneratedSecretsDiffer(): void
    {
        $secrets = [];
        for ($i = 0; $i < 20; $i++) {
            $secrets[] = Totp::generateSecret();
        }

        // A fixed secret means every admin account shares the same second factor.
        $this->assertCount(20, array_unique($secrets));
    }

    public function testVerifyAcceptsTheCurrentCode(): void
    {
        $secret = Totp::generateSecret();
        $code   = $this->generateCodeAt($secret, (int) floor(time() / 30));

        $this->assertTrue(Totp::verifyCode($secret, $code));
    }

    /**
     * The tolerance window is ±30 seconds — no more.
     *
     * Widening it eases use and widens the replay window by exactly as much.
     * This test pins the limit: what is inside the window is accepted, and what is outside
     * it is refused.
     */
    public function testVerifyAcceptsOneStepBeforeAndAfter(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        $this->assertTrue(Totp::verifyCode($secret, $this->generateCodeAt($secret, $now - 1)));
        $this->assertTrue(Totp::verifyCode($secret, $this->generateCodeAt($secret, $now + 1)));
    }

    public function testVerifyRejectsCodesOutsideTheTolerance(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        $this->assertFalse(Totp::verifyCode($secret, $this->generateCodeAt($secret, $now - 5)));
        $this->assertFalse(Totp::verifyCode($secret, $this->generateCodeAt($secret, $now + 5)));
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $secret = Totp::generateSecret();

        foreach (['', '12345', '1234567', 'abcdef', '12 34 56', '<script>'] as $bad) {
            $this->assertFalse(
                Totp::verifyCode($secret, $bad),
                "An invalid input was accepted: [{$bad}]"
            );
        }
    }

    public function testVerifyIgnoresSurroundingWhitespace(): void
    {
        // The user copies the code from the app and a space comes with it — refusing that
        // is a usability fault rather than protection.
        $secret = Totp::generateSecret();
        $code   = $this->generateCodeAt($secret, (int) floor(time() / 30));

        $this->assertTrue(Totp::verifyCode($secret, "  {$code}  "));
    }

    public function testACodeFromADifferentSecretIsRejected(): void
    {
        $mine   = Totp::generateSecret();
        $theirs = Totp::generateSecret();
        $code   = $this->generateCodeAt($theirs, (int) floor(time() / 30));

        $this->assertFalse(Totp::verifyCode($mine, $code));
    }

    // ════════════════════════════════════════════════════════
    // The QR image — generated locally, and the secret never leaves the server
    // ════════════════════════════════════════════════════════

    /**
     * The QR is an embedded image rather than a URL to an external host.
     *
     * The generator used to return a URL to api.qrserver.com carrying the secret in the
     * query string — which is to say every admin's second-factor secret was handed to a
     * third party and passed through its logs and those of any intermediary. This test
     * prevents that returning, however the implementation changes.
     */
    public function testTheQrCodeIsAnInlineImageNotARemoteUrl(): void
    {
        $secret = Totp::generateSecret();
        $src    = Totp::getQrCodeUrl($secret, 'admin@example.com');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $src);
        $this->assertDoesNotMatchRegularExpression(
            '#https?://#i',
            $src,
            'The QR image points at an external host — the secret leaves the server.'
        );
    }

    /**
     * And more importantly: the secret itself is nowhere it could be transmitted from.
     *
     * The check runs on the decoded value rather than the encoded text: base64 hides the
     * secret from a quick read and does not hide it from the network.
     */
    public function testTheSecretDoesNotAppearInAnyRequestableForm(): void
    {
        $secret = Totp::generateSecret();
        $src    = Totp::getQrCodeUrl($secret, 'admin@example.com');
        $svg    = base64_decode(substr($src, strlen('data:image/svg+xml;base64,')));

        $this->assertStringContainsString('<svg', $svg, 'The output is not valid SVG.');

        // The secret is encoded in the QR's own modules (squares), not as text.
        $this->assertStringNotContainsString($secret, $svg);
        $this->assertStringNotContainsString('otpauth://', $svg);
    }

    /**
     * The otpauth URL carries what the authenticator apps need.
     *
     * It is shown as text to the admin when scanning is not possible — so were the issuer or
     * the secret missing from it, the admin would add an account that does not work, and
     * would only discover that at their first sign-in.
     */
    public function testTheProvisioningUriCarriesIssuerAndSecret(): void
    {
        $secret = Totp::generateSecret();
        $uri    = Totp::provisioningUri($secret, 'admin@example.com', 'Cairo Store');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=Cairo%20Store', $uri);
    }

    /**
     * No source in the project mentions an external QR service.
     *
     * The two tests above guard the current generator; this one guards the project against a
     * "quick fix" somewhere else — a line in a view or in JS building the URL itself. A
     * secret in a query string does not become safe by changing who writes it.
     */
    public function testNoSourceReferencesAnExternalQrService(): void
    {
        $roots = [
            dirname(__DIR__, 2) . '/app',
            dirname(__DIR__, 2) . '/public/js',
        ];

        $offenders = [];

        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (!in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }

                $src = (string) file_get_contents($file->getPathname());

                foreach (['qrserver.com', 'chart.googleapis.com', 'quickchart.io'] as $host) {
                    // A mention inside a comment explaining the fix is allowed; what is
                    // forbidden is building an actual URL — that is, the host preceded by a
                    // scheme.
                    if (preg_match('#https?://[^\s\'"]*' . preg_quote($host, '#') . '#i', $src)) {
                        $offenders[] = $file->getFilename() . " → {$host}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "An external QR service — the secret leaves the server:\n  " . implode("\n  ", $offenders)
        );
    }

    // ════════════════════════════════════════════════════════
    // Preventing code reuse — verifyAndGetSlice
    // ════════════════════════════════════════════════════════

    /**
     * Success returns the slice rather than merely true.
     *
     * The returned value is what the caller stores to prevent reuse, so were the wrong slice
     * returned, the prevention would be either too wide (refusing valid codes) or empty
     * (preventing nothing).
     */
    public function testVerifyAndGetSliceReturnsTheMatchingSlice(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        $this->assertSame($now, Totp::verifyAndGetSlice($secret, $this->generateCodeAt($secret, $now)));
        $this->assertSame($now - 1, Totp::verifyAndGetSlice($secret, $this->generateCodeAt($secret, $now - 1)));
        $this->assertSame($now + 1, Totp::verifyAndGetSlice($secret, $this->generateCodeAt($secret, $now + 1)));
    }

    public function testVerifyAndGetSliceReturnsNullOnFailure(): void
    {
        $secret = Totp::generateSecret();

        $this->assertNull(Totp::verifyAndGetSlice($secret, '000000'));
        $this->assertNull(Totp::verifyAndGetSlice($secret, 'abcdef'));
    }

    /**
     * A consumed code is refused within its own window.
     *
     * Without this the code stays valid for a full ninety seconds, so whoever catches it —
     * over a shoulder, from a log, or from a shared screen — resends it and gets in.
     */
    public function testAConsumedSliceIsRejected(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);
        $code   = $this->generateCodeAt($secret, $now);

        $this->assertSame($now, Totp::verifyAndGetSlice($secret, $code, null));
        $this->assertNull(Totp::verifyAndGetSlice($secret, $code, $now));
    }

    /**
     * And anything older than the consumed slice is refused too, not equality alone.
     *
     * The window holds three slices. Were only the matching one blocked, the previous
     * slice's code would stay valid after the later one was used — a thirty-second hole
     * opening at exactly the moment the door is supposed to be shut.
     */
    public function testASliceOlderThanTheConsumedOneIsRejected(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        $this->assertNull(Totp::verifyAndGetSlice($secret, $this->generateCodeAt($secret, $now - 1), $now));
    }

    /**
     * But a slice newer than the consumed one stays acceptable.
     *
     * The upper bound of the prevention matters as much as the lower: were anything after
     * the consumed slice refused, the account would lock itself after the first successful
     * sign-in.
     */
    public function testANewerSliceIsStillAccepted(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        $this->assertSame(
            $now,
            Totp::verifyAndGetSlice($secret, $this->generateCodeAt($secret, $now), $now - 1)
        );
    }

    /**
     * The older verifyCode remains an honest wrapper over verifyAndGetSlice.
     *
     * It is still used on the 2FA enrolment path, so its diverging from the function it
     * delegates to would have meant two different verification rules in the same project.
     */
    public function testVerifyCodeStaysConsistentWithVerifyAndGetSlice(): void
    {
        $secret = Totp::generateSecret();
        $now    = (int) floor(time() / 30);

        foreach ([$this->generateCodeAt($secret, $now), '000000', '', 'zzzzzz'] as $code) {
            $this->assertSame(
                Totp::verifyAndGetSlice($secret, $code) !== null,
                Totp::verifyCode($secret, $code),
                "The two functions diverge on the input [{$code}]"
            );
        }
    }
}
