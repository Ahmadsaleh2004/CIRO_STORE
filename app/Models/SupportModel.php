<?php

namespace App\Models;

use App\Core\Model;
use Exception;

/**
 * SupportModel — covers the contact_messages table from the admin panel's side only.
 * (ContactModel, used on the public store side, stays responsible for ::save() alone,
 * inserting a new message.)
 */
class SupportModel extends Model
{
    /**
     * The total message count (with an optional search filter) — used to compute pagination.
     */
    public static function countAll(string $search = ''): int
    {
        try {
            $db = self::db();
            if ($search !== '') {
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM contact_messages
                     WHERE full_name LIKE ? OR email LIKE ? OR message LIKE ?"
                );
                $like = "%{$search}%";
                $stmt->execute([$like, $like, $like]);
            } else {
                $stmt = $db->query("SELECT COUNT(*) FROM contact_messages");
            }
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("SupportModel::countAll Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Fetch a page of messages with the user's name (LEFT JOIN users), newest first.
     *
     * @return array<string, mixed>
     */
    public static function getPage(string $search, int $perPage, int $offset): array
    {
        try {
            $db     = self::db();
            $where  = '';
            $params = [];

            if ($search !== '') {
                $where  = " WHERE cm.full_name LIKE ? OR cm.email LIKE ? OR cm.message LIKE ? ";
                $like   = "%{$search}%";
                $params = [$like, $like, $like];
            }

            $stmt = $db->prepare(
                "SELECT cm.*, u.full_name AS user_name
                 FROM contact_messages cm
                 LEFT JOIN users u ON u.id = cm.user_id
                 {$where}
                 ORDER BY cm.sent_at DESC
                 LIMIT {$perPage} OFFSET {$offset}"
            );
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("SupportModel::getPage Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * The support messages a given user sent — by user_id, or by the same email if they
     * were not registered at the time.
     *
     * @return list<array<string, mixed>>
     */
    public static function getMessagesForUser(int $userId, string $email): array
    {
        try {
            $db   = self::db();
            $stmt = $db->prepare(
                "SELECT * FROM contact_messages
                 WHERE user_id = ? OR email = ?
                 ORDER BY sent_at DESC"
            );
            $stmt->execute([$userId, $email]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("SupportModel::getMessagesForUser Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Mark every message read (called when the page opens — the old behaviour, to the letter).
     */
    public static function markAllNotified(): void
    {
        try {
            self::db()->query("UPDATE contact_messages SET is_notified = 1");
        } catch (Exception $e) {
            error_log("SupportModel::markAllNotified Error: " . $e->getMessage());
        }
    }

    /**
     * Delete a single message. Returns true or false.
     */
    public static function delete(int $messageId): bool
    {
        try {
            $stmt = self::db()->prepare("DELETE FROM contact_messages WHERE id = ?");
            return $stmt->execute([$messageId]);
        } catch (Exception $e) {
            error_log("SupportModel::delete Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check that a user with the given id exists, before sending them a reply.
     */
    public static function userExists(int $userId): bool
    {
        try {
            $stmt = self::db()->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            return (bool) $stmt->fetch();
        } catch (Exception $e) {
            error_log("SupportModel::userExists Error: " . $e->getMessage());
            return false;
        }
    }
    /**
     * The text of a support message by its id, or null if it does not exist.
     *
     * Moved out of AdminSupportController, where it was a query written inline to read
     * the message before deleting it (so its text could be recorded in the admin log).
     */
    public static function getMessageText(int $messageId): ?string
    {
        try {
            $stmt = self::db()->prepare("SELECT message FROM contact_messages WHERE id = ?");
            $stmt->execute([$messageId]);
            $text = $stmt->fetchColumn();
            return $text !== false ? (string)$text : null;
        } catch (Exception $e) {
            error_log("SupportModel::getMessageText Error: " . $e->getMessage());
            return null;
        }
    }
}
