<?php

namespace Tests\Unit;

use App\Controllers\AuthController;
use PHPUnit\Framework\TestCase;

/**
 * AuthController::resolveGoogleRedirectUri — the address Google returns the visitor to.
 *
 * These exist because the fault they describe was invisible from inside the application.
 * The live store carried the development machine's GOOGLE_REDIRECT_URI, so Google was
 * asked to send every visitor back to http://localhost/STORE/public/auth/google/callback.
 * The refusal happened on Google's page: the store logged nothing, showed nothing, and
 * reported itself healthy while its only social sign-in was dead.
 *
 * The rule frozen here is narrow on purpose — a localhost value is overridden only when
 * the site itself is not local. A developer running on localhost must keep working, and a
 * remote value is never second-guessed, because a deployment behind a proxy or on a custom
 * domain may legitimately differ from APP_URL.
 */
final class GoogleRedirectUriTest extends TestCase
{
    private const LIVE  = 'https://cairo-store.onrender.com';
    private const LOCAL = 'http://localhost/STORE/public';

    public function testAnEmptyValueIsDerivedFromTheApplicationUrl(): void
    {
        $this->assertSame(
            'https://cairo-store.onrender.com/auth/google/callback',
            AuthController::resolveGoogleRedirectUri('', self::LIVE)
        );
    }

    public function testATrailingSlashOnTheApplicationUrlDoesNotDoubleUp(): void
    {
        $this->assertSame(
            'https://cairo-store.onrender.com/auth/google/callback',
            AuthController::resolveGoogleRedirectUri('', self::LIVE . '/')
        );
    }

    public function testALocalhostValueIsIgnoredWhenTheSiteIsRemote(): void
    {
        // The exact shape of the live fault.
        $this->assertSame(
            'https://cairo-store.onrender.com/auth/google/callback',
            AuthController::resolveGoogleRedirectUri(
                'http://localhost/STORE/public/auth/google/callback',
                self::LIVE
            )
        );
    }

    public function testALoopbackAddressIsTreatedAsLocalToo(): void
    {
        $this->assertSame(
            'https://cairo-store.onrender.com/auth/google/callback',
            AuthController::resolveGoogleRedirectUri(
                'http://127.0.0.1:8080/auth/google/callback',
                self::LIVE
            )
        );
    }

    public function testALocalhostValueIsKeptWhenTheSiteIsAlsoLocal(): void
    {
        // Development must keep working — this is the case the override must not touch.
        $configured = 'http://localhost/STORE/public/auth/google/callback';

        $this->assertSame(
            $configured,
            AuthController::resolveGoogleRedirectUri($configured, self::LOCAL)
        );
    }

    public function testARemoteValueIsNeverSecondGuessed(): void
    {
        // A proxy or a custom domain may legitimately differ from APP_URL.
        $configured = 'https://shop.example.com/auth/google/callback';

        $this->assertSame(
            $configured,
            AuthController::resolveGoogleRedirectUri($configured, self::LIVE)
        );
    }

    public function testWhitespaceCountsAsAbsent(): void
    {
        $this->assertSame(
            'https://cairo-store.onrender.com/auth/google/callback',
            AuthController::resolveGoogleRedirectUri('   ', self::LIVE)
        );
    }
}
