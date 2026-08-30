<?php

namespace App\Services;

use App\Models\AdminModel;
use App\Models\NotificationModel;
use App\Models\ProductModel;
use App\Models\StockNotificationModel;
use App\Models\UserModel;
use App\Models\AdminProductModel;

/**
 * StockNotifier — every stock notification in one place.
 *
 * Why a service of its own?
 * This logic used to be three private methods spread across two controllers
 * (AdminProductsController and WishlistController), all repeating the same pattern:
 * fetch the list of targets, then send each of them a notification whose text is built
 * right there. The wording and the target ranks are product decisions, not controller
 * decisions — and scattering them meant changing one notification's wording required
 * searching two files.
 *
 * Every method is static, consistent with the rest of the project's layers, and none
 * of them throws: a notification failing to send must not bring down a product save
 * that has already succeeded.
 */
class StockNotifier
{
    /** The permission identifying the admins concerned with stock notifications. */
    private const PERM = 'can_manage_products';

    /**
     * Tells every user who requested "notify me" that the product is back in stock, then
     * clears the requests so nobody is told twice about the same restock.
     *
     * @param int|null $actorAdminId The admin who caused the change (for the notification record)
     */
    public static function productBackInStock(int $productId, string $productName, ?int $actorAdminId = null): void
    {
        $userIds = StockNotificationModel::waitingUserIds($productId);

        if (empty($userIds)) {
            return;
        }

        foreach ($userIds as $userId) {
            NotificationModel::insert(
                $userId,
                'Product Back in Stock! 🎉',
                "\"{$productName}\" you wanted is now back in stock!",
                $actorAdminId,
                'product',
                $productId
            );
        }

        StockNotificationModel::clearForProduct($productId);
    }

    /**
     * Tells the admins a product has run out entirely — it does nothing while the stock
     * is still above zero.
     *
     * It covers all four ranks including A: a stock-out is an operational event that
     * concerns everyone who manages products.
     */
    public static function productOutOfStock(int $productId, string $productName): void
    {
        if (AdminProductModel::getTotalStock($productId) > 0) {
            return;
        }

        $message = "The product \"{$productName}\" (#{$productId}) is now out of stock "
                 . "(0 units across all colors).";

        self::notifyAdmins(
            ['A', 'B', 'C', 'D'],
            'Product Out of Stock ⚠️',
            $message,
            'product_out_of_stock',
            $productId
        );
    }

    /**
     * Tells the admins that a user has asked to be notified when a product is available.
     *
     * Rank A is excluded here deliberately — one customer's request is not an event
     * warranting disturbing the root admin, unlike a stock-out.
     */
    public static function customerRequestedNotification(int $productId, int $requestingUserId): void
    {
        $productName  = ProductModel::getNameById($productId) ?? "Product #{$productId}";
        $userName     = UserModel::getFullNameById($requestingUserId) ?? 'A customer';
        $requestCount = StockNotificationModel::countForProduct($productId);

        $message = "{$userName} requested to be notified when this product is back in stock "
                 . "({$requestCount})";

        self::notifyAdmins(
            ['B', 'C', 'D'],
            $productName,
            $message,
            'stock_notify_request',
            $productId
        );
    }

    /**
     * Sends one notification to each admin within the given ranks who holds the
     * product-management permission. The pattern shared by the two methods above.
     *
     * @param string[] $ranks
     */
    private static function notifyAdmins(
        array $ranks,
        string $title,
        string $message,
        string $type,
        int $productId
    ): void {
        foreach (AdminModel::findByPermsAndRanks([self::PERM], $ranks) as $adminId) {
            AdminModel::sendNotification(
                (int)$adminId,
                $title,
                $message,
                $type,
                'product',
                $productId
            );
        }
    }
}
