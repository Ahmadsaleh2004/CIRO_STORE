<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * env() and envBool() — they guard the decision of whether PHP's errors are shown to the
 * visitor. A fault here means leaking the server's paths and the database names.
 *
 * The two functions were the site of two real faults fixed in phase 1, and these tests are
 * what stops them coming back.
 */
final class EnvLoaderTest extends TestCase
{
    /** @var array<string, mixed> a copy of $_ENV before the test, restored in tearDown */
    private array $backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->backup;
        parent::tearDown();
    }

    public function testEnvReturnsTheValueWhenSet(): void
    {
        $_ENV['SOME_KEY'] = 'some-value';
        $this->assertSame('some-value', env('SOME_KEY'));
    }

    public function testEnvReturnsTheDefaultWhenKeyIsMissing(): void
    {
        unset($_ENV['MISSING_KEY']);
        $this->assertSame('fallback', env('MISSING_KEY', 'fallback'));
    }

    /**
     * The original fault: `$_ENV[$k] ?? getenv($k) ?: $default` parses as
     * `$_ENV[$k] ?? (getenv($k) ?: $default)` — so a key that exists with an empty value
     * returns "" and bypasses the default entirely.
     *
     * The practical effect: an `APP_ENV=` line in .env (a copy of .env.example left unfilled)
     * gave "" rather than 'production', so debug mode opened on a production server
     * silently.
     */
    public function testEnvTreatsAnEmptyValueAsAbsent(): void
    {
        $_ENV['BLANK_KEY'] = '';

        $this->assertSame(
            'production',
            env('BLANK_KEY', 'production'),
            'An empty key bypassed the default value — the precedence fault has returned.'
        );
    }

    public function testEnvReturnsNullWhenMissingAndNoDefaultGiven(): void
    {
        unset($_ENV['NOTHING_HERE']);
        $this->assertNull(env('NOTHING_HERE'));
    }

    /**
     * The second fault: every .env value is a string, and (bool)"false" is **true** in PHP.
     * So `APP_DEBUG=false` would have opened debug mode rather than closing it — the most
     * dangerous kind of fault, because it does the opposite of what its reader reads.
     */
    public function testEnvBoolReadsTheStringFalseAsFalse(): void
    {
        foreach (['false', 'FALSE', 'False', '0', 'no', 'off', 'anything'] as $value) {
            $_ENV['FLAG'] = $value;
            $this->assertFalse(
                envBool('FLAG', true),
                "The value [{$value}] was read as true — the (bool)\"false\" trap has returned."
            );
        }
    }

    public function testEnvBoolAcceptsTheTruthyForms(): void
    {
        foreach (['1', 'true', 'TRUE', 'yes', 'on', '  true  '] as $value) {
            $_ENV['FLAG'] = $value;
            $this->assertTrue(envBool('FLAG', false), "The value [{$value}] was not read as true.");
        }
    }

    public function testEnvBoolFallsBackToTheDefaultWhenAbsentOrBlank(): void
    {
        unset($_ENV['FLAG']);
        $this->assertTrue(envBool('FLAG', true));
        $this->assertFalse(envBool('FLAG', false));

        $_ENV['FLAG'] = '';
        $this->assertTrue(envBool('FLAG', true));
    }
}
