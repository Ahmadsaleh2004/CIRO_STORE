<?php

namespace Tests\Integration;

use App\Core\Throttle;
use Tests\Support\DatabaseTestCase;

/**
 * Throttle — the general attempt counter for the sensitive entry points.
 *
 * It is tested against a real database rather than an in-memory substitute, because half of
 * its logic is in the SQL itself: the window is computed with DATE_SUB inside MySQL, and the
 * composite index is what makes the count possible. A substitute in PHP would be testing
 * something else.
 *
 * The bucket used here is defined locally and matches no real bucket in the route table: the
 * test examines the mechanism, and tying it to production names would make it fail every
 * time a limit changed on a route that has nothing to do with it.
 */
final class ThrottleTest extends DatabaseTestCase
{
    private const BUCKET = 'test-bucket';
    private const WHO    = '203.0.113.7';

    public function testAFreshIdentifierIsNotThrottled(): void
    {
        $this->assertFalse(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));
    }

    public function testTheLimitIsReachedAtTheThresholdNotAfterIt(): void
    {
        // A limit of 3 means "three attempts allowed, and the fourth refused". An off-by-one
        // here is not cosmetic: >= instead of > effectively doubles what the guard permits on
        // every endpoint in the project.
        Throttle::record(self::BUCKET, self::WHO);
        Throttle::record(self::BUCKET, self::WHO);
        $this->assertFalse(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));

        Throttle::record(self::BUCKET, self::WHO);
        $this->assertTrue(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));
    }

    /**
     * The buckets are isolated: exhausting one does not close another.
     *
     * Without that isolation, somebody trying password recovery locks the sign-in door on
     * themselves — and the two endpoints have nothing to do with one another.
     */
    public function testBucketsAreIsolatedFromEachOther(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Throttle::record('bucket-one', self::WHO);
        }

        $this->assertTrue(Throttle::tooMany('bucket-one', self::WHO, 3, 15));
        $this->assertFalse(Throttle::tooMany('bucket-two', self::WHO, 3, 15));
    }

    /**
     * And the sources are isolated: throttling one does not throttle another.
     *
     * This is the fundamental difference from an email-based throttle — and were this
     * isolation to collapse, one attacker could lock the site against every visitor.
     */
    public function testIdentifiersAreIsolatedFromEachOther(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Throttle::record(self::BUCKET, self::WHO);
        }

        $this->assertTrue(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));
        $this->assertFalse(Throttle::tooMany(self::BUCKET, '198.51.100.4', 3, 15));
    }

    /**
     * What has left the window is not counted.
     *
     * The record is planted with an old date directly rather than waiting: a test that sleeps
     * for a quarter of an hour to prove a window expired is a test that never gets run.
     */
    public function testAttemptsOlderThanTheWindowDoNotCount(): void
    {
        $this->pdo->exec(
            "INSERT INTO throttle_attempts (bucket, identifier, attempted_at) VALUES
             ('" . self::BUCKET . "', '" . self::WHO . "', DATE_SUB(NOW(), INTERVAL 40 MINUTE)),
             ('" . self::BUCKET . "', '" . self::WHO . "', DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
             ('" . self::BUCKET . "', '" . self::WHO . "', DATE_SUB(NOW(), INTERVAL 20 MINUTE))"
        );

        // A 15-minute window: all three are outside it.
        $this->assertFalse(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));

        // A 60-minute window: all three are inside it.
        $this->assertTrue(Throttle::tooMany(self::BUCKET, self::WHO, 3, 60));
    }

    public function testClearRemovesTheIdentifiersTraceOnThatBucketOnly(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Throttle::record(self::BUCKET, self::WHO);
            Throttle::record('other-bucket', self::WHO);
        }

        Throttle::clear(self::BUCKET, self::WHO);

        $this->assertFalse(Throttle::tooMany(self::BUCKET, self::WHO, 3, 15));
        $this->assertTrue(Throttle::tooMany('other-bucket', self::WHO, 3, 15));
    }

    /**
     * The sweep deletes the old and leaves anything inside the retention period untouched.
     *
     * Both bounds together: were it to delete the recent, the throttle would have no memory,
     * and were it to keep the old, the table would grow without bound in a project with no
     * guaranteed cron.
     */
    public function testRecordPrunesOnlyTracesOlderThanTheRetention(): void
    {
        $this->pdo->exec(
            "INSERT INTO throttle_attempts (bucket, identifier, attempted_at) VALUES
             ('" . self::BUCKET . "', '" . self::WHO . "', DATE_SUB(NOW(), INTERVAL 3 DAY)),
             ('" . self::BUCKET . "', '" . self::WHO . "', DATE_SUB(NOW(), INTERVAL 2 HOUR))"
        );

        Throttle::record(self::BUCKET, self::WHO);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM throttle_attempts WHERE bucket = ? AND identifier = ?'
        );
        $stmt->execute([self::BUCKET, self::WHO]);

        // The two-hour record and the new one remain; the three-day record is gone.
        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    /**
     * The source address is not read from a header the client sends.
     *
     * X-Forwarded-For is entirely forgeable: were it read without a trusted proxy in front,
     * bypassing the throttle would be a matter of changing a header on every request — that
     * is, the guard becomes decoration. This test prevents a later "improvement" opening it.
     */
    public function testClientIpIgnoresForwardedHeaders(): void
    {
        $_SERVER['REMOTE_ADDR']          = '192.0.2.10';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $this->assertSame('192.0.2.10', Throttle::clientIp());

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }
}
