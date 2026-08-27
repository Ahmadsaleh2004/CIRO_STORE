<?php

namespace App\Models;

use App\Core\Database;
use Exception;

/**
 * AdminModel — يغطي جدول admins حصراً
 * لا يلمس جدول users بأي شكل من الأشكال
 */
class AdminModel
{
    // ════════════════════════════════════════════════════════
    // الحدود والنوافذ الزمنية لـ Rate Limiting (أشد من المستخدم العادي)
    // ════════════════════════════════════════════════════════
    private const MAX_FAILED_ATTEMPTS = 3;
    private const WINDOW_MINUTES      = 30;
    private const LOCKOUT_MINUTES     = 30;

    // ════════════════════════════════════════════════════════
    // جلب أدمن بالإيميل من جدول admins فقط
    // ════════════════════════════════════════════════════════
    public static function findByEmail(string $email): ?array
    {
        try {
            $db   = Database::connect();
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
    // عدد المحاولات الفاشلة خلال النافذة الزمنية
    // ════════════════════════════════════════════════════════
    public static function getFailedAttempts(string $email): int
    {
        try {
            $db   = Database::connect();
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
    // تسجيل محاولة تسجيل الدخول (ناجحة أو فاشلة)
    // ════════════════════════════════════════════════════════
    public static function logLoginAttempt(string $email, bool $success): void
    {
        try {
            $db   = Database::connect();
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
    // فحص Rate Limiting — 3 محاولات فاشلة خلال 30 دقيقة = حظر 30 دقيقة
    // ════════════════════════════════════════════════════════
    public static function isRateLimited(string $email): bool
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM admin_login_attempts
                 WHERE email = ? AND success = 0
                 AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
            );
            $stmt->execute([strtolower(trim($email)), self::LOCKOUT_MINUTES]);
            return (int)$stmt->fetchColumn() >= self::MAX_FAILED_ATTEMPTS;
        } catch (Exception $e) {
            error_log("AdminModel::isRateLimited Error: " . $e->getMessage());
            return false; // الأمان: في حالة الخطأ لا نحجب
        }
    }

    // ════════════════════════════════════════════════════════
    // تحديث last_activity للأدمن
    // ════════════════════════════════════════════════════════
    public static function updateActivity(int $adminId): void
    {
        try {
            $db = Database::connect();
            $db->prepare("UPDATE admins SET last_activity = NOW() WHERE id = ?")
               ->execute([$adminId]);
        } catch (Exception $e) {
            error_log("AdminModel::updateActivity Error: " . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // جلب أدمن بالـ ID من جدول admins
    // ════════════════════════════════════════════════════════
    public static function findById(int $id): ?array
    {
        try {
            $db   = Database::connect();
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
    // جلب صلاحيات أدمن من جدول admin_permissions
    // ════════════════════════════════════════════════════════
    public static function getPermissions(int $adminId): array
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare("SELECT * FROM admin_permissions WHERE admin_id = ?");
            $stmt->execute([$adminId]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("AdminModel::getPermissions Error: " . $e->getMessage());
            return [];
        }
    }

    // ════════════════════════════════════════════════════════
    // تسجيل عملية بجدول admin_audit_log
    // ════════════════════════════════════════════════════════
    public static function logAction(
        int $adminId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $details = null
    ): void {
        try {
            $db   = Database::connect();
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
    // تحديث بيانات أدمن (الاسم / الهاتف / كلمة المرور إن وُجدت)
    // ════════════════════════════════════════════════════════
    public static function updateProfile(int $adminId, array $data): bool
    {
        try {
            $db     = Database::connect();
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
    // نظام الهرمية — خريطة قيم الرتب (A أعلى، D أدنى)
    // ════════════════════════════════════════════════════════

    /** خريطة قيمة الرتبة — كلما الرقم أعلى، الرتبة أعلى (A=4 أعلى ... D=1 أدنى) */
    private const RANK_VALUES = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1];

    public static function getRankValue(string $role): int
    {
        return self::RANK_VALUES[$role] ?? 0;
    }

    /** true فقط إذا رتبة $actorRole أعلى STRICTLY من رتبة $targetRole */
    public static function canManageTarget(string $actorRole, string $targetRole): bool
    {
        return self::getRankValue($actorRole) > self::getRankValue($targetRole);
    }

    public static function getRootAdminId(): ?int
    {
        try {
            $id = Database::connect()->query("SELECT id FROM admins WHERE role='A' LIMIT 1")->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Exception $e) {
            error_log("AdminModel::getRootAdminId Error: " . $e->getMessage());
            return null;
        }
    }

    // ════════════════════════════════════════════════════════
    // جلب بيانات الأدمنية مع الصلاحيات
    // ════════════════════════════════════════════════════════

    /** كل الأدمنية + صلاحياتهم (LEFT JOIN)، مرتبين حسب تاريخ الإضافة */
    public static function getAllWithPermissions(): array
    {
        try {
            $stmt = Database::connect()->query("
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

    /** نفس الاستعلام أعلاه بس لأدمن واحد (لصفحة admin-details) */
    public static function getByIdWithPermissions(int $id): ?array
    {
        try {
            $stmt = Database::connect()->prepare("
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
            return (int)Database::connect()->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        } catch (Exception $e) {
            error_log("AdminModel::countAdmins Error: " . $e->getMessage());
            return 0;
        }
    }

    // ════════════════════════════════════════════════════════
    // تحقق من كلمة مرور + تحقق من تكرار الإيميل
    // ════════════════════════════════════════════════════════

    public static function verifyPassword(int $adminId, string $plainPassword): bool
    {
        try {
            $stmt = Database::connect()->prepare("SELECT password FROM admins WHERE id=? LIMIT 1");
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
            $stmt = Database::connect()->prepare("SELECT id FROM admins WHERE email=? LIMIT 1");
            $stmt->execute([strtolower(trim($email))]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            error_log("AdminModel::emailExists Error: " . $e->getMessage());
            return false;
        }
    }

    // ════════════════════════════════════════════════════════
    // CRUD: إنشاء / حذف / تحديث صلاحيات
    // ════════════════════════════════════════════════════════

    /**
     * إنشاء أدمن جديد + صلاحياته بمعاملة واحدة (transaction)
     * يرجع الـ id الجديد أو null عند الفشل
     */
    public static function createAdmin(array $data, array $perms): ?int
    {
        $db = Database::connect();
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

    public static function deleteAdmin(int $id): bool
    {
        $db = Database::connect();
        try {
            $db->beginTransaction();

            // Disable FK checks for reordering PK while FKs exist
            $db->exec("SET FOREIGN_KEY_CHECKS=0");

            $db->prepare("DELETE FROM admins WHERE id=?")->execute([$id]);

            $stmt = $db->prepare("SELECT id FROM admins WHERE id > ? ORDER BY id ASC");
            $stmt->execute([$id]);
            $toShift = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($toShift as $oldId) {
                $newId = $oldId - 1;

                $db->prepare("UPDATE admins SET id=? WHERE id=?")->execute([$newId, $oldId]);
                $db->prepare("UPDATE admin_permissions SET admin_id=? WHERE admin_id=?")->execute([$newId, $oldId]);
                $db->prepare("UPDATE admins SET added_by=? WHERE added_by=?")->execute([$newId, $oldId]);
                $db->prepare("UPDATE admin_notifications SET admin_id=? WHERE admin_id=?")->execute([$newId, $oldId]);
                $db->prepare("UPDATE admin_notifications SET sender_admin_id=? WHERE sender_admin_id=?")->execute([$newId, $oldId]);
                $db->prepare("UPDATE home_sliders SET updated_by_admin_id=? WHERE updated_by_admin_id=?")->execute([$newId, $oldId]);
                $db->prepare("UPDATE order_expiry_log SET previous_admin_id=? WHERE previous_admin_id=?")->execute([$newId, $oldId]);
                $db->prepare("UPDATE user_strikes SET issued_by_admin_id=? WHERE issued_by_admin_id=?")->execute([$newId, $oldId]);
                $db->prepare("UPDATE orders SET taken_by_admin_id=? WHERE taken_by_admin_id=?")->execute([$newId, $oldId]);
            }

            $maxId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM admins")->fetchColumn();
            $db->exec("ALTER TABLE admins AUTO_INCREMENT = " . ($maxId + 1));

            $db->exec("SET FOREIGN_KEY_CHECKS=1");
            $db->commit();
            return true;
        } catch (\Exception $e) {
            $db->exec("SET FOREIGN_KEY_CHECKS=1");
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("AdminModel::deleteAdmin Error: " . $e->getMessage());
            return false;
        }
    }

    /** تحديث الرتبة + الصلاحيات معًا في معاملة واحدة */
    public static function updatePermissions(int $id, ?string $newRole, array $perms, int $editorAdminId): bool
    {
        $db = Database::connect();
        try {
            $db->beginTransaction();

            if (in_array($newRole, ['A', 'B', 'C', 'D'], true)) {
                $db->prepare("UPDATE admins SET role=?, updated_at=NOW(), last_modified_by=? WHERE id=?")
                   ->execute([$newRole, $editorAdminId, $id]);
            } else {
                // لا تغيير بالرتبة — لسا لازم نحدّث updated_at لأن تعديل صلاحيات صار فعليًا
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
    // إشعارات الأدمن (admin_notifications)
    // ════════════════════════════════════════════════════════

    /** إرسال إشعار فردي لأدمن */
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
            Database::connect()->prepare("
                INSERT INTO admin_notifications
                    (admin_id, title, message, type, related_type, related_id, sender_admin_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$recipientAdminId, $title, $message, $type, $relatedType, $relatedId, $senderAdminId]);
        } catch (Exception $e) {
            error_log('AdminModel::sendNotification Error: ' . $e->getMessage());
        }
    }

    /**
     * أدمنية يتوفر فيهم: أي صلاحية من $perms AND رتبة من $ranks (لـ Broadcast)
     * يرجع مصفوفة من الـ IDs
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

            $stmt = Database::connect()->prepare("
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
     * أدمنية يملكون صلاحية معيّنة AND رتبتهم أعلى (Strictly Higher) من رتبة
     * $actorRole — مع استثناء رتبة A (الأدمن الأساسي) دائماً من النتيجة، حتى لو
     * كانت رتبته أعلى فعلياً من $actorRole.
     * يُستخدم لإشعارات "أبلغ المشرفين الأعلى مباشرة" بدون إزعاج الأدمن الجذر.
     *
     * @return int[] IDs الأدمنية المستهدفين (قد تكون مصفوفة فارغة)
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
    // سجل أفعال أدمن معيّن من admin_audit_log (لصفحة details)
    // ════════════════════════════════════════════════════════

    public static function getAuditLogForAdmin(int $adminId, int $limit = 50): array
    {
        try {
            $stmt = Database::connect()->prepare("
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
     * سجل تدقيق مفلتر: فقط target_type ضمن القائمة المُعطاة.
     * تُستخدم لأقسام Admin Details المتخصصة (Orders/User/Product/Branding/Support/Site Config).
     */
    public static function getAuditLogByTypes(int $adminId, array $targetTypes, int $limit = 50): array
    {
        if (!$targetTypes) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($targetTypes), '?'));
            $stmt = Database::connect()->prepare("
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
     * سجل تدقيق مفلتر بالاستبعاد: كل شيء ما عدا target_type المذكرة، بالإضافة لأي صف
     * target_type فيه NULL (تسجيل دخول/خروج، تحديث بروفايل، تفعيل/تعطيل 2FA... إلخ).
     * تُستخدم لقسم "Admin Actions Log" العام، بعد ما صار عنده أقسام متخصصة منفصلة.
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

            $stmt = Database::connect()->prepare("
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
     * كل أفعال أي أدمن كانت على target_type='user' AND target_id=$userId
     * (لصفحة user-details — سجل نشاط الأدمنية على هاد المستخدم)
     */
    public static function getAuditLogForUser(int $userId, int $limit = 50): array
    {
        try {
            $stmt = Database::connect()->prepare("
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
    // تصدير CSV — Role A فقط (نفس تقييد القديم)
    // ════════════════════════════════════════════════════════

    public static function getAllForCsvExport(): array
    {
        try {
            $stmt = Database::connect()->query("
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
     * إنشاء رمز إعادة تعيين كلمة المرور للأدمن (صالح 60 دقيقة).
     * يرجع التوكن الخام (يُرسل للإيميل) — يُخزَّن hash منه فقط في الجدول.
     */
    public static function createPasswordReset(string $email, string $userType = 'admin'): ?string
    {
        try {
            $db = \App\Core\Database::connect();
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            // الانتهاء يُحسب داخل MySQL (DATE_ADD) ليتطابق مع NOW() المستخدمة في التحقق —
            // مهم لأن منطقة PHP الزمنية قد تختلف عن منطقة MySQL
            $stmt = $db->prepare("INSERT INTO password_resets (email, user_type, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))");
            $stmt->execute([$email, $userType, $tokenHash]);

            return $token;
        } catch (\Exception $e) {
            error_log("AdminModel::createPasswordReset Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * التحقق من صحة رمز إعادة تعيين كلمة مرور الأدمن (غير مستخدم وغير منتهٍ).
     */
    public static function validatePasswordResetToken(string $email, string $token, string $userType = 'admin'): bool
    {
        try {
            $db = \App\Core\Database::connect();
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
     * استهلاك الرمز بعد استخدامه (يمنع إعادة استخدام نفس الرابط)
     */
    public static function consumePasswordResetToken(string $email, string $token, string $userType = 'admin'): void
    {
        $db = \App\Core\Database::connect();
        $tokenHash = hash('sha256', $token);
        $stmt = $db->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND user_type = ? AND token_hash = ?");
        $stmt->execute([$email, $userType, $tokenHash]);
    }

    /**
     * تحديث كلمة مرور الأدمن (تستقبل الـ hash الجاهز)
     */
    public static function updatePassword(int $adminId, string $newPasswordHash): bool
    {
        try {
            $db = \App\Core\Database::connect();
            $stmt = $db->prepare("UPDATE admins SET password = ? WHERE id = ?");
            return $stmt->execute([$newPasswordHash, $adminId]);
        } catch (\Exception $e) {
            error_log("AdminModel::updatePassword Error: " . $e->getMessage());
            return false;
        }
    }

    // ════════════════════════════════════════════════════════
    // التحقق الثنائي (2FA / TOTP) — اختياري لكل أدمن
    // ════════════════════════════════════════════════════════

    public static function enable2FA(int $adminId, string $secret): bool
    {
        $db = \App\Core\Database::connect();
        $stmt = $db->prepare("UPDATE admins SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
        return $stmt->execute([$secret, $adminId]);
    }

    public static function disable2FA(int $adminId): bool
    {
        $db = \App\Core\Database::connect();
        $stmt = $db->prepare("UPDATE admins SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?");
        return $stmt->execute([$adminId]);
    }
}
