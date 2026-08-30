<?php

namespace App\Core;

use PDO;

/**
 * Throttle — a general attempt counter for sensitive entry points.
 *
 * What separates it from isRateLimited in UserModel/AdminModel is not the
 * mechanism but the question. That one asks: "how many times has sign-in to this
 * **account** failed?" — a question about one particular account, whose answer is
 * shown to the admin as failed_attempts. This one asks: "how many requests has
 * this **source** sent to this endpoint?"
 *
 * Nobody was asking the second question, and its absence is what left the 2FA step
 * breakable by guessing: the code is six digits, and the ±30-second window leaves
 * three valid codes out of a million at any instant — with nothing standing between
 * an attacker and the million attempts.
 *
 * The identifier today is the IP address. That is deliberate and limited: anyone
 * with many addresses gets past it. But it raises the cost of the attack from "a
 * while loop" to "a proxy network", and the account-bound throttle still stands
 * above it — the two layers guard different things and neither replaces the other.
 */
class Throttle
{
    /** How many days a trace survives before it is swept away. */
    private const RETENTION_DAYS = 1;

    /**
     * Has this source exceeded the allowance for this bucket within the window?
     *
     * ⚠️ It returns false when the database connection fails — that is, it opens the
     * door rather than closing it. This is deliberate and matches the existing
     * isRateLimited behaviour: every endpoint guarded here needs the database to
     * function at all (there is no sign-in without the admins table), so closing the
     * door on a database fault blocks what is already blocked, and turns a passing
     * database outage into a total lockout of the site.
     */
    public static function tooMany(string $bucket, string $identifier, int $max, int $windowMinutes): bool
    {
        try {
            $stmt = Database::connect()->prepare(
                "SELECT COUNT(*) FROM throttle_attempts
                 WHERE bucket = ?
                   AND identifier = ?
                   AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
            );
            $stmt->bindValue(1, $bucket, PDO::PARAM_STR);
            $stmt->bindValue(2, $identifier, PDO::PARAM_STR);
            $stmt->bindValue(3, $windowMinutes, PDO::PARAM_INT);
            $stmt->execute();

            return (int)$stmt->fetchColumn() >= $max;
        } catch (\Exception $e) {
            error_log('Throttle::tooMany Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Records an attempt, and sweeps this source's old traces in the same round trip.
     *
     * Sweeping here rather than in a scheduled job: the delete is confined to
     * (bucket, identifier), so it walks the same index and touches nobody else's
     * rows, and its cost is negligible against the guarantee that the table does not
     * grow without bound in a project with no dependable cron.
     */
    public static function record(string $bucket, string $identifier): void
    {
        try {
            $db = Database::connect();

            $db->prepare(
                "INSERT INTO throttle_attempts (bucket, identifier, attempted_at)
                 VALUES (?, ?, NOW())"
            )->execute([$bucket, $identifier]);

            $stmt = $db->prepare(
                "DELETE FROM throttle_attempts
                 WHERE bucket = ?
                   AND identifier = ?
                   AND attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $stmt->bindValue(1, $bucket, PDO::PARAM_STR);
            $stmt->bindValue(2, $identifier, PDO::PARAM_STR);
            $stmt->bindValue(3, self::RETENTION_DAYS, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\Exception $e) {
            error_log('Throttle::record Error: ' . $e->getMessage());
        }
    }

    /**
     * Clears this source's trace on this bucket — called after a genuine success.
     *
     * Without it, a user who forgot their password twice and then remembered it pays
     * for those two attempts on their next visit — and they are not who we are
     * guarding against.
     */
    public static function clear(string $bucket, string $identifier): void
    {
        try {
            Database::connect()
                ->prepare("DELETE FROM throttle_attempts WHERE bucket = ? AND identifier = ?")
                ->execute([$bucket, $identifier]);
        } catch (\Exception $e) {
            error_log('Throttle::clear Error: ' . $e->getMessage());
        }
    }

    /**
     * The source address as the server sees it.
     *
     * REMOTE_ADDR alone, deliberately: X-Forwarded-For is a header the client sends,
     * so reading it without a trusted proxy in front hands the attacker the key to
     * bypassing the throttle — they need only change it on every request. When the
     * project is put behind a real proxy, this is the one place that changes.
     */
    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return $ip !== '' ? substr($ip, 0, 45) : 'unknown';
    }
}
