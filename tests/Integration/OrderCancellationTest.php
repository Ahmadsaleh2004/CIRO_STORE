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

    // ════════════════════════════════════════════════════════
    // انتقالات حالة الطلب من لوحة التحكّم
    // ════════════════════════════════════════════════════════

    private function makeAdmin(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admins (full_name, email, password, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            'Test Admin',
            'admin' . uniqid() . '@example.com',
            password_hash('secret123', PASSWORD_BCRYPT),
            'A',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function orderStatus(int $orderId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM orders WHERE order_id = ?');
        $stmt->execute([$orderId]);

        return (string) $stmt->fetchColumn();
    }

    public function testOnlyOneAdminCanTakeAnOrder(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 1);

        $first  = $this->makeAdmin();
        $second = $this->makeAdmin();

        $this->assertTrue(OrderModel::adminTakeOrder($orderId, $first)['success']);

        // الثاني يُرفض: الحالة لم تعد not_taken. وقبل إضافة المعاملة
        // و`FOR UPDATE` كان الفحص والكتابة عبارتين منفصلتين، فيمرّ
        // الاثنان معاً وتفوز الكتابة الأخيرة — فيظنّ الأوّل أنه يحمل
        // الطلب بينما يحمله الثاني.
        $result = OrderModel::adminTakeOrder($orderId, $second);
        $this->assertFalse($result['success']);

        $stmt = $this->pdo->prepare('SELECT taken_by_admin_id FROM orders WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $this->assertSame($first, (int) $stmt->fetchColumn());
    }

    public function testACancelledOrderCannotBeMarkedDelivered(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 2);
        $adminId = $this->makeAdmin();

        $this->setStatus($orderId, 'cancelled');

        // ⚠️ لم تكن adminMarkDelivered تفحص الحالة إطلاقاً: تقرأ
        // user_id وحده ثم تكتب 'completed' على أي طلب. فطلبٌ ملغى —
        // وقد أُعيد مخزونه إلى المستودع — كان يُقلَب إلى «مكتمل» بطلب
        // واحد إلى /admin/orders/mark-delivered، فيدخل تقارير المبيعات
        // بلا بضاعة خرجت.
        //
        // والواجهة لا تعرض الزرّ إلا على طلب taken، لكن الحراسة في
        // الواجهة ليست حراسة — النقطة تقبل طلباً مباشراً.
        $result = OrderModel::adminMarkDelivered($orderId, $adminId);

        $this->assertFalse($result['success']);
        $this->assertSame('cancelled', $this->orderStatus($orderId));
    }

    public function testAnUntakenOrderCannotBeMarkedDelivered(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 1);
        $adminId = $this->makeAdmin();

        // التسليم يفترض أن أحداً أخذ الطلب وجهّزه. القفز فوق ذلك يُنتج
        // طلباً «مكتملاً» لا يحمل اسم من نفّذه.
        $this->assertFalse(OrderModel::adminMarkDelivered($orderId, $adminId)['success']);
        $this->assertSame('not_taken', $this->orderStatus($orderId));
    }

    public function testATakenOrderIsMarkedDeliveredNormally(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 1);
        $adminId = $this->makeAdmin();

        // المسار السليم يبقى سليماً: الحارس يمنع ما يجب منعه وحده.
        $this->assertTrue(OrderModel::adminTakeOrder($orderId, $adminId)['success']);
        $this->assertTrue(OrderModel::adminMarkDelivered($orderId, $adminId)['success']);
        $this->assertSame('completed', $this->orderStatus($orderId));
    }
}
