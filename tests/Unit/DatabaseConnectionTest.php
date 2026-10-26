<?php

namespace Tests\Unit;

use App\Core\Database;
use App\Core\DatabaseUnavailable;
use PHPUnit\Framework\TestCase;

/**
 * Two decisions in Database's connection path, frozen.
 *
 * 1. An unreachable database is thrown as DatabaseUnavailable, which extends \Error — so the
 *    models' `catch (Exception)` blocks cannot swallow it and turn an outage into "no
 *    products" or "wrong password", while /health can still catch it and answer JSON.
 *
 * 2. TLS options are added only when DB_SSL_CA is set, verification is always on with them,
 *    and the driver constants resolve on every supported PHP version (8.2 has only the old
 *    PDO::MYSQL_* names; 8.5 deprecates them in favour of Pdo\Mysql::*).
 */
final class DatabaseConnectionTest extends TestCase
{
    /**
     * The whole design rests on a model's catch (Exception) NOT matching the outage.
     *
     * There is no separate "is not an Exception" assertion: PHPStan resolves the class
     * hierarchy statically and rejects such a check as always-true. This test covers it
     * instead, through the path the models actually take — a future
     * `extends \RuntimeException` would land in the catch below and fail it.
     */
    public function testAModelStyleCatchLetsTheOutageThrough(): void
    {
        $this->expectException(DatabaseUnavailable::class);

        try {
            // Through a Throwable-typed rethrow, the way a model sees it: the try block only
            // knows "something was thrown", exactly as in the hundred and fifty model catches.
            $this->rethrow(new DatabaseUnavailable('down'));
        } catch (\Exception $e) {
            $this->fail('catch (Exception) swallowed the outage — the models would hide it.');
        }
    }

    private function rethrow(\Throwable $thrown): void
    {
        throw $thrown;
    }

    public function testNoCertificateMeansNoTlsOptions(): void
    {
        // The local database listens on 127.0.0.1 without TLS; nothing must change there.
        $this->assertSame([], Database::sslOptions(''));
    }

    public function testACertificateTurnsOnTlsWithVerification(): void
    {
        $ca = tempnam(sys_get_temp_dir(), 'ca');
        file_put_contents($ca, "-----BEGIN CERTIFICATE-----\n-----END CERTIFICATE-----\n");

        try {
            $options = Database::sslOptions($ca);
        } finally {
            unlink($ca);
        }

        $caAttribute     = defined('Pdo\Mysql::ATTR_SSL_CA')
            ? constant('Pdo\Mysql::ATTR_SSL_CA')
            : constant('PDO::MYSQL_ATTR_SSL_CA');
        $verifyAttribute = defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
            ? constant('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
            : constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT');

        $this->assertSame($ca, $options[$caAttribute]);
        // Encryption without verification would hand the password to anyone able to answer
        // in the server's place — so verification is not optional once TLS is on.
        $this->assertTrue($options[$verifyAttribute]);
    }

    public function testAMissingCertificateFileIsReportedByName(): void
    {
        // A misconfigured secret file must say what is wrong, not fail later inside the
        // driver as an unexplained TLS handshake error.
        $this->expectException(DatabaseUnavailable::class);
        // Missing, as distinct from present-but-unreadable — the case Render's secret files
        // actually produced, which the old single message could not tell apart.
        $this->expectExceptionMessage('DB_SSL_CA is set to /no/such/ca.pem, and there is no file there');

        Database::sslOptions('/no/such/ca.pem');
    }
}
