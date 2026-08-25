<?php

namespace App\Services;

use App\Models\AdminModel;
use App\Models\NotificationModel;
use App\Models\ProductModel;
use App\Models\StockNotificationModel;
use App\Models\UserModel;
use App\Models\AdminProductModel;

/**
 * StockNotifier — كل إشعارات المخزون في مكان واحد.
 *
 * لماذا خدمة مستقلة؟
 * كان هذا المنطق ثلاث دوال private موزّعة على كنترولرين
 * (AdminProductsController و WishlistController)، وكلها تكرّر نفس النمط:
 * اجلب قائمة المستهدفين، ثم أرسل لكل واحد إشعاراً بنص مبني هنا. النصوص
 * والرتب المستهدفة قرارات منتَج، لا قرارات كنترولر — وتفرّقها كان يعني
 * أن تغيير صياغة إشعار واحد يتطلّب البحث في ملفين.
 *
 * كل الدوال static اتساقاً مع بقية طبقات المشروع، ولا شيء منها يرمي:
 * فشل إرسال إشعار يجب ألّا يُسقط عملية حفظ منتج نجحت بالفعل.
 */
class StockNotifier
{
    /** صلاحية الأدمن المعنيّ بإشعارات المخزون. */
    private const PERM = 'can_manage_products';

    /**
     * يُبلّغ كل مستخدم طلب "نبّهني" أن المنتج عاد للتوفّر، ثم يمسح
     * الطلبات كي لا يُبلَّغ مرتين عن نفس التوفّر.
     *
     * @param int|null $actorAdminId الأدمن الذي سبّب التغيير (لسجل الإشعار)
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
     * يُبلّغ الأدمنية أن منتجاً نفد بالكامل — لا يفعل شيئاً إن كان
     * المخزون لا يزال أكبر من صفر.
     *
     * يشمل الرتب الأربع بما فيها A: نفاد المخزون حدث تشغيلي يعني كل من
     * يدير المنتجات.
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
     * يُبلّغ الأدمنية أن مستخدماً طلب إشعاراً عند توفّر منتج.
     *
     * الرتبة A مستثناة هنا عمداً — طلب زبون واحد ليس حدثاً يستدعي
     * إزعاج الأدمن الأساسي، بخلاف نفاد المخزون.
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
     * يرسل إشعاراً واحداً لكل أدمن ضمن الرتب المعطاة ولديه صلاحية
     * إدارة المنتجات. النمط المشترك بين الدالتين أعلاه.
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
