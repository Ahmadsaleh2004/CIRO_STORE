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
     * @return list<array<string, mixed>>
     */
    public static function getList(int $userId, int $limit = 30): array
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
            return [];
        }
    }

    /**
     * The unread notification count.
     */
    public static function countUnread(int $userId): int
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
            );
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
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
     * Dismiss a notification — it leaves the list by way of setting is_read.
     */
    public static function dismiss(int $notifId, int $userId): bool
    {
        return self::markRead($notifId, $userId);
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
