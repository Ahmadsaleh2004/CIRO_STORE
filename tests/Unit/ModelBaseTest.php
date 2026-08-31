<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The shared base for the models — one place that knows about the database.
 *
 * `Database::connect()` was written 156 times across sixteen models. And the problem was not
 * coupling — swapping the data source already works through Database::setConnection(), and
 * every integration test in this project relies on that — it was **duplication**: one line
 * repeated 156 times.
 *
 * And these tests guard the gain. One new line calling Database::connect() directly in a
 * model reopens the door, and it appears in no behavioural test — because it works
 * perfectly. Its only consequence is that it accumulates until we are back at 156.
 */
final class ModelBaseTest extends TestCase
{
    /** @return list<string> */
    private static function modelFiles(): array
    {
        return glob(dirname(__DIR__, 2) . '/app/Models/*.php') ?: [];
    }

    public function testTheModelDirectoryIsNotEmpty(): void
    {
        // A guard over the guard: a wrong path would make the two checks below run over an
        // empty list, declaring success without checking anything.
        $this->assertGreaterThan(10, count(self::modelFiles()));
    }

    public function testEveryModelExtendsTheSharedBase(): void
    {
        $offenders = [];

        foreach (self::modelFiles() as $path) {
            $src = (string) file_get_contents($path);

            if (!preg_match('/^class\s+\w+\s+extends\s+Model\b/m', $src)) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Models off the shared base:\n  " . implode("\n  ", $offenders)
        );
    }

    public function testNoModelOpensItsOwnConnection(): void
    {
        $offenders = [];

        foreach (self::modelFiles() as $path) {
            // The comments are stripped: the models explain what was replaced, and that
            // explanation is what prevents it coming back.
            $src = (string) file_get_contents($path);
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (str_contains($src, 'Database::connect(')) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Models opening their own connection instead of self::db():\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * Classes outside the model layer that are allowed to open a connection — each with its
     * reason.
     *
     * The list is deliberately short, and every addition to it is a decision to be justified
     * rather than an oversight to be forgiven.
     * (The same pattern used in CsrfContractHttpTest::DOCUMENTED_EXEMPTIONS.)
     */
    private const DOCUMENTED_EXEMPTIONS = [
        'Core/Throttle.php' =>
            'Infrastructure with a table of its own (throttle_attempts) that represents no '
            . 'domain entity. Making it a model would put the request guard in the data layer.',

        'Core/Mailer.php' =>
            'It sends mail; the queue is a detail of its implementation rather than of its '
            . 'identity. Inheriting Model would say it is a model, and it is not.',

        'Controllers/HealthController.php' =>
            'It tests the connection itself — /health answers "is the database responding?". '
            . 'Going through a model measures the model rather than the connection.',
    ];

    /**
     * No connection is opened outside the model layer without a stated justification.
     *
     * Without this check the call could migrate from the models into the services or the
     * controllers — scattering again with nobody noticing, which is exactly how things stood
     * before the shared base.
     */
    public function testOnlyTheBaseAndItsOwnLayerKnowAboutDatabase(): void
    {
        $allowed   = ['Model.php', 'Database.php'];
        $offenders = [];

        foreach (glob(dirname(__DIR__, 2) . '/app/Core/*.php') ?: [] as $path) {
            if (in_array(basename($path), $allowed, true)) {
                continue;
            }

            $src = (string) file_get_contents($path);
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (str_contains($src, 'Database::connect(')) {
                $offenders[] = 'Core/' . basename($path);
            }
        }

        foreach (glob(dirname(__DIR__, 2) . '/app/Controllers/*.php') ?: [] as $path) {
            $src = (string) file_get_contents($path);
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (str_contains($src, 'Database::connect(')) {
                $offenders[] = 'Controllers/' . basename($path);
            }
        }

        $undocumented = array_values(array_diff($offenders, array_keys(self::DOCUMENTED_EXEMPTIONS)));

        $this->assertSame(
            [],
            $undocumented,
            "A database connection is opened outside the model layer with no stated justification:\n  "
            . implode("\n  ", $undocumented)
        );
    }

    /**
     * And no exception outlives its reason.
     *
     * An exception list that is never reviewed turns into an ignore list: the site is fixed
     * and its name stays on the list, silently covering a later return of the same fault.
     */
    public function testNoExemptionOutlivesItsReason(): void
    {
        $root  = dirname(__DIR__, 2) . '/app/';
        $stale = [];

        foreach (self::DOCUMENTED_EXEMPTIONS as $relative => $reason) {
            $path = $root . $relative;

            if (!is_file($path)) {
                $stale[] = "{$relative} — the file no longer exists";
                continue;
            }

            $src = (string) file_get_contents($path);
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (!str_contains($src, 'Database::connect(')) {
                $stale[] = "{$relative} — it no longer opens a connection; remove it from the list";
            }
        }

        $this->assertSame([], $stale, "Exceptions that have lost their reason:\n  " . implode("\n  ", $stale));
    }
}
