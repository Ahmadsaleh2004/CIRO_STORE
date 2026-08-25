<?php

namespace App\Models;

use App\Core\Database;
use Exception;

class ContactModel
{
    /**
     * حفظ رسالة تواصل جديدة في قاعدة البيانات
     */
    public static function save(?int $userId, string $fullName, string $email, string $message): bool
    {
        try {
            $db = Database::connect();
            $stmt = $db->prepare(
                "INSERT INTO contact_messages (user_id, full_name, email, message, is_notified)
                 VALUES (?, ?, ?, ?, 0)"
            );
            return $stmt->execute([$userId, $fullName, $email, $message]);
        } catch (Exception $e) {
            error_log("ContactModel::save Error: " . $e->getMessage());
            return false;
        }
    }
}