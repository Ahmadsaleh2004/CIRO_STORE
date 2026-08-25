<?php

namespace App\Models;

use App\Core\Database;
use Exception;

/**
 * SupportModel — يغطي جدول contact_messages من جهة لوحة تحكم الأدمن فقط
 * (ContactModel المستخدم بجهة المتجر العام يبقى مسؤول فقط عن ::save() لإدخال رسالة جديدة)
 */
class SupportModel
{
    /**
     * إجمالي عدد الرسائل (مع فلترة بحث اختيارية) — تستخدم لحساب الـ Pagination
     */
    public static function countAll(string $search = ''): int
    {
        try {
            $db = Database::connect();
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
     * جلب صفحة من الرسائل مع اسم المستخدم (LEFT JOIN users) مرتبة تنازلياً بتاريخ الإرسال
     */
    public static function getPage(string $search, int $perPage, int $offset): array
    {
        try {
            $db     = Database::connect();
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
     * رسائل الدعم يلي بعتها يوزر معيّن (بالـ user_id أو بنفس إيميله لو ما كان مسجّل وقتها)
     */
    public static function getMessagesForUser(int $userId, string $email): array
    {
        try {
            $db   = Database::connect();
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
     * تحديد كل الرسائل كمقروءة (تُستدعى عند فتح الصفحة — نفس سلوك القديم بالحرف)
     */
    public static function markAllNotified(): void
    {
        try {
            Database::connect()->query("UPDATE contact_messages SET is_notified = 1");
        } catch (Exception $e) {
            error_log("SupportModel::markAllNotified Error: " . $e->getMessage());
        }
    }

    /**
     * حذف رسالة واحدة. يرجع true/false
     */
    public static function delete(int $messageId): bool
    {
        try {
            $stmt = Database::connect()->prepare("DELETE FROM contact_messages WHERE id = ?");
            return $stmt->execute([$messageId]);
        } catch (Exception $e) {
            error_log("SupportModel::delete Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * التحقق من وجود مستخدم بمعرّف معيّن (قبل إرسال رد له)
     */
    public static function userExists(int $userId): bool
    {
        try {
            $stmt = Database::connect()->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            return (bool) $stmt->fetch();
        } catch (Exception $e) {
            error_log("SupportModel::userExists Error: " . $e->getMessage());
            return false;
        }
    }
}
