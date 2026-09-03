<?php

namespace Tests\Integration;

use App\Models\AdminModel;
use Tests\Support\DatabaseTestCase;

/**
 * The failed-attempt counter behind the admin captcha and the admin lockout.
 *
 * It is tested against a real database because the rule lives in the SQL: the window is a
 * DATE_SUB inside MySQL, and so is "since the last successful sign-in" — a correlated
 * subquery that a PHP substitute would not be exercising at all.
 *
 * The rule under test used to be missing, and its absence had a face: an administrator who
 * mistyped once, signed in successfully, signed out and came back was met by a captcha
 * demand minutes after proving they held the password — because the count still included
 * the failure from before the success. Combined with a captcha-failure response that did
 * not ask the page to render the widget, that was a locked door with no handle.
 */
final class AdminLoginCounterTest extends DatabaseTestCase
{
    private const EMAIL = 'counter-test-admin@example.invalid';

    /** Records an attempt at a chosen age, since the model always writes NOW(). */
    private function attempt(bool $success, int $minutesAgo): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_login_attempts (email, ip_address, attempted_at, success)
             VALUES (?, ?, DATE_SUB(NOW(), INTERVAL ? MINUTE), ?)'
        );
        $stmt->execute([self::EMAIL, '203.0.113.9', $minutesAgo, (int) $success]);
    }

    public function testAFreshAddressHasNoFailures(): void
    {
        $this->assertSame(0, AdminModel::getFailedAttempts(self::EMAIL));
        $this->assertFalse(AdminModel::isRateLimited(self::EMAIL));
    }

    public function testFailuresInsideTheWindowAreCounted(): void
    {
        $this->attempt(false, 5);
        $this->attempt(false, 3);

        $this->assertSame(2, AdminModel::getFailedAttempts(self::EMAIL));
    }

    public function testFailuresOlderThanTheWindowAreNotCounted(): void
    {
        $this->attempt(false, 45); // the window is 30 minutes

        $this->assertSame(0, AdminModel::getFailedAttempts(self::EMAIL));
    }

    public function testASuccessfulSignInResetsTheCount(): void
    {
        // The shape of the reported fault: a mistyped password, then a real sign-in.
        $this->attempt(false, 20);
        $this->attempt(true, 10);

        $this->assertSame(
            0,
            AdminModel::getFailedAttempts(self::EMAIL),
            'A failure from before a successful sign-in must not be held against its owner.'
        );
    }

    public function testFailuresAfterTheLastSuccessAreCountedAgain(): void
    {
        // The success must reset the count, not disable it — an attacker who arrives after
        // the owner has left is exactly who the counter is for.
        $this->attempt(false, 25);
        $this->attempt(true, 20);
        $this->attempt(false, 5);

        $this->assertSame(1, AdminModel::getFailedAttempts(self::EMAIL));
    }

    public function testTheLockoutAlsoStopsAtTheLastSuccess(): void
    {
        // Three failures is the lockout threshold; a sign-in between them and now means
        // the owner is not locked out of a panel they were just using.
        $this->attempt(false, 28);
        $this->attempt(false, 27);
        $this->attempt(false, 26);

        $this->assertTrue(AdminModel::isRateLimited(self::EMAIL));

        $this->attempt(true, 25);

        $this->assertFalse(
            AdminModel::isRateLimited(self::EMAIL),
            'A successful sign-in must clear the lockout, not merely the captcha demand.'
        );
    }

    public function testTheFailedRowsAreKept(): void
    {
        // The count changes; the audit trail does not. These rows are how a real attack is
        // reconstructed afterwards, so resetting the counter must never delete them.
        $this->attempt(false, 20);
        $this->attempt(true, 10);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts WHERE email = ? AND success = 0'
        );
        $stmt->execute([self::EMAIL]);

        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
