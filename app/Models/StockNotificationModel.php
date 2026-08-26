<?php

namespace App\Models;

use App\Core\Database;
use PDO;
use Exception;

/**
 * StockNotificationModel — جدول stock_notifications: طلبات "نبّهني عند
 * توفّر المنتج".
 *
 * لماذا موديل مستقل؟
 * كانت استعلامات هذا الجدول مكتوبة مباشرة داخل ثلاثة كنترولرز
 * (Wishlist / Product / AdminProducts)، وأربعة منها متطابقة حرفياً.
 * الجدول كيان قائم بذاته لا يخصّ المنتج ولا المستخدم وحدهما، فجمعه هنا
 * أوضح من توزيعه على ProductModel و UserModel.
 *
 * كل الدوال static اتساقاً مع بقية موديلات المشروع، وكلها تبتلع
 * الاستثناء وتسجّله ثم تُرجع قيمة محايدة — نفس نمط ProductModel و
 * AdminModel: فشل استعلام ثانوي يجب ألّا يُسقط الصفحة كلها.
 */
class StockNotificationModel
{
    /**
     * هل طلب هذا المستخدم إشعاراً عن هذا المنتج؟
     */
    public static function exists(int $productId, int $userId): bool
    {
        try {
            $stmt = Database::connect()->prepare(
                "SELECT id FROM stock_notifications
                 WHERE product_id = ? AND user_id = ? LIMIT 1"
            );
            $stmt->execute([$productId, $userId]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            error_log('StockNotificationModel::exists Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * كل المنتجات التي طلب هذا المستخدم إشعاراً عنها.
     *
     * @return int[]
     */
    public static function productIdsForUser(int $userId): array
    {
        try {
            $stmt = Database::connect()->prepare(
                "SELECT product_id FROM stock_notifications WHERE user_id = ?"
            );
            $stmt->execute([$userId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) {
            error_log('StockNotificationModel::productIdsForUser Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * نفس السابقة لكن مقيّدة بمجموعة منتجات — لتفادي جلب كل طلبات
     * المستخدم عند الحاجة لحالة بضعة منتجات فقط.
     *
     * @param  int[] $productIds
     * @return int[]
     */
    public static function productIdsForUserWithin(int $userId, array $productIds): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));
        if (!$productIds) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmt = Database::connect()->prepare(
                "SELECT product_id FROM stock_notifications
                 WHERE user_id = ? AND product_id IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$userId], $productIds));
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) {
            error_log('StockNotificationModel::productIdsForUserWithin Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * يسجّل طلب إشعار إن لم يكن مسجّلاً.
     *
     * @return bool true إذا أُضيف صفّ جديد، false إذا كان موجوداً أو فشل.
     */
    public static function add(int $productId, int $userId): bool
    {
        if (self::exists($productId, $userId)) {
            return false;
        }

        try {
            Database::connect()
                ->prepare("INSERT INTO stock_notifications (product_id, user_id) VALUES (?, ?)")
                ->execute([$productId, $userId]);
            return true;
        } catch (Exception $e) {
            error_log('StockNotificationModel::add Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * عدد الطلبات المسجّلة على منتج.
     */
    public static function countForProduct(int $productId): int
    {
        try {
            $stmt = Database::connect()->prepare(
                "SELECT COUNT(*) FROM stock_notifications WHERE product_id = ?"
            );
            $stmt->execute([$productId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log('StockNotificationModel::countForProduct Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * معرّفات المستخدمين المنتظرين توفّر منتج.
     *
     * @return int[]
     */
    public static function waitingUserIds(int $productId): array
    {
        try {
            $stmt = Database::connect()->prepare(
                "SELECT DISTINCT user_id FROM stock_notifications WHERE product_id = ?"
            );
            $stmt->execute([$productId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) {
            error_log('StockNotificationModel::waitingUserIds Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * يحذف كل طلبات الإشعار على منتج — تُستدعى بعد إرسال الإشعارات
     * فعلياً، حتى لا يُبلَّغ المستخدم مرتين عن نفس التوفّر.
     */
    public static function clearForProduct(int $productId): void
    {
        try {
            Database::connect()
                ->prepare("DELETE FROM stock_notifications WHERE product_id = ?")
                ->execute([$productId]);
        } catch (Exception $e) {
            error_log('StockNotificationModel::clearForProduct Error: ' . $e->getMessage());
        }
    }
}
