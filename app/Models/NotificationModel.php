<?php

namespace App\Models;

use App\Core\Model;
use Exception;

/**
 * NotificationModel — covers the notifications table (user notifications).
 */
class NotificationModel extends Model
{
    /**
     * Fetch the user's notification list.
     *
     * Null when the query failed, not an empty list: an empty list makes the bell read
     * "No notifications" and the badge 0, which is a false statement during an outage.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function getList(int $userId, int $limit = 30): ?array
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "SELECT id, title, message, is_read, related_type, related_id, created_at
                 FROM notifications
                 WHERE user_id = ?
                 ORDER BY created_at DESC
                 LIMIT ?"
            );
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("NotificationModel::getList Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * The unread notification count — null when the query failed, for the same reason as
     * getList: 0 is a real answer, and it set the badge to zero during an outage.
     */
    public static function countUnread(int $userId): ?int
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
            );
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("NotificationModel::countUnread Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark a single notification read.
     */
    public static function markRead(int $notifId, int $userId): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "UPDATE notifications SET is_read = 1
                 WHERE id = ? AND user_id = ?"
            );
            return $stmt->execute([$notifId, $userId]);
        } catch (Exception $e) {
            error_log("NotificationModel::markRead Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark every notification read.
     */
    public static function markAllRead(int $userId): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "UPDATE notifications SET is_read = 1 WHERE user_id = ?"
            );
            return $stmt->execute([$userId]);
        } catch (Exception $e) {
            error_log("NotificationModel::markAllRead Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Dismiss a notification — delete it, as the endpoint's own documentation says.
     *
     * It used to call markRead. And the list is every notification, read or not, so the one
     * the user had just removed came back on the next poll — the × appeared to work, then the
     * notification returned within a minute. Scoped to its owner like every other write here.
     */
    public static function dismiss(int $notifId, int $userId): bool
    {
        try {
            $stmt = self::db()->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
            return $stmt->execute([$notifId, $userId]);
        } catch (Exception $e) {
            error_log('NotificationModel::dismiss Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete all of the user's notifications.
     */
    public static function deleteAll(int $userId): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
            return $stmt->execute([$userId]);
        } catch (Exception $e) {
            error_log("NotificationModel::deleteAll Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert a new notification (used by checkout and orders as needed).
     */
    public static function insert(int $userId, string $title, string $message, ?int $adminId = null, ?string $relatedType = null, ?int $relatedId = null): bool
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "INSERT INTO notifications
                    (user_id, title, message, sender_admin_id, related_type, related_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            return $stmt->execute([$userId, $title, $message, $adminId, $relatedType, $relatedId]);
        } catch (Exception $e) {
            error_log("NotificationModel::insert Error: " . $e->getMessage());
            return false;
        }
    }
}
