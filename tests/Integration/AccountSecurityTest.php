<?php

namespace Tests\Integration;

use App\Models\UserModel;
use Tests\Support\DatabaseTestCase;

/**
 * Account security: the recovery tokens, email verification and the attempt counter.
 *
 * ══════════════════════════════════════════════════════════════
 * Why this file exists
 * ══════════════════════════════════════════════════════════════
 *
 * UserModel runs to 716 lines and was covered by not one test. And it carries the layer
 * whose breaking steals not a product but **an account**: the password recovery link.
 *
 * And every property of this layer is of the kind that appears to work while broken: a token
 * that never expires stays valid forever, a token accepted twice means whoever read an old
 * email gets in today, and one account's token opens another. None of that shows up in
 * ordinary use — all of it shows up on the day it is exploited.
 *
 * The tests here establish the properties rather than the lines: expiry, single use, the
 * token's binding to its owner, and that the raw token is never stored.
 */
final class AccountSecurityTest extends DatabaseTestCase
{
    /**
     * @return array{id: int, email: string}
     */
    private function makeUser(string $email = ''): array
    {
        $email = $email !== '' ? $email : 'user' . uniqid() . '@example.com';

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)'
        );
        $stmt->execute(['Test User', $email, password_hash('secret123', PASSWORD_BCRYPT)]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'email' => $email];
    }

    /** Moves a recovery token's expiry into the past — simulating the passage of time. */
    private function expirePasswordReset(string $email): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE email = ?'
        );
        $stmt->execute([$email]);
    }

    // ════════════════════════════════════════════════════════
    // The password recovery token
    // ════════════════════════════════════════════════════════

    public function testAFreshResetTokenValidatesOnce(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createPasswordReset($user['email']);

        $this->assertNotNull($token);
        $this->assertTrue(UserModel::validatePasswordResetToken($user['email'], $token));
    }

    /**
     * The most important property in this file: **the raw token is never stored**.
     *
     * A database leak — a lost backup, or an SQL injection on some other path — must not hand
     * the attacker valid recovery links for every user who requested one. The table carries
     * the sha256 alone, and that cannot be reversed.
     */
    public function testTheRawTokenIsNeverStored(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createPasswordReset($user['email']);

        $stmt = $this->pdo->prepare('SELECT token_hash FROM password_resets WHERE email = ?');
        $stmt->execute([$user['email']]);
        $stored = (string) $stmt->fetchColumn();

        $this->assertNotSame($token, $stored, 'The raw token is stored in the database.');
        $this->assertSame(hash('sha256', $token), $stored);
    }

    public function testAConsumedTokenCannotBeUsedAgain(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createPasswordReset($user['email']);

        $this->assertTrue(UserModel::validatePasswordResetToken($user['email'], $token));

        UserModel::consumePasswordResetToken($user['email'], $token);

        // A link accepted twice means whoever read an old email — on a shared machine, or in
        // an inbox compromised later — gets in today.
        $this->assertFalse(UserModel::validatePasswordResetToken($user['email'], $token));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createPasswordReset($user['email']);

        $this->expirePasswordReset($user['email']);

        $this->assertFalse(UserModel::validatePasswordResetToken($user['email'], $token));
    }

    public function testATokenDoesNotOpenAnotherAccount(): void
    {
        $victim  = $this->makeUser('victim@example.com');
        $attacker = $this->makeUser('attacker@example.com');

        $attackerToken = UserModel::createPasswordReset($attacker['email']);

        // The token is bound to the email address in the same SELECT. Without that, any
        // valid token becomes a key to any account — the worst thing that can happen here.
        $this->assertFalse(UserModel::validatePasswordResetToken($victim['email'], $attackerToken));
    }

    public function testAUserTokenIsNotValidAsAnAdminToken(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createPasswordReset($user['email'], 'user');

        // One table serves both the users and the admins, and the user_type column is all
        // that separates them. Were it neglected in the check, a customer's token would reset
        // the password of an admin holding the same email address.
        $this->assertFalse(UserModel::validatePasswordResetToken($user['email'], $token, 'admin'));
    }

    public function testAWrongTokenIsRejected(): void
    {
        $user = $this->makeUser();
        UserModel::createPasswordReset($user['email']);

        $this->assertFalse(
            UserModel::validatePasswordResetToken($user['email'], str_repeat('a', 64))
        );
    }

    // ════════════════════════════════════════════════════════
    // Email verification
    // ════════════════════════════════════════════════════════

    public function testVerifyingAnEmailMarksTheUserAndBurnsTheToken(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createEmailVerification($user['id']);

        $this->assertNotNull($token);
        $this->assertFalse(UserModel::isEmailVerified($user['id']));

        $this->assertTrue(UserModel::verifyEmailToken($token));
        $this->assertTrue(UserModel::isEmailVerified($user['id']));

        // The token is deleted after use, so reopening the link does nothing.
        $this->assertSame(0, $this->countRows('email_verifications'));
        $this->assertFalse(UserModel::verifyEmailToken($token));
    }

    public function testAnExpiredVerificationTokenIsRejected(): void
    {
        $user  = $this->makeUser();
        $token = UserModel::createEmailVerification($user['id']);

        $stmt = $this->pdo->prepare(
            'UPDATE email_verifications SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE user_id = ?'
        );
        $stmt->execute([$user['id']]);

        $this->assertFalse(UserModel::verifyEmailToken($token));
        $this->assertFalse(UserModel::isEmailVerified($user['id']));
    }

    // ════════════════════════════════════════════════════════
    // The sign-in attempt counter
    // ════════════════════════════════════════════════════════

    public function testRateLimitingKicksInAfterTheThreshold(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 4; $i++) {
            UserModel::logLoginAttempt($user['email'], false);
        }
        $this->assertFalse(UserModel::isRateLimited($user['email']));

        UserModel::logLoginAttempt($user['email'], false);
        $this->assertTrue(UserModel::isRateLimited($user['email']));
    }

    public function testRateLimitingIsPerAccountNotGlobal(): void
    {
        $target    = $this->makeUser('target@example.com');
        $bystander = $this->makeUser('bystander@example.com');

        for ($i = 0; $i < 5; $i++) {
            UserModel::logLoginAttempt($target['email'], false);
        }

        $this->assertTrue(UserModel::isRateLimited($target['email']));

        // A global counter would let one attacker lock the store against all of its
        // customers with five attempts — a denial of service at no cost.
        $this->assertFalse(UserModel::isRateLimited($bystander['email']));
    }

    public function testASuccessfulLoginIsNotCountedAgainstTheUser(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 10; $i++) {
            UserModel::logLoginAttempt($user['email'], true);
        }

        $this->assertFalse(UserModel::isRateLimited($user['email']));
        $this->assertSame(0, UserModel::getFailedAttemptsCount($user['email']));
    }

    public function testOldFailuresFallOutOfTheWindow(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            UserModel::logLoginAttempt($user['email'], false);
        }
        $this->assertTrue(UserModel::isRateLimited($user['email']));

        // The window is a sliding one rather than a permanent lock: somebody who forgot
        // their password and comes back an hour later should get in, not find their account
        // locked forever.
        $stmt = $this->pdo->prepare(
            'UPDATE login_attempts SET attempted_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE email = ?'
        );
        $stmt->execute([$user['email']]);

        $this->assertFalse(UserModel::isRateLimited($user['email']));
    }
}
