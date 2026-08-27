<?php

namespace App\Models;

use App\Core\Database;
use Exception;

/**
 * AdminNotificationModel — يغطي جدول admin_notifications (إشعارات الأدمنية)
 * الإدراج لا يتم هنا — فقط عبر AdminModel::sendNotification() الموجودة.
 */
class AdminNotificationModel
{
    /**
     * جلب قائمة الإشعارات لأدمن معيّن، الأحدث أولًا
     */
    public static function getList(int $adminId, int $limit = 30): array
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare(
                "SELECT id, title, message, type, related_type, related_id, is_read, created_at
                 FROM admin_notifications
                 WHERE admin_id = ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT ?"
            );
            $stmt->execute([$adminId, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("AdminNotificationModel::getList Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * عدد الإشعارات غير المقروءة لأدمن معيّن
     */
    public static function countUnread(int $adminId): int
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM admin_notifications WHERE admin_id = ? AND is_read = 0"
            );
            $stmt->execute([$adminId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * تحديد إشعار واحد كمقروء — مشروط بـ admin_id (منع IDOR)
     */
    public static function markRead(int $notifId, int $adminId): bool
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare(
                "UPDATE admin_notifications SET is_read = 1
                 WHERE id = ? AND admin_id = ?"
            );
            return $stmt->execute([$notifId, $adminId]);
        } catch (Exception $e) {
            error_log("AdminNotificationModel::markRead Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * تحديد كل إشعارات الأدمن كمقروءة
     */
    public static function markAllRead(int $adminId): bool
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare(
                "UPDATE admin_notifications SET is_read = 1 WHERE admin_id = ?"
            );
            return $stmt->execute([$adminId]);
        } catch (Exception $e) {
            error_log("AdminNotificationModel::markAllRead Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * حذف كل إشعارات الأدمن
     */
    public static function deleteAll(int $adminId): bool
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare("DELETE FROM admin_notifications WHERE admin_id = ?");
            return $stmt->execute([$adminId]);
        } catch (Exception $e) {
            error_log("AdminNotificationModel::deleteAll Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * حذف إشعار واحد للأدمن — مشروط بـ admin_id (منع IDOR)
     */
    public static function dismiss(int $notifId, int $adminId): bool
    {
        try {
            $stmt = Database::connect()->prepare(
                "DELETE FROM admin_notifications WHERE id = ? AND admin_id = ?"
            );
            $stmt->execute([$notifId, $adminId]);
            return true;
        } catch (Exception $e) {
            error_log('AdminNotificationModel::dismiss Error: ' . $e->getMessage());
            return false;
        }
    }
}
