<?php

namespace Tests\Support;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The base for the integration tests.
 *
 * It does three things for every test:
 *   1. skips the whole test if no test database is available — so `composer test` stays
 *      runnable on a machine without MySQL, and so the unit tests do not fail over the
 *      absence of a service they do not need.
 *   2. injects the test database's connection into Database — so all 158 model queries go
 *      there rather than to the development database.
 *   3. empties the tables before every test, so no test inherits its predecessor's state.
 *
 * The emptying comes before the test rather than after it, deliberately: a failing test
 * leaves its data in the database to be examined, and the next test cleans up before it
 * starts.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = prepareTestDatabase();
        if ($pdo === null) {
            $this->markTestSkipped(
                'The test database is unavailable (MySQL is not responding, or tests/fixtures/schema.sql is missing).'
            );
        }

        $this->pdo = $pdo;
        Database::setConnection($pdo);
        $this->truncateAll();
    }

    protected function tearDown(): void
    {
        // Clearing the injection so the test's connection does not leak past it.
        Database::reset();
        parent::tearDown();
    }

    /**
     * Empties every table in the test database.
     *
     * The foreign key check is switched off temporarily: the tables are interlinked
     * (orders → order_items → products), and any fixed emptying order would break the moment
     * a new relationship is added. Switching it off makes the order irrelevant.
     *
     * **DELETE rather than TRUNCATE** — and the difference is measured, not assumed:
     *
     *     TRUNCATE (28 tables):  8.585 seconds
     *     DELETE   (28 tables):  0.256 seconds   ← 33 times faster
     *
     * The reason is that TRUNCATE in InnoDB drops the table's space and recreates it, which
     * is a file-system operation rather than a row one — so its cost is constant however
     * empty the table is. And DELETE on an empty table does almost nothing.
     *
     * Reducing the number of round trips was tried first (one statement instead of 29), and
     * it improved nothing — it made the time worse. That is, the bottleneck was never in the
     * network at all. Measurement is what revealed that; guessing would have left the
     * problem in place.
     *
     * The effect: a slow test suite does not get run, and a suite that does not get run
     * protects nothing.
     *
     * Note: DELETE does not reset AUTO_INCREMENT. No test here depends on a particular id
     * (they all read lastInsertId), so the difference has no effect — and should a later
     * test need it, that test should reset it explicitly itself.
     */
    protected function truncateAll(): void
    {
        // ⚠️ The list is read every time, and **never cached**.
        //
        // It was cached in a static field as an optimisation, and the optimisation was
        // wrong in two ways: first, the measurement proved the bottleneck was TRUNCATE
        // itself rather than the number of round trips (see below), so the caching saved
        // nothing worth mentioning. And second — the more important — it assumes a fixed
        // schema for the whole run.
        //
        // And MigratorTest breaks that assumption: it drops schema_migrations and creates
        // temporary tables. So the list cached from the first test becomes a lie, and
        // seventeen tests fail with "Base table doesn't exist".
        //
        // A SHOW TABLES query is one round trip taking fractions of a millisecond. The real
        // cost was somewhere else.
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        $sql = 'SET FOREIGN_KEY_CHECKS = 0; ';
        foreach ($tables as $table) {
            $sql .= 'DELETE FROM `' . str_replace('`', '``', $table) . '`; ';
        }
        $sql .= 'SET FOREIGN_KEY_CHECKS = 1;';

        $this->pdo->exec($sql);
    }

    /** Counts a table's rows — a helper needed in almost every test. */
    protected function countRows(string $table): int
    {
        return (int) $this->pdo
            ->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')
            ->fetchColumn();
    }
}
