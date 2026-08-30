<?php

namespace App\Core;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    /**
     * An injected connection that takes precedence over the real one — for tests only.
     *
     * This is **all** the project needed to become testable. The models are all
     * static and call Database::connect() — one hundred and fifty-eight sites — so
     * there is a single choke point, and having it return a different connection is
     * enough to make 4,827 lines of model testable without touching one line of them.
     *
     * And that is deliberate: the cleanup plan decided "the models stay static", and
     * the decision was right and has not been overturned. The alternative — turning
     * every model into an object receiving a PDO in its constructor — would have
     * changed one hundred and fifty-eight calls and all of their callers, for a gain
     * no greater than what these two lines already give.
     */
    private static ?PDO $injected = null;

    private function __construct()
    {
        // The constants are guaranteed: config.php defines all of them from .env, and
        // it is loaded before any access to this class from every entry point
        // (public/index.php and the scripts under scripts/). There used to be a
        // `defined(...) ? ... : $_ENV[...]` here for each key — a fallback that never
        // worked: the constants were **always** defined, from values written out in
        // config.php, so the second branch was dead and the .env file was read and then
        // ignored.
        $dsn = 'mysql:host=' . DB_HOST
             . ';port='     . DB_PORT
             . ';dbname='   . DB_NAME
             . ';charset='  . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // ⚠️ Never pass a PDO message to the browser. There used to be
            //     die("Database connection error: " . $e->getMessage())
            // here, and a PDO message carries the host name, the database name and the
            // user name verbatim — meaning the first connection error in production
            // handed the visitor half the credentials. And die ends the request with a
            // **200**, so search engines and monitoring tools read the broken page as a
            // healthy one.
            //
            // 503 rather than 500: a downed database means the service is temporarily
            // unavailable.
            ErrorPage::serverError('Database connection failed: ' . $e->getMessage(), 503);
        }
    }

    private function __clone()
    {
    }

    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton instance.");
    }

    /**
     * Get the single instance of the Database class (singleton gateway).
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * Get the PDO object.
     */
    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /**
     * A shorthand for getting a PDO directly: Database::connect().
     *
     * It checks the injected connection first. In production that is always null
     * (setConnection refuses to work outside the CLI), so the path is exactly as it
     * was.
     */
    public static function connect(): PDO
    {
        return self::$injected ?? self::getInstance()->getConnection();
    }

    /**
     * Injects a replacement connection — **for tests only**.
     *
     * ⚠️ Restricted to the CLI deliberately, and it throws outside it. The
     * restriction is not decoration: without it, a single request path calling this —
     * by oversight, or through an execution vulnerability — would redirect every
     * query in the application to a database the attacker controls. PHPUnit always
     * runs on the CLI, so the restriction costs the tests nothing.
     *
     * @throws \LogicException If called from a web context.
     */
    public static function setConnection(PDO $pdo): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException(
                'Database::setConnection() is a test-only seam and must never run outside CLI.'
            );
        }

        self::$injected = $pdo;
    }

    /**
     * Clears the injected connection and the singleton instance together.
     *
     * Called in tearDown so a test's connection does not leak into the next test.
     * Clearing $instance as well is deliberate: a test wanting a real connection after
     * an injecting test must build it afresh rather than inherit a stale one.
     */
    public static function reset(): void
    {
        self::$injected = null;
        self::$instance = null;
    }
}
