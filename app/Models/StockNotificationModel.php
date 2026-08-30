<?php

namespace App\Models;

use App\Core\Model;
use PDO;
use Exception;

/**
 * StockNotificationModel — the stock_notifications table: "notify me when this
 * product is available" requests.
 *
 * Why a model of its own?
 * The queries against this table used to be written inline inside three controllers
 * (Wishlist / Product / AdminProducts), four of them identical word for word. The
 * table is an entity in its own right, belonging to neither the product nor the
 * user alone, so gathering it here is clearer than splitting it across ProductModel
 * and UserModel.
 *
 * Every method is static, consistent with the rest of the project's models, and
 * they all swallow the exception, log it, and return a neutral value — the same
 * pattern as ProductModel and AdminModel: a secondary query failing must not bring
 * the whole page down.
 */
class StockNotificationModel extends Model
{
    /**
     * Has this user requested a notification for this product?
     */
    public static function exists(int $productId, int $userId): bool
    {
        try {
            $stmt = self::db()->prepare(
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
     * Every product this user has requested a notification for.
     *
     * @return int[]
     */
    public static function productIdsForUser(int $userId): array
    {
        try {
            $stmt = self::db()->prepare(
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
     * The same as above but restricted to a set of products — to avoid fetching all of
     * a user's requests when only a handful of products' status is needed.
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
            $stmt = self::db()->prepare(
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
     * Records a notification request, if one is not recorded already.
     *
     * @return bool true if a new row was inserted; false if it already existed or the insert failed.
     */
    public static function add(int $productId, int $userId): bool
    {
        if (self::exists($productId, $userId)) {
            return false;
        }

        try {
            self::db()
                ->prepare("INSERT INTO stock_notifications (product_id, user_id) VALUES (?, ?)")
                ->execute([$productId, $userId]);
            return true;
        } catch (Exception $e) {
            error_log('StockNotificationModel::add Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * How many requests are recorded against a product.
     */
    public static function countForProduct(int $productId): int
    {
        try {
            $stmt = self::db()->prepare(
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
     * The ids of the users waiting for a product to come back in stock.
     *
     * @return int[]
     */
    public static function waitingUserIds(int $productId): array
    {
        try {
            $stmt = self::db()->prepare(
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
     * Deletes every notification request against a product — called after the
     * notifications have actually been sent, so a user is not told twice about the same
     * restock.
     */
    public static function clearForProduct(int $productId): void
    {
        try {
            self::db()
                ->prepare("DELETE FROM stock_notifications WHERE product_id = ?")
                ->execute([$productId]);
        } catch (Exception $e) {
            error_log('StockNotificationModel::clearForProduct Error: ' . $e->getMessage());
        }
    }
}
