<?php

namespace App\Models;

use App\Core\Model;
use Exception;

/**
 * UserModel — covers the users table.
 * Operations: sign-in, account creation, lookup by email, and profile updates.
 */
class UserModel extends Model
{
    /**
     * Fetch a user by email.
     *
     * @return array<string, mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Exception $e) {
            error_log("UserModel::findByEmail Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch a user by id.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Exception $e) {
            error_log("UserModel::findById Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch a user by google_id (if the column exists).
     *
     * @return array<string, mixed>|null
     */
    public static function findByGoogleId(string $googleId): ?array
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("SELECT * FROM users WHERE google_id = ? LIMIT 1");
            $stmt->execute([$googleId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Exception $e) {
            // The column may not exist yet
            return null;
        }
    }

    /**
     * Check whether a phone number is already taken.
     */
    public static function phoneExists(string $phone): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("SELECT id FROM users WHERE phone_number = ? LIMIT 1");
            $stmt->execute([$phone]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Create a new user.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): ?int
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("
                INSERT INTO users
                    (full_name, email, password, phone_number, country, city,
                     gender, birth_date, privacy_policy_accepted,
                     privacy_policy_accepted_at, last_activity, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())
            ");
            $ok = $stmt->execute([
                $data['full_name'],
                $data['email'],
                $data['password'],
                $data['phone']    ?? null,
                $data['country']  ?? null,
                $data['city']     ?? null,
                $data['gender']   ?? null,
                $data['birth_date'] ?? null,
            ]);
            return $ok ? (int)$db->lastInsertId() : null;
        } catch (Exception $e) {
            error_log("UserModel::create Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a user through Google OAuth (with no password set).
     */
    public static function createFromGoogle(string $googleId, string $email, string $name): ?int
    {
        try {
            $db           = self::db();
            $randomPass   = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            $stmt         = $db->prepare("
                INSERT INTO users
                    (full_name, email, password, google_id,
                     email_verified_at,
                     privacy_policy_accepted, privacy_policy_accepted_at,
                     last_activity, created_at)
                VALUES (?, ?, ?, ?, NOW(), 1, NOW(), NOW(), NOW())
            ");
            $stmt->execute([$name, $email, $randomPass, $googleId]);
            return (int)$db->lastInsertId();
        } catch (Exception $e) {
            error_log("UserModel::createFromGoogle Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update the google_id of an existing user.
     */
    public static function updateGoogleId(int $userId, string $googleId): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("UPDATE users SET google_id = ? WHERE id = ?");
            return $stmt->execute([$googleId, $userId]);
        } catch (Exception $e) {
            error_log("UserModel::updateGoogleId Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update last_activity.
     */
    public static function updateActivity(int $userId): void
    {
        try {
            $db = self::db();
            $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$userId]);
        } catch (Exception $e) {
            error_log("UserModel::updateActivity Error: " . $e->getMessage());
        }
    }

    /**
     * Update the profile details.
     *
     * @param array<string, mixed> $data
     */
    public static function updateProfile(int $userId, array $data): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("
                UPDATE users
                SET full_name = ?, phone_number = ?, country = ?, city = ?
                WHERE id = ?
            ");
            return $stmt->execute([
                $data['full_name'],
                $data['phone_number'] ?? null,
                $data['country']      ?? null,
                $data['city']         ?? null,
                $userId,
            ]);
        } catch (Exception $e) {
            error_log("UserModel::updateProfile Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check the strike count — used to determine whether the user is blocked.
     */
    public static function getStrikesCount(int $userId): int
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("SELECT COUNT(*) FROM user_strikes WHERE user_id = ?");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * The total number of users in the system — entirely unfiltered (for the counter in
     * the Manage Users page title).
     */
    public static function countAll(): int
    {
        try {
            return (int)self::db()
                ->query("SELECT COUNT(*) FROM users")
                ->fetchColumn();
        } catch (\Exception $e) {
            error_log("UserModel::countAll Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Record a sign-in attempt.
     */
    public static function logLoginAttempt(string $email, bool $success): void
    {
        try {
            $db   = self::db();
            $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $stmt = $db->prepare(
                "INSERT INTO login_attempts (email, ip_address, attempted_at, success)
                 VALUES (?, ?, NOW(), ?)"
            );
            $stmt->execute([$email, $ip, (int)$success]);
        } catch (Exception $e) {
            error_log("UserModel::logLoginAttempt Error: " . $e->getMessage());
        }
    }

    /**
     * Rate-limit check — has this user exceeded the sign-in attempt allowance?
     */
    public static function isRateLimited(string $email, int $maxAttempts = 5, int $windowMinutes = 15): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE email = ? AND success = 0
                 AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
            );
            $stmt->execute([$email, $windowMinutes]);
            return (int)$stmt->fetchColumn() >= $maxAttempts;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * The count of failed attempts within the time window (a number, not a boolean).
     * Exactly the same logic as isRateLimited(), but returning the COUNT rather than
     * comparing it against the maximum.
     */
    public static function getFailedAttemptsCount(string $email, int $windowMinutes = 15): int
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE email = ? AND success = 0
                 AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
            );
            $stmt->execute([$email, $windowMinutes]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    // ════════════════════════════════════════════════════════
    // User management — the admin panel (Users 02/03/04)
    // ════════════════════════════════════════════════════════

    /**
     * A user list with search by name or email, status filtering and pagination.
     * $status: 'all' | 'active' | 'not_active' | 'blocked'
     *   - blocked     = a strike count of 3 or more
     *   - not_active  = last activity older than 90 days, with fewer than 3 strikes
     *   - active      = everyone else
     * Returns: ['rows' => array, 'total' => int]
     *
     * @return array<string, mixed> The rows together with the pagination data
     */
    public static function getAllForAdmin(
        string $search,
        string $status,
        int $page,
        int $perPage = 20
    ): array {
        try {
            $db      = self::db();
            $search  = trim($search);
            $status  = in_array($status, ['all', 'active', 'not_active', 'blocked'], true) ? $status : 'all';
            $page    = max(1, $page);
            $perPage = max(1, $perPage);
            $offset  = ($page - 1) * $perPage;

            $where  = '';
            $params = [];
            if ($search !== '') {
                $where    = 'WHERE u.full_name LIKE ? OR u.email LIKE ?';
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            // Status filtering — blocked first, because it depends on strikes alone rather than last_activity
            $statusWhere = '';
            switch ($status) {
                case 'blocked':
                    $statusWhere = 'WHERE t.strikes_count >= 3';
                    break;
                case 'not_active':
                    $statusWhere = 'WHERE t.strikes_count < 3 AND (t.last_activity IS NULL OR t.last_activity < (NOW() - INTERVAL 90 DAY))';
                    break;
                case 'active':
                    $statusWhere = 'WHERE t.strikes_count < 3 AND (t.last_activity IS NOT NULL AND t.last_activity >= (NOW() - INTERVAL 90 DAY))';
                    break;
            }

            $inner = "
                SELECT u.id, u.last_activity,
                       (SELECT COUNT(*) FROM user_strikes us WHERE us.user_id = u.id) AS strikes_count
                FROM users u
                {$where}
            ";

            $cStmt = $db->prepare("SELECT COUNT(*) FROM ({$inner}) t {$statusWhere}");
            $cStmt->execute($params);
            $total = (int)$cStmt->fetchColumn();

            $rowsStmt = $db->prepare("
                SELECT t.*
                FROM (
                    SELECT u.*,
                           (SELECT COUNT(*) FROM user_strikes us WHERE us.user_id = u.id) AS strikes_count
                    FROM users u
                    {$where}
                ) t
                {$statusWhere}
                ORDER BY t.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $rowsStmt->execute(array_merge($params, [$perPage, $offset]));

            return ['rows' => $rowsStmt->fetchAll(), 'total' => $total];
        } catch (Exception $e) {
            error_log("UserModel::getAllForAdmin Error: " . $e->getMessage());
            return ['rows' => [], 'total' => 0];
        }
    }

    /**
     * The ids of the users matching any of the chosen statuses — for the broadcast
     * system. $statuses takes values from: 'active' | 'not_active' | 'blocked'
     *   - blocked     = a strike count of 3 or more
     *   - not_active  = last activity older than 90 days, with fewer than 3 strikes
     *   - active      = everyone else
     * The classification logic is identical to getAllForAdmin(), word for word — so the
     * receiving audience matches exactly what the Manage Users table shows.
     * @return int[]
     * @param list<string> $statuses
     */
    public static function findByStatuses(array $statuses): array
    {
        $allowed  = ['active', 'not_active', 'blocked'];
        $statuses = array_values(array_intersect($statuses, $allowed));
        if (!$statuses) {
            return [];
        }

        try {
            $conds = [];
            foreach ($statuses as $s) {
                switch ($s) {
                    case 'blocked':
                        $conds[] = 't.strikes_count >= 3';
                        break;
                    case 'not_active':
                        $conds[] = 't.strikes_count < 3 AND (t.last_activity IS NULL OR t.last_activity < (NOW() - INTERVAL 90 DAY))';
                        break;
                    default: // active
                        $conds[] = 't.strikes_count < 3 AND (t.last_activity IS NOT NULL AND t.last_activity >= (NOW() - INTERVAL 90 DAY))';
                }
            }

            $stmt = self::db()->query("
                SELECT t.id
                FROM (
                    SELECT u.id, u.last_activity,
                           (SELECT COUNT(*) FROM user_strikes us WHERE us.user_id = u.id) AS strikes_count
                    FROM users u
                ) t
                WHERE " . implode(' OR ', $conds));

            return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (Exception $e) {
            error_log("UserModel::findByStatuses Error: " . $e->getMessage());
            return [];
        }
    }

    /** Every users column plus strikes_count for one user (for the details page). */
    /**
     * @return array<string, mixed>|null
     */
    public static function getByIdForAdmin(int $id): ?array
    {
        try {
            $stmt = self::db()->prepare("
                SELECT u.*,
                       (SELECT COUNT(*) FROM user_strikes us WHERE us.user_id = u.id) AS strikes_count
                FROM users u
                WHERE u.id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            error_log("UserModel::getByIdForAdmin Error: " . $e->getMessage());
            return null;
        }
    }

    /** Every user_strikes row for a given user, newest first. */
    /**
     * @return list<array<string, mixed>>
     */
    public static function getStrikes(int $userId): array
    {
        try {
            $stmt = self::db()->prepare(
                "SELECT * FROM user_strikes WHERE user_id = ? ORDER BY created_at DESC, id DESC"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("UserModel::getStrikes Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Add a strike to a user.
     * On reaching exactly three strikes: an automatic block, plus cancelling their
     * pending orders (not_taken/taken) and restoring that stock, through
     * OrderModel::cancelAllPendingForUser.
     */
    public static function addStrike(int $userId, int $adminId, string $reason): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "INSERT INTO user_strikes (user_id, reason, issued_by_admin_id) VALUES (?, ?, ?)"
            );
            $ok = $stmt->execute([$userId, $reason, $adminId]);

            if ($ok && self::getStrikesCount($userId) === 3) {
                OrderModel::cancelAllPendingForUser($userId);
            }

            return $ok;
        } catch (Exception $e) {
            error_log("UserModel::addStrike Error: " . $e->getMessage());
            return false;
        }
    }

    /** Remove a strike — it requires the strike to belong to $userId itself, to prevent IDOR. */
    public static function removeStrike(int $strikeId, int $userId): bool
    {
        try {
            $stmt = self::db()->prepare(
                "DELETE FROM user_strikes WHERE id = ? AND user_id = ?"
            );
            // execute() returns true even when no row was deleted — rowCount must be checked
            return $stmt->execute([$strikeId, $userId]) && $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("UserModel::removeStrike Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Permanently delete a user together with all of their orders, whatever their
     * status — one operation inside a transaction.
     * It relies on no possible ON DELETE CASCADE in the database — the deletion is
     * explicit, step by step (the same pattern as adminDeleteOrder in OrderModel), so the
     * correct order is guaranteed and precise details of what was actually deleted can be
     * returned for the audit record.
     *
     * @return array{
     *   success: bool,
     *   ordersDeletedCount: int,
     *   ordersByStatus: array<string,int>,   // e.g. ['not_taken'=>1, 'taken'=>0, 'completed'=>2, 'cancelled'=>1]
     * }
     */
    public static function deleteUser(int $id): array
    {
        $db = self::db();
        $ordersByStatus = ['not_taken' => 0, 'taken' => 0, 'completed' => 0, 'cancelled' => 0];

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT order_id, status FROM orders WHERE user_id = ?");
            $stmt->execute([$id]);
            $userOrders = $stmt->fetchAll();

            foreach ($userOrders as $o) {
                if (isset($ordersByStatus[$o['status']])) {
                    $ordersByStatus[$o['status']]++;
                }
            }

            if ($userOrders) {
                $orderIds = array_column($userOrders, 'order_id');
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

                $db->prepare("DELETE FROM order_items WHERE order_id IN ({$placeholders})")->execute($orderIds);
                $db->prepare("DELETE FROM order_expiry_log WHERE order_id IN ({$placeholders})")->execute($orderIds);
                $db->prepare("DELETE FROM orders WHERE order_id IN ({$placeholders})")->execute($orderIds);
            }

            $delStmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $delStmt->execute([$id]);
            $userDeleted = $delStmt->rowCount() > 0;

            if (!$userDeleted) {
                $db->rollBack();
                return ['success' => false, 'ordersDeletedCount' => 0, 'ordersByStatus' => $ordersByStatus];
            }

            $db->commit();
            return [
                'success'            => true,
                'ordersDeletedCount' => count($userOrders),
                'ordersByStatus'     => $ordersByStatus,
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("UserModel::deleteUser Error: " . $e->getMessage());
            return ['success' => false, 'ordersDeletedCount' => 0, 'ordersByStatus' => $ordersByStatus];
        }
    }

    /**
     * Send a single notification to a user — a thin wrapper over the existing, tested
     * NotificationModel::insert(), kept for symmetry with AdminModel::sendNotification().
     */
    public static function sendNotification(
        int $userId,
        string $title,
        string $message,
        ?int $senderAdminId = null,
        ?string $relatedType = null,
        ?int $relatedId = null
    ): void {
        \App\Models\NotificationModel::insert($userId, $title, $message, $senderAdminId, $relatedType, $relatedId);
    }

    /** Every column needed for the CSV export, plus strikes_count as a computed column. */
    /**
     * @return list<array<string, mixed>>
     */
    public static function getAllForCsvExport(): array
    {
        try {
            $stmt = self::db()->query("
                SELECT u.id, u.full_name, u.email, u.phone_number, u.country, u.city, u.gender,
                       u.birth_date, u.google_id, u.last_activity, u.created_at,
                       (SELECT COUNT(*) FROM user_strikes us WHERE us.user_id = u.id) AS strikes_count
                FROM users u
                ORDER BY u.created_at DESC
            ");
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('UserModel::getAllForCsvExport Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Create a password reset token (valid for 60 minutes).
     * It returns the raw token, which is emailed — only a hash of it is stored in the table.
     */
    public static function createPasswordReset(string $email, string $userType = 'user'): ?string
    {
        try {
            $db = self::db();
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            // The expiry is computed inside MySQL (DATE_ADD) so it lines up with the NOW()
            // used when verifying — this matters because PHP's timezone may differ from MySQL's
            $stmt = $db->prepare("INSERT INTO password_resets (email, user_type, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))");
            $stmt->execute([$email, $userType, $tokenHash]);

            return $token;
        } catch (\Exception $e) {
            error_log("UserModel::createPasswordReset Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify a password reset token (unused and unexpired).
     */
    public static function validatePasswordResetToken(string $email, string $token, string $userType = 'user'): bool
    {
        try {
            $db = self::db();
            $tokenHash = hash('sha256', $token);
            $stmt = $db->prepare("SELECT id FROM password_resets WHERE email = ? AND user_type = ? AND token_hash = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
            $stmt->execute([$email, $userType, $tokenHash]);
            return (bool)$stmt->fetch();
        } catch (\Exception $e) {
            error_log("UserModel::validatePasswordResetToken Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Consume the token once it has been used, which prevents reusing the same link.
     */
    public static function consumePasswordResetToken(string $email, string $token, string $userType = 'user'): void
    {
        $db = self::db();
        $tokenHash = hash('sha256', $token);
        $stmt = $db->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND user_type = ? AND token_hash = ?");
        $stmt->execute([$email, $userType, $tokenHash]);
    }

    /**
     * Update the user's password (it receives the already-computed hash).
     */
    public static function updatePassword(int $userId, string $newPasswordHash): bool
    {
        try {
            $db = self::db();
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            return $stmt->execute([$newPasswordHash, $userId]);
        } catch (\Exception $e) {
            error_log("UserModel::updatePassword Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create an email verification token for the user (valid for 24 hours).
     * It returns the raw token, which is emailed — only a hash of it is stored in the table.
     */
    public static function createEmailVerification(int $userId): ?string
    {
        try {
            $db = self::db();
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            // The expiry is computed inside MySQL (DATE_ADD) so it lines up with the NOW()
            // used when verifying — this matters because PHP's timezone may differ from MySQL's
            $stmt = $db->prepare("INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
            $stmt->execute([$userId, $tokenHash]);
            return $token;
        } catch (\Exception $e) {
            error_log("UserModel::createEmailVerification Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify the user's email through the emailed link — returns true on success.
     */
    public static function verifyEmailToken(string $token): bool
    {
        try {
            $db = self::db();
            $tokenHash = hash('sha256', $token);
            $stmt = $db->prepare("SELECT user_id FROM email_verifications WHERE token_hash = ? AND expires_at > NOW() LIMIT 1");
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch();
            if (!$row) {
                return false;
            }

            $update = $db->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?");
            $update->execute([$row['user_id']]);

            $del = $db->prepare("DELETE FROM email_verifications WHERE token_hash = ?");
            $del->execute([$tokenHash]);

            return true;
        } catch (\Exception $e) {
            error_log("UserModel::verifyEmailToken Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Is the user's email verified? (It uses the same connection available for the lookup.)
     */
    public static function isEmailVerified(int $userId): bool
    {
        $user = self::findById($userId);
        return $user && !empty($user['email_verified_at']);
    }

    /**
     * A user's full name by their id, or null if they do not exist.
     *
     * Moved out of WishlistController, where it was a query written inline inside the
     * function that notifies the admins of a "notify me when available" request.
     */
    public static function getFullNameById(int $userId): ?string
    {
        try {
            $stmt = self::db()->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $name = $stmt->fetchColumn();
            return $name !== false ? (string)$name : null;
        } catch (Exception $e) {
            error_log("UserModel::getFullNameById Error: " . $e->getMessage());
            return null;
        }
    }
}
