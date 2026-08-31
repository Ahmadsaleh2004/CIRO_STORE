<?php

namespace Tests\Integration;

use App\Core\Migrator;
use PDO;
use Tests\Support\DatabaseTestCase;

/**
 * The migrator — it applies schema changes with ordering, tracking and rollback.
 *
 * The tests run against a **temporary** migrations directory they build themselves, rather
 * than the real database/migrations. The reason is that the real files depend on tables
 * present in the baseline, and running them in a test means rebuilding the whole database
 * for every case — slow, and what would then be tested is the SQL files rather than the
 * migrator's logic.
 */
final class MigratorTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/cairo-migrations-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        $this->pdo->exec('DROP TABLE IF EXISTS `schema_migrations`');
        $this->pdo->exec('DROP TABLE IF EXISTS `mt_widgets`');
        $this->pdo->exec('DROP TABLE IF EXISTS `mt_gadgets`');
    }

    protected function tearDown(): void
    {
        // The early return is not a precaution. parent::setUp() skips the test when no test
        // database is available, and a skip is an exception — so setUp exits before assigning
        // $pdo and $dir, while tearDown runs regardless. The result was that every skip was
        // reported as an **error** ("typed property … before initialization"), producing
        // eighteen red results unrelated to the migrator on any machine without MySQL — and a
        // permanently red suite guards nothing, because the real failure is lost among them.
        if (!isset($this->pdo)) {
            parent::tearDown();
            return;
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);

        $this->pdo->exec('DROP TABLE IF EXISTS `schema_migrations`');
        $this->pdo->exec('DROP TABLE IF EXISTS `mt_widgets`');
        $this->pdo->exec('DROP TABLE IF EXISTS `mt_gadgets`');

        parent::tearDown();
    }

    private function write(string $filename, string $up, string $down = ''): void
    {
        $body = "-- @UP\n{$up}\n";
        if ($down !== '') {
            $body .= "\n-- @DOWN\n{$down}\n";
        }

        file_put_contents($this->dir . '/' . $filename, $body);
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->pdo, $this->dir);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    // ── Ordering ─────────────────────────────────────────────

    /**
     * The ordering comes from the version number rather than from the file system.
     *
     * This is the reason the migrator exists at all: the dependency used to be written as
     * prose in the comments ("depends on admin_auth.sql") with nothing enforcing it, so the
     * execution order followed the file system's ordering — which differs from machine to
     * machine.
     */
    public function testAppliesMigrationsInVersionOrder(): void
    {
        $this->write('0002_second.sql', 'CREATE TABLE `mt_gadgets` (`id` INT PRIMARY KEY);');
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');

        $done = $this->migrator()->up();

        $this->assertSame(['0001_first', '0002_second'], $done);
        $this->assertTrue($this->tableExists('mt_widgets'));
        $this->assertTrue($this->tableExists('mt_gadgets'));
    }

    public function testRejectsAFileWithoutAVersionNumber(): void
    {
        $this->write('no_version_here.sql', 'SELECT 1;');

        $this->expectException(\RuntimeException::class);
        $this->migrator()->available();
    }

    public function testRejectsDuplicateVersionNumbers(): void
    {
        $this->write('0001_one.sql', 'SELECT 1;');
        $this->write('0001_two.sql', 'SELECT 1;');

        // Two identical numbers mean the order of the pair is undefined — which is exactly
        // what the migrator came to prevent.
        $this->expectException(\RuntimeException::class);
        $this->migrator()->available();
    }

    // ── Tracking ─────────────────────────────────────────────

    public function testAnAppliedMigrationIsNotRunTwice(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');

        $this->assertSame(['0001_first'], $this->migrator()->up());

        // Were it run again it would fail with "table already exists" — which is what
        // actually happened while the files were run by hand with no tracking.
        $this->assertSame([], $this->migrator()->up());
    }

    public function testPendingShrinksAsMigrationsAreApplied(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');
        $this->write('0002_second.sql', 'CREATE TABLE `mt_gadgets` (`id` INT PRIMARY KEY);');

        $this->assertCount(2, $this->migrator()->pending());
        $this->migrator()->up();
        $this->assertCount(0, $this->migrator()->pending());
    }

    public function testPretendReportsWithoutTouchingTheDatabase(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');

        $done = $this->migrator()->up(true);

        $this->assertSame(['0001_first'], $done);
        $this->assertFalse($this->tableExists('mt_widgets'), 'pretend actually created the table.');
        $this->assertCount(1, $this->migrator()->pending(), 'pretend recorded the migration as applied.');
    }

    // ── Drift ────────────────────────────────────────────────

    /**
     * The most important thing the migrator guards.
     *
     * Editing a file that has already been applied is a silent fault of the worst kind: the
     * developer's database holds the old version and production's holds the new, and both say
     * "applied". Nothing reveals the difference until a query blows up on a column that does
     * not exist.
     */
    public function testDetectsAMigrationFileEditedAfterItWasApplied(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');
        $this->migrator()->up();

        $this->assertSame([], $this->migrator()->drifted());

        $this->write(
            '0001_first.sql',
            "CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY, `extra` VARCHAR(10));"
        );

        $this->assertCount(1, $this->migrator()->drifted());
    }

    public function testUpRefusesToRunWhileDriftIsUnresolved(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');
        $this->migrator()->up();

        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY, `x` INT);');
        $this->write('0002_second.sql', 'CREATE TABLE `mt_gadgets` (`id` INT PRIMARY KEY);');

        // Stopping is deliberate: applying a new migration over a database whose history has
        // drifted builds on an unknown foundation.
        $this->expectException(\RuntimeException::class);
        $this->migrator()->up();
    }

    public function testDetectsAnAppliedMigrationWhoseFileDisappeared(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');
        $this->migrator()->up();

        unlink($this->dir . '/0001_first.sql');

        $this->assertCount(1, $this->migrator()->drifted());
    }

    /**
     * A line ending does not count as drift.
     *
     * .gitattributes checks out CRLF on Windows and LF elsewhere, so a raw checksum would
     * differ between two machines for the same file with the same content — a false alarm
     * every time, and a repeated false alarm trains people to ignore it.
     */
    public function testLineEndingsDoNotCountAsDrift(): void
    {
        $sql = 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);';

        file_put_contents($this->dir . '/0001_first.sql', "-- @UP\n{$sql}\n");
        $this->migrator()->up();

        file_put_contents($this->dir . '/0001_first.sql', "-- @UP\r\n{$sql}\r\n");

        $this->assertSame([], $this->migrator()->drifted());
    }

    // ── Rollback ─────────────────────────────────────────────

    public function testRollsBackTheMostRecentMigration(): void
    {
        $this->write(
            '0001_first.sql',
            'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);',
            'DROP TABLE `mt_widgets`;'
        );
        $this->write(
            '0002_second.sql',
            'CREATE TABLE `mt_gadgets` (`id` INT PRIMARY KEY);',
            'DROP TABLE `mt_gadgets`;'
        );
        $this->migrator()->up();

        $done = $this->migrator()->down();

        $this->assertSame(['0002_second'], $done, 'The rollback started from the oldest rather than the newest.');
        $this->assertFalse($this->tableExists('mt_gadgets'));
        $this->assertTrue($this->tableExists('mt_widgets'), 'The rollback went past what was asked for.');
    }

    public function testRollsBackSeveralStepsInReverseOrder(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);', 'DROP TABLE `mt_widgets`;');
        $this->write('0002_second.sql', 'CREATE TABLE `mt_gadgets` (`id` INT PRIMARY KEY);', 'DROP TABLE `mt_gadgets`;');
        $this->migrator()->up();

        $this->assertSame(['0002_second', '0001_first'], $this->migrator()->down(2));
        $this->assertCount(2, $this->migrator()->pending());
    }

    public function testRollingBackAMigrationWithoutADownSectionThrows(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');
        $this->migrator()->up();

        // An explicit refusal is better than a half rollback leaving the database between
        // two states.
        $this->expectException(\RuntimeException::class);
        $this->migrator()->down();
    }

    // ── The baseline ─────────────────────────────────────────

    /**
     * baseline records without running.
     *
     * The seven existing migrations are already imprinted in tests/fixtures/schema.sql, so
     * running them over a database built from it fails with "table already exists".
     */
    public function testBaselineRecordsWithoutExecuting(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');

        $this->assertSame(1, $this->migrator()->baseline());
        $this->assertFalse($this->tableExists('mt_widgets'), 'baseline executed the script.');
        $this->assertSame([], $this->migrator()->pending());
    }

    public function testBaselineIsIdempotent(): void
    {
        $this->write('0001_first.sql', 'CREATE TABLE `mt_widgets` (`id` INT PRIMARY KEY);');

        $this->assertSame(1, $this->migrator()->baseline());
        $this->assertSame(0, $this->migrator()->baseline());
    }

    // ── Sections ─────────────────────────────────────────────

    public function testSectionsAreParsedIndependently(): void
    {
        $this->write('0001_first.sql', 'SELECT 1;', 'SELECT 2;');
        $path = $this->dir . '/0001_first.sql';

        $this->assertSame('SELECT 1;', $this->migrator()->section($path, 'UP'));
        $this->assertSame('SELECT 2;', $this->migrator()->section($path, 'DOWN'));
    }

    public function testAMissingSectionYieldsAnEmptyString(): void
    {
        $this->write('0001_first.sql', 'SELECT 1;');

        $this->assertSame('', $this->migrator()->section($this->dir . '/0001_first.sql', 'DOWN'));
    }

    // ── The real migrations ──────────────────────────────────

    /**
     * The actual database/migrations files are all well formed.
     *
     * They are not run here — they depend on tables from the baseline. But their structure is
     * checked: a valid version number, a non-empty @UP section, and a @DOWN section present.
     */
    public function testEveryRealMigrationIsWellFormed(): void
    {
        $real = new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations');
        $problems = [];

        foreach ($real->available() as $migration) {
            $label = $migration['version'] . '_' . $migration['name'];

            if ($real->section($migration['path'], 'UP') === '') {
                $problems[] = "{$label} — the @UP section is empty.";
            }
            if ($real->section($migration['path'], 'DOWN') === '') {
                $problems[] = "{$label} — no @DOWN section; if rolling back is impossible, write the reason.";
            }
        }

        // 10 since 0010_order_address_snapshot (an order's address is a snapshot, not a
        // reference: address_id was a live key with ON DELETE SET NULL, so a user editing
        // their address changed the destination of an order already delivered, and deleting
        // it erased completed orders' addresses permanently).
        // 11 since 0011_server_side_cart (the cart follows the user, not the browser: it was
        // in localStorage so it did not cross their devices and was lost when browser data
        // was cleared — and losing a full cart is a lost sale, not a UI annoyance).
        // 12 since 0012_slider_item_title (a title line above the description on the slider's
        // image: the text field was a single one, so it carried two competing roles — a title
        // that identifies and a description that explains — and the image showed one of them
        // and not both).
        $this->assertCount(12, $real->available(), 'The migration count changed — update this test deliberately, not by oversight.');
        $this->assertSame([], $problems, "Malformed migrations:\n  " . implode("\n  ", $problems));
    }

    /**
     * A multi-byte comment stays a comment after extraction.
     *
     * This test guards a fault that was entirely silent: section() split the lines with
     * `preg_split('/\R/')`, and `\R` without the /u modifier matches the byte `\x85` — which
     * is a legitimate continuation byte inside Arabic letters such as "م" (D9 85). So every
     * Arabic comment line was cut in the middle of a character, and its remainder became a
     * line not starting with `--`, that is, text handed to PDO::exec as though it were SQL.
     *
     * And the fault never appeared because the first seven migrations were recorded by
     * baseline without being run — the first real call to up() is what hit it.
     *
     * The migration is built here rather than read from database/migrations: those files are
     * English now, so reading them would make this test pass over a case it never exercises.
     * Writing the multi-byte comment ourselves keeps the guard real whatever language the
     * repository's own migrations are written in.
     */
    public function testMultiByteCommentsSurviveSectionExtraction(): void
    {
        // The comment carries the exact bytes that broke it: "م" is D9 85, and the second
        // byte is what an unescaped \R matches as a line separator.
        $this->write(
            '0001_first.sql',
            "-- ملاحظة عربية تحمل البايت 0x85
CREATE TABLE mt_widgets (id INT PRIMARY KEY);",
            '-- تعليق آخر
DROP TABLE mt_widgets;'
        );

        $migrator = $this->migrator();
        $path     = $this->dir . '/0001_first.sql';
        $mangled  = [];

        foreach (['UP', 'DOWN'] as $part) {
            foreach (preg_split('/

|
|
/', $migrator->section($path, $part)) ?: [] as $n => $line) {
                $line = ltrim($line);
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                // A non-ASCII letter opening a line that is not a comment = a severed comment.
                if (preg_match('/^[^ -]/u', $line)) {
                    $mangled[] = sprintf('@%s line %d: %s', $part, $n + 1, mb_substr($line, 0, 40));
                }
            }
        }

        $this->assertSame(
            [],
            $mangled,
            "A multi-byte comment was severed and became SQL:
  " . implode("
  ", $mangled)
        );

        // And it really does run: a severed comment reaches PDO::exec as a syntax error.
        $migrator->up();
        $this->assertTrue($this->tableExists('mt_widgets'));
    }
}
