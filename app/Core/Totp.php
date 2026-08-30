<?php

namespace App\Core;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

// ⚠️ No `use OTPHP\TOTP;` here. Class names in PHP are case-insensitive, so that
// import brings the name `TOTP` into this namespace — which is the same name as
// the `Totp` declared below. The result is a fatal error: "Cannot declare class
// App\Core\Totp because the name is already in use". The fully qualified name at
// the point of use avoids it and makes the source explicit at the same time.

/**
 * Totp — two-factor authentication for admin accounts.
 *
 * ⚠️ This file used to implement TOTP and base32 by hand. It worked correctly (the
 * RFC 6238 vectors attest to that, and they are tested), but hand-written crypto
 * means any subtle error in it goes undetected by human review. Its core was
 * replaced with spomky-labs/otphp, and the **policy** stays explicit here:
 *
 *   · the ±30-second tolerance window — a security decision, not an implementation
 *     detail, so it stays legible in this file rather than buried in a library
 *     argument.
 *   · preventing code reuse through the consumed time slice.
 *
 * That is why the library's TOTP::verify() is not used: it takes leeway in seconds
 * and does not return the matching slice, and the slice is what prevents reuse. The
 * loop below asks the library for each slice's code and compares in constant time —
 * so all the cryptography comes from it, and all the policy stays here.
 *
 * ⚠️ And most importantly: the QR image is generated **locally**. It used to be
 * built as a link to api.qrserver.com carrying the secret in the query string —
 * meaning every admin's two-factor secret was sent to a third party, and passed
 * through its logs and those of every intermediary on the way. The secret now never
 * leaves the server.
 */
class Totp
{
    private const SECRET_LENGTH = 20;

    /** The time-slice length in seconds — the TOTP standard. */
    private const PERIOD = 30;

    /** How many slices of tolerance before and after the current one (1 = ±30 seconds). */
    private const WINDOW = 1;

    public static function generateSecret(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32 alphabet
        $secret = '';
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    /**
     * A display-ready QR image, generated on the server as a data: URI.
     *
     * SVG rather than PNG: the SVG backend in bacon-qr-code needs neither imagick nor
     * gd, so it adds no PHP extension to the deployment requirements. And it is
     * sharper when scaled up anyway.
     *
     * A data: URI rather than a file path: the image carries the secret, and writing
     * it to disk means a file holding a live secret under public/ — precisely what we
     * are avoiding. It lives in the response alone and then it is gone.
     */
    public static function getQrCodeUrl(string $secret, string $accountEmail, string $issuer = 'Store'): string
    {
        $renderer = new ImageRenderer(new RendererStyle(250), new SvgImageBackEnd());
        $svg      = (new Writer($renderer))->writeString(self::provisioningUri($secret, $accountEmail, $issuer));

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * The otpauth:// URI as authenticator apps understand it.
     *
     * It is also shown to the admin as text when scanning the image is not possible —
     * which is why it is a method of its own rather than a line inside the image
     * generator.
     */
    public static function provisioningUri(string $secret, string $accountEmail, string $issuer = 'Store'): string
    {
        $totp = self::totp($secret);
        $totp->setLabel($accountEmail);
        $totp->setIssuer($issuer);

        return $totp->getProvisioningUri();
    }

    public static function verifyCode(string $secret, string $code): bool
    {
        return self::verifyAndGetSlice($secret, $code) !== null;
    }

    /**
     * Verifies the code and returns the time slice that matched it, or null.
     *
     * A bool alone is not enough to prevent reuse: the ±30-second window leaves a
     * single code valid for ninety seconds, so whoever catches it (over a shoulder,
     * from a log, from a shared screen) can resend it within that. The caller stores
     * the returned slice and passes it back next time as $lastSlice.
     *
     * Comparison with hash_equals rather than ===: the timing difference between two
     * six-digit string comparisons is tiny and hard to exploit over a network, but the
     * cost is zero and the rule is uniform — any comparison touching a secret is done
     * in constant time.
     *
     * @param  int|null $lastSlice The last slice consumed for this account.
     * @return int|null The matching slice, or null on failure or when the code has
     *                  already been consumed.
     */
    public static function verifyAndGetSlice(string $secret, string $code, ?int $lastSlice = null): ?int
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $now = (int) floor(time() / self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $timeSlice = $now + $offset;

            if (!hash_equals(self::generateCode($secret, $timeSlice), $code)) {
                continue;
            }

            // The code is right — but has it been consumed? The rejection covers slices
            // older than the last consumed one too, not equality alone: without that, the
            // previous slice's code stays valid after the later one is used — a
            // thirty-second hole that opens at the very moment it is supposed to close.
            if ($lastSlice !== null && $timeSlice <= $lastSlice) {
                return null;
            }

            return $timeSlice;
        }

        return null;
    }

    /**
     * The code for a given slice.
     *
     * It stays private and keeps the same signature deliberately: the RFC 6238 vector
     * tests reach it through reflection, and they are what prove the implementation
     * really works with Google Authenticator rather than only with itself. Since the
     * move to the library, those tests guard **the integration with it** — which is
     * what needs guarding now.
     */
    private static function generateCode(string $secret, int $timeSlice): string
    {
        return self::totp($secret)->at($timeSlice * self::PERIOD);
    }

    /** A TOTP object with the project's settings: SHA-1 · six digits · thirty seconds. */
    private static function totp(string $secret): \OTPHP\TOTP
    {
        return \OTPHP\TOTP::createFromSecret(strtoupper(trim($secret)));
    }
}
