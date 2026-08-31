<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Scrubbing the monitoring data before it leaves the server.
 *
 * ══════════════════════════════════════════════════════════════
 * Why a test on this function in particular
 * ══════════════════════════════════════════════════════════════
 *
 * `monitoringScrub` is the last thing standing between `$_POST` and a third party. And the
 * request context Sentry sends with every error includes the whole request body — that is,
 * the passwords, the CSRF tokens, the 2FA codes and the recovery keys.
 *
 * And its failure appears on no screen and fails no request: all that happens is that a
 * secret leaves for another server. There is no way to discover it except a test that asks
 * the function directly — and this is it.
 *
 * ⚠️ Whoever adds a sensitive field to any form is responsible for adding it to
 * MONITORING_SCRUB_KEYS, and for a line here proving it is scrubbed.
 */
final class MonitoringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The file is loaded from config.php in a normal run; the test loads it on its own
        // so it stays a unit that needs no environment.
        require_once dirname(__DIR__, 2) . '/app/config/monitoring.php';
    }

    /** @return list<array{0: string}> */
    public static function sensitiveKeys(): array
    {
        return [
            ['password'],
            ['confirm_password'],
            ['current_password'],
            ['new_password'],
            ['csrf_token'],
            ['token'],
            ['totp_code'],
            ['totp_secret'],
            ['secret'],
            ['h-captcha-response'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sensitiveKeys')]
    public function testASensitiveKeyNeverKeepsItsValue(string $key): void
    {
        $out = monitoringScrub([$key => 'THE-ACTUAL-SECRET']);

        $this->assertSame('[scrubbed]', $out[$key]);
        $this->assertStringNotContainsString('THE-ACTUAL-SECRET', json_encode($out) ?: '');
    }

    /**
     * The recursion is not a detail: a JSON body reaches the controllers as a nested array
     * (checkout sends `items`, for instance), and scrubbing the first level alone gives a
     * feeling of safety without the safety.
     */
    public function testNestedSecretsAreScrubbedToo(): void
    {
        $out = monitoringScrub([
            'order' => [
                'items'      => [['qty' => 2, 'csrf_token' => 'LEAK-1']],
                'auth'       => ['current_password' => 'LEAK-2'],
            ],
        ]);

        $encoded = json_encode($out) ?: '';

        $this->assertStringNotContainsString('LEAK-1', $encoded);
        $this->assertStringNotContainsString('LEAK-2', $encoded);
        $this->assertSame(2, $out['order']['items'][0]['qty'], 'The non-sensitive data changed.');
    }

    public function testTheKeyMatchIsCaseInsensitive(): void
    {
        // The field names come from forms written by people. `CSRF_Token` and `Password`
        // are both likely, and case sensitivity here would mean a silent hole.
        $out = monitoringScrub(['Password' => 'X', 'CSRF_TOKEN' => 'Y']);

        $this->assertSame('[scrubbed]', $out['Password']);
        $this->assertSame('[scrubbed]', $out['CSRF_TOKEN']);
    }

    public function testOrdinaryFieldsSurviveUntouched(): void
    {
        // A scrub that erases everything makes the report useless. The purpose is hiding the
        // secrets, not hiding the fault.
        $in  = ['email' => 'a@b.c', 'qty' => 3, 'note' => 'hello', 'items' => [1, 2]];
        $out = monitoringScrub($in);

        $this->assertSame($in, $out);
    }

    /**
     * Every key in the list must be lower case.
     *
     * The comparison lower-cases **the incoming key** rather than the list's key, so an entry
     * in the list written in capitals never matches anything — while still looking as though
     * it protects.
     */
    public function testEveryConfiguredKeyIsLowercase(): void
    {
        foreach (MONITORING_SCRUB_KEYS as $key) {
            $this->assertSame(
                strtolower($key),
                $key,
                "The key [{$key}] is in capitals — it will never match anything."
            );
        }
    }
}
