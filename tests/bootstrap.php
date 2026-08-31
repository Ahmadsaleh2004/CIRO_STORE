<?php

/**
 * tests/bootstrap.php
 * Setting up the test environment.
 *
 * Two responsibilities only:
 *   1. loading the autoloader and the project's constants (APPROOT, URLROOT, DB_*).
 *   2. building a **separate** test database and preparing a connection to it.
 *
 * ⚠️ The database used is `<DB_NAME>_test`, not the development one. The separation is not
 * a precaution: the integration tests empty the tables between every test, so running them
 * against the development database would erase all of its data on the first run. The name is
 * derived rather than written, so it cannot drift from .env if that changes.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The tests run on the CLI only.\n");
    exit(1);
}

/** The test database's name — derived from the project's database with a _test suffix. */
define('TEST_DB_NAME', DB_NAME . '_test');

/**
 * Builds a connection to the MySQL server without selecting a database.
 * It returns null if the connection fails — so the integration tests skip themselves rather
 * than the whole run failing. The unit tests need no database in the first place.
 */
function testServerConnection(): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($tried) {
        return $pdo;
    }
    $tried = true;

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        $pdo = null;
    }

    return $pdo;
}

/**
 * Creates the test database and loads its schema once per run.
 *
 * The schema comes from tests/fixtures/schema.sql — a structural copy of the real database
 * with no data. It is regenerated with `composer test:schema` whenever the schema changes;
 * and if somebody forgets, the integration tests fail plainly rather than passing against an
 * old structure.
 */
function prepareTestDatabase(): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($tried) {
        return $pdo;
    }
    $tried = true;

    $server = testServerConnection();
    if ($server === null) {
        return null;
    }

    $schemaFile = __DIR__ . '/fixtures/schema.sql';
    if (!is_file($schemaFile)) {
        return null;
    }

    $name = TEST_DB_NAME;

    // The database name is derived from .env rather than from user input, but backtick
    // quoting remains correct for names containing special characters.
    $quoted = '`' . str_replace('`', '``', $name) . '`';

    $server->exec("DROP DATABASE IF EXISTS {$quoted}");
    $server->exec("CREATE DATABASE {$quoted} CHARACTER SET " . DB_CHARSET);

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $name . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // The schema's statements are executed in one go. mysqldump separates them with
    // semicolons at line ends, and exec accepts multiple statements over a MySQL connection.
    $pdo->exec(file_get_contents($schemaFile));

    return $pdo;
}
