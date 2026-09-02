<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * isLocalUrl — "is this deployment a developer's own copy?"
 *
 * Two separate defects turned on this one question, which is why it is a shared helper and
 * why it is tested on its own rather than through either caller:
 *
 *   · the live store told Google to send every visitor back to the development machine,
 *     because GOOGLE_REDIRECT_URI still held the localhost value;
 *   · the local admin login could not be signed into at all, because hCaptcha refuses to
 *     issue a token to localhost while the server went on demanding one.
 *
 * The security-relevant case is the last group: the answer must depend on APP_URL, which is
 * server configuration, and never on the Host header, which the client sends.
 */
final class IsLocalUrlTest extends TestCase
{
    public function testLocalhostIsLocal(): void
    {
        $this->assertTrue(isLocalUrl('http://localhost/STORE/public'));
        $this->assertTrue(isLocalUrl('http://localhost:8080/'));
    }

    public function testLoopbackAddressesAreLocal(): void
    {
        $this->assertTrue(isLocalUrl('http://127.0.0.1:8080/auth/google/callback'));
        $this->assertTrue(isLocalUrl('http://[::1]/'));
    }

    public function testASubdomainOfLocalhostIsLocal(): void
    {
        // app.localhost resolves to the loopback in every modern browser.
        $this->assertTrue(isLocalUrl('http://app.localhost:3000/'));
    }

    public function testARealDeploymentIsNotLocal(): void
    {
        $this->assertFalse(isLocalUrl('https://cairo-store.onrender.com'));
        $this->assertFalse(isLocalUrl('https://shop.example.com/auth/google/callback'));
    }

    public function testAHostMerelyCONTAININGLocalhostIsNotLocal(): void
    {
        // The substring test this replaces would have called both of these local, and the
        // first is a domain anybody can register.
        $this->assertFalse(isLocalUrl('https://localhost.example.com/'));
        $this->assertFalse(isLocalUrl('https://mylocalhost.com/'));
    }

    public function testAnEmptyOrHostlessUrlIsNotLocal(): void
    {
        // Not local means "treated as production" — the safe direction for a value that
        // gates a captcha.
        $this->assertFalse(isLocalUrl(''));
        $this->assertFalse(isLocalUrl('/auth/google/callback'));
    }
}
