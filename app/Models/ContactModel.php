<?php

namespace App\Models;

use App\Core\Model;
use Exception;

class ContactModel extends Model
{
    /**
     * Save a new contact message to the database.
     */
    public static function save(?int $userId, string $fullName, string $email, string $message): bool
    {
        try {
            $db = self::db();
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
