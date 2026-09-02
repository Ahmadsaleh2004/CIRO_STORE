<?php

namespace App\Models;

use App\Core\Model;
use Exception;

/**
 * AdminModel — covers the admins table exclusively.
 * It does not touch the users table in any way.
 */
class AdminModel extends Model
{
    // ════════════════════════════════════════════════════════
    // Rate-limiting allowances and windows (stricter than for a regular user)
    // ════════════════════════════════════════════════════════
    private const MAX_FAILED_ATTEMPTS = 3;
    private const WINDOW_MINUTES      = 30;
    private const LOCKOUT_MINUTES     = 30;

    // ════════════════════════════════════════════════════════
    // Fetch an admin by email, from the admins table alone
    // ════════════════════════════════════════════════════════
    /**
     * @return array<string, mixed>|null An admins row as fetch() returns it
     */
    public static function findByEmail(string $email): ?array
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("SELECT * FROM admins WHERE email = ? LIMIT 1");
            $stmt->execute([strtolower(trim($email))]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Exception $e) {
            error_log("AdminModel::findByEmail Error: " . $e->getMessage());
            return null;
        }
    }

    // ════════════════════════════════════════════════════════
    // The count of failed attempts within the time window
    // ════════════════════════════════════════════════════════
    public static function getFailedAttempts(string $email): int
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM admin_login_attempts
                 WHERE email = ? AND success = 0
                 AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
            );
            $stmt->execute([strtolower(trim($email)), self::WINDOW_MINUTES]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("AdminModel::getFailedAttempts Error: " . $e->getMessage());
            return 0;
        }
    }

    // ════════════════════════════════════════════════════════
    // Record a sign-in attempt, successful or failed
    // ════════════════════════════════════════════════════════
    public static function logLoginAttempt(string $email, bool $success): void
    {
        try {
            $db   = self::db();
            $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $stmt = $db->prepare(
                "INSERT INTO admin_login_attempts (email, ip_address, attempted_at, success)
                 VALUES (?, ?, NOW(), ?)"
            );
            $stmt->execute([strtolower(trim($email)), $ip, (int)$success]);
        } catch (Exception $e) {
            error_log("AdminModel::logLoginAttempt Error: " . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // Rate-limit check — 3 failed attempts within 30 minutes means a 30-minute lockout
    // ════════════════════════════════════════════════════════
    public static function isRateLimited(string $email): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM admin_login_attempts
                 WHERE email = ? AND success = 0
                 AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
            );
            $stmt->execute([strtolower(trim($email)), self::LOCKOUT_MINUTES]);
            return (int)$stmt->fetchColumn() >= self::MAX_FAILED_ATTEMPTS;
        } catch (Exception $e) {
            error_log("AdminModel::isRateLimited Error: " . $e->getMessage());
            return false; // Safety: on an error we do not lock anyone out
        }
    }

    // ════════════════════════════════════════════════════════
    // updateActivity() used to live here, and it wrote to a column that
    // does not exist: `admins.last_activity` is declared in the very first
    // migration and is absent from the schema the database actually has.
    // Every successful sign-in therefore logged
    //     Unknown column 'last_activity' in 'field list'
    // and carried on, because the write was wrapped in a try/catch.
    //
    // It is deleted rather than repaired with a migration: nothing in the
    // project ever READ the value — not a query, not a view, not a report —
    // so adding the column would create a field that is written on every
    // sign-in and never once looked at. (`users.last_activity` is a different
    // column, is read in several places, and is untouched here.)
    //
    // `admins.is_active` is in the same position — declared in migration 0001,
    // absent from the schema, and referenced by no PHP anywhere in the project.
    // Nothing depends on it, so nothing here changes; it is recorded so the next
    // reader of that migration knows the drift is known rather than missed.
    // ════════════════════════════════════════════════════════

    // ════════════════════════════════════════════════════════
    // Fetch an admin by id, from the admins table
    // ════════════════════════════════════════════════════════
    /**
     * @return array<string, mixed>|null An admins row as fetch() returns it
     */
    public static function findById(int $id): ?array
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("SELECT * FROM admins WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            error_log("AdminModel::findById Error: " . $e->getMessage());
            return null;
        }
    }

    // ════════════════════════════════════════════════════════
    // Fetch an admin's permissions from the admin_permissions table
    // ════════════════════════════════════════════════════════
    /**
     * @return array<string, mixed> An admin_permissions row, or [] if there is none
     */
    public static function getPermissions(int $adminId): array
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("SELECT * FROM admin_permissions WHERE admin_id = ?");
            $stmt->execute([$adminId]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("AdminModel::getPermissions Error: " . $e->getMessage());
            return [];
        }
    }

    // ════════════════════════════════════════════════════════
    // Record an action in the admin_audit_log table
    // ════════════════════════════════════════════════════════
    public static function logAction(
        int $adminId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $details = null
    ): void {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "INSERT INTO admin_audit_log (admin_id, action, target_type, target_id, details)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$adminId, $action, $targetType, $targetId, $details]);
        } catch (Exception $e) {
            error_log("AdminModel::logAction Error: " . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // Update an admin's details (name / phone / password, if given)
    // ════════════════════════════════════════════════════════
    /**
     * @param array<string, mixed> $data
     */
    public static function updateProfile(int $adminId, array $data): bool
    {
        try {
            $db     = self::db();
            $fields = [];
            $params = [];

            foreach (['full_name', 'phone_number', 'password'] as $key) {
                if (array_key_exists($key, $data)) {
                    $fields[] = "`{$key}` = ?";
                    $params[] = $data[$key];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $params[] = $adminId;
            $sql  = "UPDATE admins SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            return $stmt->execute($params);
        } catch (Exception $e) {
            error_log("AdminModel::updateProfile Error: " . $e->getMessage());
            return false;
        }
    }

    // ════════════════════════════════════════════════════════
    // The hierarchy — a map of rank values (A highest, D lowest)
    // ════════════════════════════════════════════════════════

    /** A map of rank to value — the higher the number, the higher the rank (A=4 highest … D=1 lowest). */
    private const RANK_VALUES = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1];

    public static function getRankValue(string $role): int
    {
        return self::RANK_VALUES[$role] ?? 0;
    }

    /** True only when $actorRole ranks STRICTLY above $targetRole. */
    public static function canManageTarget(string $actorRole, string $targetRole): bool
    {
        return self::getRankValue($actorRole) > self::getRankValue($targetRole);
    }

    public static function getRootAdminId(): ?int
    {
        try {
            // An explicit ORDER BY: without it the row order is the engine's to choose, so
            // "root" could change between two calls over the same data. There is one rank A
            // today (and no way to create a second — canManageTarget requires a rank above
            // A, which does not exist), but leaving the definition of root to luck is not
            // something to hand to the future.
            $id = self::db()
                ->query("SELECT id FROM admins WHERE role='A' ORDER BY id ASC LIMIT 1")
                ->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Exception $e) {
            error_log("AdminModel::getRootAdminId Error: " . $e->getMessage());
            return null;
        }
    }

    // ════════════════════════════════════════════════════════
    // Fetch the admins together with their permissions
    // ════════════════════════════════════════════════════════

    /** Every admin plus their permissions (a LEFT JOIN), ordered by when they were added. */
    /**
     * @return list<array<string, mixed>>
     */
    public static function getAllWithPermissions(): array
    {
        try {
            $stmt = self::db()->query("
                SELECT a.*, ap.can_manage_admins, ap.can_manage_products, ap.can_manage_users,
                       ap.can_view_dashboard, ap.can_manage_support, ap.can_edit_site_content,
                       ap.can_manage_checkout_settings, ap.can_manage_orders, ap.can_manage_branding,
                       modifier.full_name AS last_modified_by_name
                FROM admins a
                LEFT JOIN admin_permissions ap ON ap.admin_id = a.id
                LEFT JOIN admins modifier ON modifier.id = a.last_modified_by
                ORDER BY a.created_at ASC
            ");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("AdminModel::getAllWithPermissions Error: " . $e->getMessage());
            return [];
        }
    }

    /** The same query as above but for one admin (for the admin details page). */
    /**
     * @return array<string, mixed>|null
     */
    public static function getByIdWithPermissions(int $id): ?array
    {
        try {
            $stmt = self::db()->prepare("
                SELECT a.*, ap.can_manage_admins, ap.can_manage_products, ap.can_manage_users,
                       ap.can_view_dashboard, ap.can_manage_support, ap.can_edit_site_content,
                       ap.can_manage_checkout_settings, ap.can_manage_orders, ap.can_manage_branding,
                       modifier.full_name AS last_modified_by_name
                FROM admins a
                LEFT JOIN admin_permissions ap ON ap.admin_id = a.id
                LEFT JOIN admins modifier ON modifier.id = a.last_modified_by
                WHERE a.id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            error_log("AdminModel::getByIdWithPermissions Error: " . $e->getMessage());
            return null;
        }
    }

    public static function countAdmins(): int
    {
        try {
            return (int)self::db()->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        } catch (Exception $e) {
            error_log("AdminModel::countAdmins Error: " . $e->getMessage());
            return 0;
        }
    }

    // ════════════════════════════════════════════════════════
    // Password verification, and an email duplication check
    // ════════════════════════════════════════════════════════

    public static function verifyPassword(int $adminId, string $plainPassword): bool
    {
        try {
            $stmt = self::db()->prepare("SELECT password FROM admins WHERE id=? LIMIT 1");
            $stmt->execute([$adminId]);
            $hash = $stmt->fetchColumn();
            return $hash && password_verify($plainPassword, $hash);
        } catch (Exception $e) {
            error_log("AdminModel::verifyPassword Error: " . $e->getMessage());
            return false;
        }
    }

    public static function emailExists(string $email): bool
    {
        try {
            $stmt = self::db()->prepare("SELECT id FROM admins WHERE email=? LIMIT 1");
            $stmt->execute([strtolower(trim($email))]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            error_log("AdminModel::emailExists Error: " . $e->getMessage());
            return false;
        }
    }

    // ════════════════════════════════════════════════════════
    // CRUD: create / delete / update permissions
    // ════════════════════════════════════════════════════════

    /**
     * Create a new admin together with their permissions, in one transaction.
     * Returns the new id, or null on failure.
     *
     * @param array<string, mixed> $data
     * @param array<string, bool> $perms A map of permission name → granted. Not a list of names — see findByPermsAndRanks, which takes a list; the difference is deliberate.
     */
    public static function createAdmin(array $data, array $perms): ?int
    {
        $db = self::db();
        try {
            $db->beginTransaction();

            $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $ins  = $db->prepare(
                "INSERT INTO admins (full_name, email, password, phone_number, role, added_by, last_modified_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([
                $data['full_name'],
                strtolower(trim($data['email'])),
                $hash,
                $data['phone_number'] ?: null,
                $data['role'],
                $data['added_by'],
                $data['added_by'],
            ]);
            $newId = (int)$db->lastInsertId();

            $db->prepare("
                INSERT INTO admin_permissions
                    (admin_id, can_manage_admins, can_manage_products, can_manage_users,
                     can_view_dashboard, can_manage_support, can_edit_site_content,
                     can_manage_checkout_settings, can_manage_orders, can_manage_branding)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $newId,
                (int)!empty($perms['can_manage_admins']),
                (int)!empty($perms['can_manage_products']),
                (int)!empty($perms['can_manage_users']),
                (int)!empty($perms['can_view_dashboard']),
                (int)!empty($perms['can_manage_support']),
                (int)!empty($perms['can_edit_site_content']),
                (int)!empty($perms['can_manage_checkout_settings']),
                (int)!empty($perms['can_manage_orders']),
                (int)!empty($perms['can_manage_branding']),
            ]);

            $db->commit();
            return $newId;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("AdminModel::createAdmin Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Deletes an admin — and the remaining ids do not move.
     *
     * ⚠️ There used to be a renumbering of every id above the deleted one: decremented
     * by one across nine tables, with SET FOREIGN_KEY_CHECKS=0 and an ALTER TABLE
     * AUTO_INCREMENT at the end. All of it was removed, for three reasons rather than one:
     *
     *   1. **The ALTER broke the transaction.** ALTER TABLE in MySQL causes an implicit
     *      commit — so the beginTransaction above ended there, and the rollBack() in the
     *      catch had nothing left to roll back. Which means the id renumbering across
     *      nine tables was **irreversible** if anything failed after it, and with the key
     *      checks switched off.
     *
     *   2. **The id was tied to authorisation.** BackupController granted the right to
     *      download the entire database on `getCurrentAdminId() !== 1` — that is, to a
     *      position in a sequence rather than to a person. And one renumbering was enough
     *      for somebody else to inherit that right, silently.
     *
     *   3. **An id is an identity, not a display order.** The project owner's decision:
     *      whoever had id 10 keeps id 10 for life. The gaps left by deletions are
     *      intended, and the sequential numbering in the tables is computed in the view
     *      from the row's position.
     */
    public static function deleteAdmin(int $id): bool
    {
        $db = self::db();
        try {
            $db->beginTransaction();

            // rowCount, not a bare execute. `DELETE ... WHERE id=?` for an id that does not
            // exist succeeds and affects zero rows, so returning true unconditionally told
            // the caller an admin had been deleted when none had. That is the same fault
            // AdminProductModel::delete carried, and it did not stop at a wrong return
            // value there either: AdminManageAdminsController wrote an audit row reading
            // "Deleted: <email>" and sent a notification for it. A log that records
            // deletions which never happened is worse than no log, because it is believed.
            $stmt = $db->prepare("DELETE FROM admins WHERE id=?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                return false;
            }

            $db->commit();
            return true;
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("AdminModel::deleteAdmin Error: " . $e->getMessage());
            return false;
        }
    }

    /** Update the rank and the permissions together, in one transaction. */
    /**
     * @param array<string, bool> $perms A map of permission name → granted. Not a list of names — see findByPermsAndRanks, which takes a list; the difference is deliberate.
     */
    public static function updatePermissions(int $id, ?string $newRole, array $perms, int $editorAdminId): bool
    {
        $db = self::db();
        try {
            $db->beginTransaction();

            if (in_array($newRole, ['A', 'B', 'C', 'D'], true)) {
                $db->prepare("UPDATE admins SET role=?, updated_at=NOW(), last_modified_by=? WHERE id=?")
                   ->execute([$newRole, $editorAdminId, $id]);
            } else {
                // No rank change — updated_at still needs updating, because a permission change did happen
                $db->prepare("UPDATE admins SET updated_at=NOW(), last_modified_by=? WHERE id=?")->execute([$editorAdminId, $id]);
            }

            $db->prepare("
                UPDATE admin_permissions SET
                    can_manage_admins=?, can_manage_products=?, can_manage_users=?,
                    can_view_dashboard=?, can_manage_support=?, can_edit_site_content=?,
                    can_manage_checkout_settings=?, can_manage_orders=?, can_manage_branding=?
                WHERE admin_id=?
            ")->execute([
                (int)!empty($perms['can_manage_admins']),
                (int)!empty($perms['can_manage_products']),
                (int)!empty($perms['can_manage_users']),
                (int)!empty($perms['can_view_dashboard']),
                (int)!empty($perms['can_manage_support']),
                (int)!empty($perms['can_edit_site_content']),
                (int)!empty($perms['can_manage_checkout_settings']),
                (int)!empty($perms['can_manage_orders']),
                (int)!empty($perms['can_manage_branding']),
                $id,
            ]);

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("AdminModel::updatePermissions Error: " . $e->getMessage());
            return false;
        }
    }

    // ════════════════════════════════════════════════════════
    // Admin notifications (admin_notifications)
    // ════════════════════════════════════════════════════════

    /** Send a single notification to an admin. */
    public static function sendNotification(
        int $recipientAdminId,
        string $title,
        string $message,
        string $type = 'system',
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?int $senderAdminId = null
    ): void {
        try {
            self::db()->prepare("
                INSERT INTO admin_notifications
                    (admin_id, title, message, type, related_type, related_id, sender_admin_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$recipientAdminId, $title, $message, $type, $relatedType, $relatedId, $senderAdminId]);
        } catch (Exception $e) {
            error_log('AdminModel::sendNotification Error: ' . $e->getMessage());
        }
    }

    /**
     * Admins matching: any permission from $perms AND a rank from $ranks (for broadcasts).
     * Returns an array of ids.
     *
     * ⚠️ `$perms` here is **a list of names**, not a map — unlike createAdmin and
     * updatePermissions, which take `array<string, bool>`. The name is the same and the
     * shape is different, a distinction the bare `array` signature does not show.
     * (`array<string, bool>` was written here by mistake at first, and PHPStan caught it
     * immediately with ten errors at the call sites — which is the point of this level.)
     *
     * @param list<string> $perms
     * @param list<string> $ranks
     * @return list<int> The ids of the matching admins
     */
    public static function findByPermsAndRanks(array $perms, array $ranks): array
    {
        $allowedPerms = [
            'can_manage_admins', 'can_manage_products', 'can_manage_users', 'can_view_dashboard',
            'can_manage_support', 'can_edit_site_content', 'can_manage_checkout_settings', 'can_manage_orders',
            'can_manage_branding',
        ];

        $perms = array_values(array_intersect($perms, $allowedPerms));
        $ranks = array_values(array_intersect($ranks, ['A', 'B', 'C', 'D']));

        if (!$perms || !$ranks) {
            return [];
        }

        try {
            $permSql = implode(' OR ', array_map(fn($p) => "ap.{$p}=1", $perms));
            $rankPh  = implode(',', array_fill(0, count($ranks), '?'));

            $stmt = self::db()->prepare("
                SELECT a.id FROM admins a
                JOIN admin_permissions ap ON ap.admin_id = a.id
                WHERE ({$permSql}) AND a.role IN ({$rankPh})
            ");
            $stmt->execute($ranks);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            error_log('AdminModel::findByPermsAndRanks Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Admins holding a given permission AND ranking strictly above $actorRole — with
     * rank A (the root admin) always excluded from the result, even though it does rank
     * above $actorRole.
     * Used for "notify the supervisors directly above" without disturbing the root admin.
     *
     * @return int[] The target admins' ids (possibly an empty array)
     */
    public static function findHigherRankWithPermission(string $perm, string $actorRole): array
    {
        $actorRank = self::getRankValue($actorRole);

        $candidateRanks = array_values(array_filter(
            ['B', 'C', 'D'],
            fn(string $r) => self::getRankValue($r) > $actorRank
        ));

        if (empty($candidateRanks)) {
            return [];
        }

        return self::findByPermsAndRanks([$perm], $candidateRanks);
    }

    /**
     * Generic hierarchical action notification.
     * Notifies the acting admin (self-confirmation) plus every admin who:
     *   (a) holds the given $permission, AND
     *   (b) has a rank strictly higher than the actor's rank,
     * excluding the root admin (role 'A') and excluding the actor themself.
     *
     * IMPORTANT: the actor's role is looked up from the database via findById(),
     * NOT from the session (getAdminRole()). This method must work correctly even
     * when the actor is not the current logged-in session (e.g. a background
     * lazy-check triggered by a different admin's page load — see the
     * order auto-release feature in a later prompt). Never call getAdminRole()
     * inside this method.
     *
     * @param int         $actorAdminId   The admin who performed the action (or, for
     *                                     system-triggered events, the admin the event
     *                                     is attributed to).
     * @param string      $permission     Single permission column name, e.g. 'can_manage_orders'.
     * @param string      $title          Notification title, same for both self and others.
     * @param string      $selfMessage    Message body sent to $actorAdminId.
     * @param string      $othersMessage  Message body sent to every higher-rank admin.
     * @param string      $type           Notification `type` column value.
     * @param string|null $relatedType    Notification `related_type` column value.
     * @param int|null    $relatedId      Notification `related_id` column value.
     */
    public static function notifyHigherRanksOnAction(
        int $actorAdminId,
        string $permission,
        string $title,
        string $selfMessage,
        string $othersMessage,
        string $type,
        ?string $relatedType = null,
        ?int $relatedId = null
    ): void {
        $rankMap = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1];

        $actor = self::findById($actorAdminId);
        $myRole = $actor['role'] ?? null;

        // Self-notification (confirmation record for the actor).
        self::sendNotification(
            $actorAdminId,
            $title,
            $selfMessage,
            $type,
            $relatedType,
            $relatedId,
            $actorAdminId
        );

        if ($myRole === null || !isset($rankMap[$myRole])) {
            return;
        }

        $higherRanks = array_keys(array_filter(
            $rankMap,
            fn(int $level) => $level > $rankMap[$myRole]
        ));

        if (!$higherRanks) {
            return;
        }

        $targets = self::findByPermsAndRanks([$permission], $higherRanks);
        $rootId  = self::getRootAdminId();

        foreach ($targets as $tId) {
            $tId = (int)$tId;
            if ($tId === $actorAdminId || ($rootId !== null && $tId === $rootId)) {
                continue;
            }
            self::sendNotification(
                $tId,
                $title,
                $othersMessage,
                $type,
                $relatedType,
                $relatedId,
                $actorAdminId
            );
        }
    }

    // ═════════════════════════════════════════════════════════
    // One admin's action log from admin_audit_log (for the details page)
    // ════════════════════════════════════════════════════════

    /**
     * @return list<array<string, mixed>>
     */
    public static function getAuditLogForAdmin(int $adminId, int $limit = 50): array
    {
        try {
            $stmt = self::db()->prepare("
                SELECT action, target_type, target_id, details, created_at
                FROM admin_audit_log
                WHERE admin_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$adminId, $limit]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('AdminModel::getAuditLogForAdmin Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * A filtered audit log: only the target_types in the given list.
     * Used by the specialised Admin Details sections (Orders/User/Product/Branding/Support/Site Config).
     *
     * @param list<string> $targetTypes
     * @return list<array<string, mixed>>
     */
    public static function getAuditLogByTypes(int $adminId, array $targetTypes, int $limit = 50): array
    {
        if (!$targetTypes) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($targetTypes), '?'));
            $stmt = self::db()->prepare("
                SELECT action, target_type, target_id, details, created_at
                FROM admin_audit_log
                WHERE admin_id = ? AND target_type IN ({$placeholders})
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute(array_merge([$adminId], $targetTypes, [$limit]));
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('AdminModel::getAuditLogByTypes Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * A filtered audit log by exclusion: everything except the listed target_types, plus
     * any row whose target_type is NULL (sign-in and sign-out, profile updates, enabling
     * and disabling 2FA, and so on).
     * Used by the general "Admin Actions Log" section, now that it has separate
     * specialised sections.
     *
     * @param list<string> $excludeTypes
     * @return list<array<string, mixed>>
     */
    public static function getAuditLogExcludingTypes(int $adminId, array $excludeTypes, int $limit = 50): array
    {
        try {
            $where = "admin_id = ?";
            $params = [$adminId];
            if ($excludeTypes) {
                $placeholders = implode(',', array_fill(0, count($excludeTypes), '?'));
                $where .= " AND (target_type NOT IN ({$placeholders}) OR target_type IS NULL)";
                $params = array_merge($params, $excludeTypes);
            }
            $params[] = $limit;

            $stmt = self::db()->prepare("
                SELECT action, target_type, target_id, details, created_at
                FROM admin_audit_log
                WHERE {$where}
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('AdminModel::getAuditLogExcludingTypes Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Every admin action against target_type='user' AND target_id=$userId
     * (for the user details page — the log of admin activity on this user).
     *
     * @return list<array<string, mixed>>
     */
    public static function getAuditLogForUser(int $userId, int $limit = 50): array
    {
        try {
            $stmt = self::db()->prepare("
                SELECT al.action, al.target_type, al.target_id, al.details, al.created_at,
                       a.full_name AS admin_name
                FROM admin_audit_log al
                JOIN admins a ON a.id = al.admin_id
                WHERE al.target_type = 'user' AND al.target_id = ?
                ORDER BY al.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('AdminModel::getAuditLogForUser Error: ' . $e->getMessage());
            return [];
        }
    }

    // ════════════════════════════════════════════════════════
    // CSV export — rank A only (the same restriction as before)
    // ════════════════════════════════════════════════════════

    /**
     * @return list<array<string, mixed>>
     */
    public static function getAllForCsvExport(): array
    {
        try {
            $stmt = self::db()->query("
                SELECT a.id, a.full_name, a.email, a.phone_number, a.role, a.created_at,
                       ap.can_manage_products, ap.can_manage_users, ap.can_manage_orders,
                       ap.can_manage_support, ap.can_view_dashboard, ap.can_manage_branding
                FROM admins a
                LEFT JOIN admin_permissions ap ON ap.admin_id = a.id
                ORDER BY a.created_at ASC
            ");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('AdminModel::getAllForCsvExport Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Create a password reset token for an admin (valid for 60 minutes).
     * It returns the raw token, which is emailed — only a hash of it is stored in the table.
     */
    public static function createPasswordReset(string $email, string $userType = 'admin'): ?string
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
            error_log("AdminModel::createPasswordReset Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify an admin's password reset token (unused and unexpired).
     */
    public static function validatePasswordResetToken(string $email, string $token, string $userType = 'admin'): bool
    {
        try {
            $db = self::db();
            $tokenHash = hash('sha256', $token);
            $stmt = $db->prepare("SELECT id FROM password_resets WHERE email = ? AND user_type = ? AND token_hash = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
            $stmt->execute([$email, $userType, $tokenHash]);
            return (bool)$stmt->fetch();
        } catch (\Exception $e) {
            error_log("AdminModel::validatePasswordResetToken Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Consume the token once used, which prevents reusing the same link.
     */
    public static function consumePasswordResetToken(string $email, string $token, string $userType = 'admin'): void
    {
        $db = self::db();
        $tokenHash = hash('sha256', $token);
        $stmt = $db->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND user_type = ? AND token_hash = ?");
        $stmt->execute([$email, $userType, $tokenHash]);
    }

    /**
     * Update the admin's password (it receives the already-computed hash).
     */
    public static function updatePassword(int $adminId, string $newPasswordHash): bool
    {
        try {
            $db = self::db();
            $stmt = $db->prepare("UPDATE admins SET password = ? WHERE id = ?");
            return $stmt->execute([$newPasswordHash, $adminId]);
        } catch (\Exception $e) {
            error_log("AdminModel::updatePassword Error: " . $e->getMessage());
            return false;
        }
    }

    // ════════════════════════════════════════════════════════
    // Two-factor authentication (2FA / TOTP) — optional per admin
    // ════════════════════════════════════════════════════════

    public static function enable2FA(int $adminId, string $secret): bool
    {
        $db = self::db();
        // last_totp_slice is cleared on every activation: the secret is new, so the
        // consumed slices belong to a different secret and carrying them over is meaningless.
        $stmt = $db->prepare(
            "UPDATE admins SET totp_secret = ?, totp_enabled = 1, last_totp_slice = NULL WHERE id = ?"
        );
        return $stmt->execute([$secret, $adminId]);
    }

    public static function disable2FA(int $adminId): bool
    {
        $db = self::db();
        $stmt = $db->prepare(
            "UPDATE admins SET totp_secret = NULL, totp_enabled = 0, last_totp_slice = NULL WHERE id = ?"
        );
        return $stmt->execute([$adminId]);
    }

    /**
     * Records the last consumed TOTP slice — which prevents reusing the same code.
     *
     * The condition `last_totp_slice IS NULL OR last_totp_slice < ?` is not decoration:
     * two concurrent requests with the same code can both clear the verification check
     * before either writes. The condition makes the write itself the arbiter, so only one
     * succeeds — and the method returns false for the other, for the caller to refuse.
     */
    public static function consumeTotpSlice(int $adminId, int $slice): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "UPDATE admins SET last_totp_slice = ?
                 WHERE id = ? AND (last_totp_slice IS NULL OR last_totp_slice < ?)"
            );
            $stmt->execute([$slice, $adminId, $slice]);
            return $stmt->rowCount() === 1;
        } catch (Exception $e) {
            error_log("AdminModel::consumeTotpSlice Error: " . $e->getMessage());
            return false;
        }
    }
}
