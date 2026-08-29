<?php

namespace Tests\Integration;

use App\Models\OrderModel;
use Tests\Support\DatabaseTestCase;

/**
 * OrderModel::placeOrder — التسعير يأتي من القاعدة، لا من العميل.
 *
 * ══════════════════════════════════════════════════════════════
 * العطل الأصلي
 * ══════════════════════════════════════════════════════════════
 *
 * السلّة تُبنى في localStorage ويرسلها المتصفح كما هي، وكانت الدالة
 * تقرأ `price` منها فتجمعه في `total_amount` وتكتبه في
 * `price_at_purchase`. أي أن **السعر كان مُدخَلاً من المستخدم**: طلبٌ
 * بـ`price: 0.01` يمرّ بتوكن صحيح وجلسة صحيحة، ويُخفّض المخزون، ويصل
 * الأدمن كطلب مشروع.
 *
 * ولم يمسكه شيء لأن هذا المسار — أغلى مسار في المتجر — لم يكن مغطّى
 * بأي اختبار. هذا الملف هو الغطاء.
 *
 * ══════════════════════════════════════════════════════════════
 * لماذا الرفض لا التمرير بسعر الخادم
 * ══════════════════════════════════════════════════════════════
 *
 * قرار منتج صريح: الزبون لا يُفاجأ بمبلغ لم يوافق عليه. والدفع عند
 * الاستلام ينقل المفاجأة إلى الباب — حيث تكلفتها رفض استلام وشحنة
 * راجعة. فالسعر المختلف يُلغي العملية ويُعيد الأسعار الصحيحة.
 */
final class OrderPricingTest extends DatabaseTestCase
{
    private function makeUser(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email) VALUES (?, ?)'
        );
        $stmt->execute(['Test Buyer', 'buyer' . uniqid() . '@example.com']);
        return (int) $this->pdo->lastInsertId();
    }

    private function makeAddress(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_addresses (user_id, full_address) VALUES (?, ?)'
        );
        $stmt->execute([$userId, '1 Test Street']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} [productId, variantId] */
    private function makeProductWithVariant(
        float $price = 100.00,
        float $discount = 0.00,
        int $stock = 5,
        int $visible = 1
    ): array {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, discount_percentage, stock_quantity, is_visible)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Test Product', $price, $discount, $stock, $visible]);
        $productId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, discount_percentage, stock_quantity)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$productId, 'Black', $price, $discount, $stock]);

        return [$productId, (int) $this->pdo->lastInsertId()];
    }

    /**
     * @param  list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function place(int $userId, int $addressId, array $items, string $key = ''): array
    {
        return OrderModel::placeOrder(
            $userId,
            $addressId,
            $items,
            'cash_on_delivery',
            $key !== '' ? $key : bin2hex(random_bytes(8))
        );
    }

    private function variantStock(int $variantId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ?');
        $stmt->execute([$variantId]);
        return (int) $stmt->fetchColumn();
    }

    // ════════════════════════════════════════════════════════
    // المسار السليم
    // ════════════════════════════════════════════════════════

    public function testAnHonestOrderIsPricedFromTheDatabase(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 10.00, 5);

        // price_after_discount عمود محسوب: 100 − 10% = 90.00
        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 2,
            'shown_price' => 90.00,
        ]]);

        $this->assertSame(OrderModel::PLACE_OK, $result['status']);

        $stmt = $this->pdo->prepare('SELECT total_amount FROM orders WHERE order_id = ?');
        $stmt->execute([$result['order_id']]);
        $this->assertSame('180.00', $stmt->fetchColumn());

        $stmt = $this->pdo->prepare(
            'SELECT price_at_purchase, color_name_snapshot FROM order_items WHERE order_id = ?'
        );
        $stmt->execute([$result['order_id']]);
        $row = $stmt->fetch();

        $this->assertSame('90.00', $row['price_at_purchase']);

        // اللقطة من القاعدة لا من العميل: لقطةٌ يكتبها الطرف الذي
        // تُوثَّق ضدّه ليست لقطة.
        $this->assertSame('Black', $row['color_name_snapshot']);

        $this->assertSame(3, $this->variantStock($variantId));
    }

    // ════════════════════════════════════════════════════════
    // الثغرة نفسها
    // ════════════════════════════════════════════════════════

    public function testAForgedPriceIsRejectedAndNothingIsWritten(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 0.00, 5);

        // «اشترِ منتجاً بمئة دولار بمليم» — الطلب الذي كان يمرّ.
        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 1,
            'shown_price' => 0.01,
        ]]);

        $this->assertSame(OrderModel::PLACE_PRICE_CHANGED, $result['status']);
        $this->assertSame(0, $this->countRows('orders'));
        $this->assertSame(0, $this->countRows('order_items'));

        // ولا سطر مخزون تحرّك — التراجع كامل لا جزئي.
        $this->assertSame(5, $this->variantStock($variantId));

        // والردّ يحمل السعر الصحيح ليصحّح العميل سلّته.
        $this->assertSame(100.00, $result['items'][0]['price']);
        $this->assertSame(0.01, $result['items'][0]['shown_price']);
    }

    public function testEveryDriftedPriceIsReportedNotJustTheFirst(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$p1, $v1] = $this->makeProductWithVariant(100.00, 0.00, 5);
        [$p2, $v2] = $this->makeProductWithVariant(50.00, 0.00, 5);

        $result = $this->place($userId, $addressId, [
            ['product_id' => $p1, 'variant_id' => $v1, 'qty' => 1, 'shown_price' => 10.00],
            ['product_id' => $p2, 'variant_id' => $v2, 'qty' => 1, 'shown_price' => 5.00],
        ]);

        $this->assertSame(OrderModel::PLACE_PRICE_CHANGED, $result['status']);

        // زبونٌ بسعرين تغيّرا يستحقّ أن يراهما مرّة واحدة، لا أن يعيد
        // المحاولة مرّتين ليكتشفهما واحداً واحداً.
        $this->assertCount(2, $result['items']);
    }

    public function testCentPrecisionDoesNotRejectAnHonestOrder(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);

        // 19.99 − 15% = 16.9915 → يخزّنها العمود المحسوب 16.99
        [$productId, $variantId] = $this->makeProductWithVariant(19.99, 15.00, 5);

        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 3,
            'shown_price' => 16.99,
        ]]);

        // المقارنة بالقروش لا بالعشريات: 0.1 + 0.2 !== 0.3 في أي حساب
        // ثنائي، ومقارنة float كانت سترفض هذا الطلب السليم.
        $this->assertSame(OrderModel::PLACE_OK, $result['status']);
    }

    // ════════════════════════════════════════════════════════
    // ما لم يعد قابلاً للشراء
    // ════════════════════════════════════════════════════════

    public function testAHiddenProductCannotBeOrdered(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 0.00, 5, visible: 0);

        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 1,
            'shown_price' => 100.00,
        ]]);

        // لم يكن أي شيء في مسار الطلب يفحص is_visible. الاستعلام القافل
        // يحمله، فأُغلق الباب مع إغلاق باب السعر.
        $this->assertSame(OrderModel::PLACE_UNAVAILABLE, $result['status']);
        $this->assertSame(0, $this->countRows('orders'));
    }

    public function testAVariantBelongingToAnotherProductIsRejected(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$cheapProduct, ]           = $this->makeProductWithVariant(5.00, 0.00, 5);
        [, $expensiveVariant]       = $this->makeProductWithVariant(500.00, 0.00, 5);

        // قرن variant غالٍ بمنتج رخيص: بلا فحص الانتماء يُخزَّن سطر
        // order_items بمنتجٍ لم يُسعَّر سعرُه.
        $result = $this->place($userId, $addressId, [[
            'product_id'  => $cheapProduct,
            'variant_id'  => $expensiveVariant,
            'qty'         => 1,
            'shown_price' => 500.00,
        ]]);

        $this->assertSame(OrderModel::PLACE_UNAVAILABLE, $result['status']);
        $this->assertSame(0, $this->countRows('orders'));
    }

    // ════════════════════════════════════════════════════════
    // لقطة العنوان
    // ════════════════════════════════════════════════════════

    public function testTheOrderKeepsItsOwnCopyOfTheAddress(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 0.00, 5);

        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 1,
            'shown_price' => 100.00,
        ]]);
        $this->assertSame(OrderModel::PLACE_OK, $result['status']);

        // المستخدم يعدّل عنوانه بعد الطلب — وهو تصرّف عادي تماماً.
        $stmt = $this->pdo->prepare('UPDATE user_addresses SET full_address = ? WHERE id = ?');
        $stmt->execute(['عنوان جديد تماماً', $addressId]);

        $stmt = $this->pdo->prepare('SELECT address_snapshot FROM orders WHERE order_id = ?');
        $stmt->execute([$result['order_id']]);
        $snapshot = (string) $stmt->fetchColumn();

        // بلا اللقطة كان السجلّ يقول إن الشحنة ذهبت إلى مكان لم تذهب
        // إليه قط — بأثر رجعي، وبلا أي أثر للتغيير.
        $this->assertStringContainsString('1 Test Street', $snapshot);
        $this->assertStringNotContainsString('عنوان جديد', $snapshot);
    }

    public function testDeletingTheAddressDoesNotEraseItFromPastOrders(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 0.00, 5);

        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 1,
            'shown_price' => 100.00,
        ]]);

        $stmt = $this->pdo->prepare('DELETE FROM user_addresses WHERE id = ?');
        $stmt->execute([$addressId]);

        $stmt = $this->pdo->prepare(
            'SELECT address_id, address_snapshot FROM orders WHERE order_id = ?'
        );
        $stmt->execute([$result['order_id']]);
        $order = $stmt->fetch();

        // المفتاح يصير NULL بحكم ON DELETE SET NULL — وهذا هو السلوك
        // القديم كاملاً: طلب مكتمل يفقد عنوانه نهائياً ولا نسخة في أي
        // مكان. اللقطة هي ما ينجو.
        $this->assertNull($order['address_id']);
        $this->assertStringContainsString('1 Test Street', (string) $order['address_snapshot']);
    }

    public function testAnItemWithoutAVariantIsRejected(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, ] = $this->makeProductWithVariant(100.00, 0.00, 5);

        $result = $this->place($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => null,
            'qty'         => 1,
            'shown_price' => 100.00,
        ]]);

        // العطل الذي يغلقه هذا الاختبار: تخفيض المخزون كان داخل
        // `if ($variantId)`، فعنصرٌ بلا variant كان يُسجَّل في
        // order_items ويُباع **بلا أن يمسّ أي عدّاد مخزون** — مبيعات
        // بلا حدّ لمنتج نفد.
        //
        // والرفض لا التخفيض من products: المتجر يفرض variant لكل منتج
        // في موضعين، فمصدر حقيقة ثانٍ للمخزون ثمنٌ أغلى من رفض حالة
        // لا تصل من واجهته أصلاً.
        $this->assertSame(OrderModel::PLACE_UNAVAILABLE, $result['status']);
        $this->assertSame(0, $this->countRows('orders'));
        $this->assertSame(0, $this->countRows('order_items'));
    }

    // ════════════════════════════════════════════════════════
    // المخزون والتكرار
    // ════════════════════════════════════════════════════════

    public function testInsufficientStockRollsBackTheWholeOrder(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$p1, $v1] = $this->makeProductWithVariant(100.00, 0.00, 10);
        [$p2, $v2] = $this->makeProductWithVariant(100.00, 0.00, 1);

        $result = $this->place($userId, $addressId, [
            ['product_id' => $p1, 'variant_id' => $v1, 'qty' => 2, 'shown_price' => 100.00],
            ['product_id' => $p2, 'variant_id' => $v2, 'qty' => 5, 'shown_price' => 100.00],
        ]);

        $this->assertSame(OrderModel::PLACE_OUT_OF_STOCK, $result['status']);
        $this->assertSame(0, $this->countRows('orders'));

        // العنصر الأوّل نجح مخزونه قبل أن يفشل الثاني — والتراجع يجب
        // أن يُعيده. هذا ما يجعل المعاملة معاملةً لا سلسلة تحديثات.
        $this->assertSame(10, $this->variantStock($v1));
        $this->assertSame(1, $this->variantStock($v2));
    }

    public function testTheSameIdempotencyKeyReturnsTheSameOrder(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(100.00, 0.00, 10);

        $item = [
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => 1,
            'shown_price' => 100.00,
        ];

        $first  = $this->place($userId, $addressId, [$item], 'fixed-key-123');
        $second = $this->place($userId, $addressId, [$item], 'fixed-key-123');

        $this->assertSame(OrderModel::PLACE_OK, $first['status']);
        $this->assertSame(OrderModel::PLACE_OK, $second['status']);
        $this->assertSame($first['order_id'], $second['order_id']);
        $this->assertTrue($second['duplicate']);

        $this->assertSame(1, $this->countRows('orders'));

        // والأهمّ: النقرة المكرّرة لا تخصم المخزون مرّتين.
        $this->assertSame(9, $this->variantStock($variantId));
    }
}
