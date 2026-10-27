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
            // Every execute() binds integers as integers — see TypedStatement for the
            // MySQL fault that made this necessary once prepares were emulated.
            PDO::ATTR_STATEMENT_CLASS    => [TypedStatement::class],
            // Emulated: PDO writes the escaped values into the SQL and sends one query.
            // With native prepares every prepare()+execute() was two round trips — PREPARE,
            // then EXECUTE — and the database is a network hop away from Render, ~115–150 ms
            // each, paid by every query on every page. /products alone ran 22 queries.
            //
            // What it costs to have this safely, checked before switching:
            //   · escaping is done by the driver against the connection's charset, which the
            //     DSN sets (utf8mb4) — the setting that makes emulation safe;
            //   · results keep their native int/float types: PHP 8.1 made emulated prepares
            //     return them as native prepares do, and the floor is 8.2;
            //   · PDO's own execute([...]) binds every value as a string, and that breaks
            //     `LIMIT '9'` (a syntax error) and LEAST() (compared as text on MySQL, where
            //     '10' < '3'). TypedStatement, set just above, binds integers as integers on
            //     every statement. tests/bootstrap.php connects the same way, so the suite
            //     runs this mode.
            PDO::ATTR_EMULATE_PREPARES   => true,

            // Without a connect timeout the request hangs for as long as PHP lets it.
            // That is not a theoretical risk — it happened here: a MariaDB whose startup
            // aborted after binding port 3306 still completed the TCP handshake and then
            // never sent its greeting, so every page load stalled with no error at all.
            //
            // ⚠️ This alone is **not** the whole fix, and the gap is easy to miss.
            // ATTR_TIMEOUT becomes MYSQL_OPT_CONNECT_TIMEOUT, which bounds the connect
            // and nothing after it. Waiting for the server's reply — the greeting and
            // every query result — is bounded by `mysqlnd.net_read_timeout` in php.ini,
            // whose default is 86400: a full day. Measured against a socket that accepts
            // and never answers, this option alone still hung past 140 seconds; with
            // net_read_timeout set, the same case threw in 5.0.
            //
            // Five seconds is far longer than a healthy local or same-network connect
            // needs, and it hands control to the catch block below, which already
            // answers with a proper 503 instead of a page that never finishes loading.
            PDO::ATTR_TIMEOUT            => 5,
        ];

        // Encryption to the database, when the deployment asks for it. Locally DB_SSL_CA is
        // empty and this adds nothing; on the live server it carries the provider's CA.
        $options += self::sslOptions(DB_SSL_CA);

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
            //
            // Thrown rather than rendered here. This line used to call
            // ErrorPage::serverError() itself, which ends the request — so the try/catch in
            // HealthController could never run and /health answered an outage with an HTML
            // page instead of {"status":"fail"}. The global handler in
            // app/config/monitoring.php now renders the same 503 for any caller that does
            // not catch it, and DatabaseUnavailable explains why it extends \Error: the
            // models' catch (Exception) blocks must not swallow it.
            throw new DatabaseUnavailable('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The PDO options for an encrypted, verified connection — or none, when $caPath is empty.
     *
     * Aiven, where the live database runs, refuses unencrypted connections outright
     * (require_secure_transport). The CA is passed so the server's certificate is CHECKED,
     * not merely accepted: encryption without verification stops a passive eavesdropper but
     * hands the password to anyone able to answer in the server's place.
     *
     * ⚠️ The constants are looked up by name because PHP renamed them. 8.4 introduced
     * Pdo\Mysql::ATTR_SSL_CA and 8.5 deprecates the old PDO::MYSQL_ATTR_SSL_CA — while the
     * project still supports 8.2, where only the old one exists. The deployed image runs
     * 8.5 and CI tests 8.2, so naming either constant directly breaks one of them.
     *
     * @return array<int, mixed>
     * @throws DatabaseUnavailable When the path is set but the file cannot be read — a
     *                             misconfigured secret file would otherwise surface as a
     *                             cryptic TLS handshake error from the driver.
     */
    public static function sslOptions(string $caPath): array
    {
        if ($caPath === '') {
            return [];
        }

        // Two failures, two messages. "Not a readable file" used to cover both, and the first
        // one seen on Render was a permissions problem, not a missing file — the message gave
        // no way to tell them apart from the log alone.
        if (!is_file($caPath)) {
            throw new DatabaseUnavailable("DB_SSL_CA is set to {$caPath}, and there is no file there");
        }
        if (!is_readable($caPath)) {
            $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? 'this process')
                : 'this process';
            throw new DatabaseUnavailable("DB_SSL_CA is set to {$caPath}, which exists but {$user} cannot read it");
        }

        return [
            self::mysqlAttribute('ATTR_SSL_CA')                 => $caPath,
            self::mysqlAttribute('ATTR_SSL_VERIFY_SERVER_CERT') => true,
        ];
    }

    /** The driver attribute under its 8.4+ name when it exists, else under the old one. */
    private static function mysqlAttribute(string $name): int
    {
        $modern = 'Pdo\Mysql::' . $name;

        return (int) (defined($modern) ? constant($modern) : constant('PDO::MYSQL_' . $name));
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
