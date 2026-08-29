<?php

namespace Tests\Integration;

use App\Models\OrderModel;
use Tests\Support\DatabaseTestCase;

/**
 * إلغاء الطلب واسترجاع المخزون.
 *
 * ══════════════════════════════════════════════════════════════
 * لماذا هذا الملف
 * ══════════════════════════════════════════════════════════════
 *
 * الإلغاء هو **الجانب الآخر من نفس العدّاد** الذي يخفّضه الطلب. وأي
 * خطأ فيه يظهر كخسارة مباشرة بأحد اتجاهين:
 *
 *   · لم يُرجَع المخزون  → بضاعة موجودة في المستودع ولا تُباع.
 *   · أُرجع مرّتين       → بيعُ ما لا يوجد، ثم اعتذارٌ لزبون.
 *
 * والاتجاه الثاني هو ما يحرسه عمود `stock_restored`: علامة تقول إن
 * هذا الطلب أعاد بضاعته سلفاً. وهي بالضبط نوع الحماية التي لا يظهر
 * كسرُها إلا بعد أن يتراكم الخطأ في القاعدة أسابيع.
 *
 * ولا شيء كان يغطّي ذلك: صفر اختبار على OrderModel قبل هذه المرحلة.
 */
final class OrderCancellationTest extends DatabaseTestCase
{
    private function makeUser(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (full_name, email) VALUES (?, ?)');
        $stmt->execute(['Test Buyer', 'buyer' . uniqid() . '@example.com']);
        return (int) $this->pdo->lastInsertId();
    }

    private function makeAddress(int $userId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_addresses (user_id, full_address) VALUES (?, ?)');
        $stmt->execute([$userId, '1 Test Street']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} [productId, variantId] */
    private function makeProductWithVariant(float $price = 100.00, int $stock = 10): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, stock_quantity, is_visible) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute(['Test Product', $price, $stock]);
        $productId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, stock_quantity)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$productId, 'Black', $price, $stock]);

        return [$productId, (int) $this->pdo->lastInsertId()];
    }

    /** يضع طلباً سليماً ويُرجع معرّفه. */
    private function placeOrder(int $userId, int $addressId, int $productId, int $variantId, int $qty): int
    {
        $result = OrderModel::placeOrder($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => $qty,
            'shown_price' => 100.00,
        ]], 'cash_on_delivery', bin2hex(random_bytes(8)));

        $this->assertSame(OrderModel::PLACE_OK, $result['status'], 'تعذّر تجهيز طلب الاختبار.');

        return $result['order_id'];
    }

    private function variantStock(int $variantId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ?');
        $stmt->execute([$variantId]);
        return (int) $stmt->fetchColumn();
    }

    private function setStatus(int $orderId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE orders SET status = ? WHERE order_id = ?');
        $stmt->execute([$status, $orderId]);
    }

    // ════════════════════════════════════════════════════════
    // الإلغاء من المستخدم
    // ════════════════════════════════════════════════════════

    public function testCancellingReturnsTheStockAndRemovesTheOrder(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 3);
        $this->assertSame(7, $this->variantStock($variantId));

        $this->assertTrue(OrderModel::cancelOrder($orderId, $userId));

        $this->assertSame(10, $this->variantStock($variantId));
        $this->assertSame(0, $this->countRows('orders'));

        // حذف نهائي مقصود لهذه الحالة وحدها (not_taken)، وعناصره تتبعه.
        $this->assertSame(0, $this->countRows('order_items'));
    }

    public function testAUserCannotCancelSomeoneElsesOrder(): void
    {
        $ownerId   = $this->makeUser();
        $addressId = $this->makeAddress($ownerId);
        $strangerId = $this->makeUser();
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($ownerId, $addressId, $productId, $variantId, 2);

        // الملكية شرط في نفس عبارة SELECT (WHERE order_id=? AND user_id=?)،
        // لا فحصاً منفصلاً يمكن أن يُنسى في مسار آخر.
        $this->assertFalse(OrderModel::cancelOrder($orderId, $strangerId));

        $this->assertSame(1, $this->countRows('orders'));
        $this->assertSame(8, $this->variantStock($variantId), 'مخزون تحرّك رغم رفض الإلغاء.');
    }

    public function testAnOrderAlreadyTakenByAnAdminCannotBeCancelledByTheUser(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 2);
        $this->setStatus($orderId, 'taken');

        // القاعدة: يُلغي الزبون قبل أن يتولّى الطلبَ أدمن، لا بعده —
        // فبعدها تكون البضاعة في طريقها إليه.
        $this->assertFalse(OrderModel::cancelOrder($orderId, $userId));

        $this->assertSame(1, $this->countRows('orders'));
        $this->assertSame(8, $this->variantStock($variantId));
    }

    public function testCancellingTwiceDoesNotReturnTheStockTwice(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 4);

        $this->assertTrue(OrderModel::cancelOrder($orderId, $userId));
        $this->assertSame(10, $this->variantStock($variantId));

        // النقرة المزدوجة، أو طلبان متسابقان من تبويبين. الطلب لم يعد
        // موجوداً، فالثانية يجب أن تفشل بلا أثر — لا أن تضيف أربع قطع
        // وهمية إلى المخزون.
        $this->assertFalse(OrderModel::cancelOrder($orderId, $userId));
        $this->assertSame(10, $this->variantStock($variantId));
    }

    // ════════════════════════════════════════════════════════
    // كاسكيد الحظر
    // ════════════════════════════════════════════════════════

    public function testBlockingAUserCancelsPendingOrdersAndReturnsStockOnce(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 20);

        $first  = $this->placeOrder($userId, $addressId, $productId, $variantId, 3);
        $second = $this->placeOrder($userId, $addressId, $productId, $variantId, 2);
        $this->assertSame(15, $this->variantStock($variantId));

        OrderModel::cancelAllPendingForUser($userId);

        $this->assertSame(20, $this->variantStock($variantId));

        // إلغاء ناعم هنا لا حذف: سجلّ التدقيق يجب أن يحتفظ بأثر ما
        // أُلغي عند الحظر — من أُلغي له وماذا ومتى.
        $stmt = $this->pdo->prepare('SELECT status, stock_restored FROM orders WHERE order_id IN (?, ?)');
        $stmt->execute([$first, $second]);

        foreach ($stmt->fetchAll() as $row) {
            $this->assertSame('cancelled', $row['status']);
            $this->assertSame(1, (int) $row['stock_restored']);
        }

        // وإعادة التشغيل — حظرٌ ثانٍ، أو سكربت الإصلاح — يجب ألّا تُرجع
        // شيئاً مرّة أخرى. هذا هو الغرض الكامل من عمود stock_restored.
        OrderModel::cancelAllPendingForUser($userId);
        $this->assertSame(20, $this->variantStock($variantId));
    }
}
