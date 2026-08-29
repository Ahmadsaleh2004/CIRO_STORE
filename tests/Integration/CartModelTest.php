<?php

namespace Tests\Integration;

use App\Models\CartModel;
use Tests\Support\DatabaseTestCase;

/**
 * سلّة المستخدم على الخادم.
 *
 * ══════════════════════════════════════════════════════════════
 * ما يحرسه هذا الملف
 * ══════════════════════════════════════════════════════════════
 *
 * السلّة انتقلت من `localStorage` إلى جدول. وما كان قبلها خطأ عرضٍ في
 * متصفّح واحد صار خطأ بيانات مشتركاً: كميّة تتضاعف، أو سطر يظهر
 * مرّتين، أو سلّةٌ تُقرأ لغير صاحبها.
 *
 * وثلاث خصائص تحديداً لا يظهر كسرُها في الاستعمال العادي:
 *
 *   · الإضافة المكرّرة تُحدّث سطراً لا تُنشئ ثانياً — والمفتاح الفريد
 *     يفرضها، لكن `ON DUPLICATE KEY` هي ما يجعلها تنجح بدل أن ترمي.
 *   · تعديل سلّة مستخدم آخر مرفوض — شرط الملكية في نفس العبارة.
 *   · والسعر يُقرأ من القاعدة عند كل قراءة، لا يُخزَّن — فتغيّر السعر
 *     يظهر في السلّة قبل الدفع لا عنده.
 */
final class CartModelTest extends DatabaseTestCase
{
    private function makeUser(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (full_name, email) VALUES (?, ?)');
        $stmt->execute(['Cart User', 'cart' . uniqid() . '@example.com']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} [productId, variantId] */
    private function makeProduct(float $price = 100.00, float $discount = 0.00, int $stock = 10, int $visible = 1): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, discount_percentage, stock_quantity, is_visible)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['Cart Product', $price, $discount, $stock, $visible]);
        $productId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, discount_percentage, stock_quantity)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$productId, 'Black', $price, $discount, $stock]);

        return [$productId, (int) $this->pdo->lastInsertId()];
    }

    // ════════════════════════════════════════════════════════
    // الإضافة
    // ════════════════════════════════════════════════════════

    public function testAddingCreatesOneLineWithLiveProductData(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct(100.00, 10.00, 10);

        $this->assertTrue(CartModel::add($userId, $productId, $variantId, 2));

        $items = CartModel::getForUser($userId);
        $this->assertCount(1, $items);

        // السعر والاسم والمخزون من القاعدة لا من المُدخَل: 100 − 10% = 90
        $this->assertSame(90.00, $items[0]['price']);
        $this->assertSame('Cart Product', $items[0]['name']);
        $this->assertSame('Black', $items[0]['color_name']);
        $this->assertSame(2, $items[0]['quantity']);
        $this->assertSame(10, $items[0]['stock']);
    }

    public function testAddingTheSameVariantTwiceSumsIntoOneLine(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($userId, $productId, $variantId, 2);
        CartModel::add($userId, $productId, $variantId, 3);

        $items = CartModel::getForUser($userId);

        // سطران لنفس اللون يعنيان سلّةً تعرض المنتج مرّتين ويُطلَب
        // مرّتين. المفتاح الفريد يمنع ذلك، وON DUPLICATE KEY يجعل
        // المنع نجاحاً لا خطأً.
        $this->assertCount(1, $items);
        $this->assertSame(5, $items[0]['quantity']);
        $this->assertSame(1, $this->countRows('cart_items'));
    }

    public function testTwoVariantsOfOneProductAreSeparateLines(): void
    {
        $userId = $this->makeUser();
        [$productId, $firstVariant] = $this->makeProduct();

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, stock_quantity) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$productId, 'White', 100.00, 5]);
        $secondVariant = (int) $this->pdo->lastInsertId();

        CartModel::add($userId, $productId, $firstVariant, 1);
        CartModel::add($userId, $productId, $secondVariant, 1);

        // نفس المنتج بلونين شيئان مختلفان في السلّة — وهو ما تعرضه
        // الواجهة فعلاً. ولذلك المفتاح الفريد على variant لا على product.
        $this->assertCount(2, CartModel::getForUser($userId));
    }

    public function testTheQuantityCeilingIsEnforced(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        $this->assertFalse(CartModel::add($userId, $productId, $variantId, CartModel::MAX_QTY + 1));
        $this->assertFalse(CartModel::add($userId, $productId, $variantId, 0));
        $this->assertSame(0, $this->countRows('cart_items'));

        // والجمع لا يتجاوز السقف أيضاً — LEAST داخل SQL، فلا قراءة قبل
        // الكتابة ولا سباق بين تبويبين.
        CartModel::add($userId, $productId, $variantId, CartModel::MAX_QTY);
        CartModel::add($userId, $productId, $variantId, 10);

        $this->assertSame(CartModel::MAX_QTY, CartModel::getForUser($userId)[0]['quantity']);
    }

    // ════════════════════════════════════════════════════════
    // التعديل والحذف — والملكية
    // ════════════════════════════════════════════════════════

    public function testSettingQuantityToZeroRemovesTheLine(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($userId, $productId, $variantId, 3);
        $this->assertTrue(CartModel::setQuantity($userId, $variantId, 0));

        // سطرٌ بكمية صفر يظهر في السلّة ولا يُطلَب — حالة لا معنى لها
        // تُربك العرض والعدّاد معاً.
        $this->assertSame(0, $this->countRows('cart_items'));
    }

    public function testSettingTheSameQuantityIsStillASuccess(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($userId, $productId, $variantId, 2);

        // MySQL يُرجع rowCount = 0 حين لا تتغيّر القيمة. الاعتماد عليه
        // وحده كان سيُرجع «فشل» عن نجاح — ويُظهر للزبون خطأً لا سبب له.
        $this->assertTrue(CartModel::setQuantity($userId, $variantId, 2));
    }

    public function testAUserCannotTouchAnotherUsersCart(): void
    {
        $owner    = $this->makeUser();
        $stranger = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($owner, $productId, $variantId, 4);

        // شرط user_id في نفس عبارة UPDATE/DELETE — لا فحص منفصل يمكن
        // أن يُنسى في مسار آخر (IDOR).
        $this->assertFalse(CartModel::setQuantity($stranger, $variantId, 99));
        $this->assertFalse(CartModel::remove($stranger, $variantId));

        $this->assertSame(4, CartModel::getForUser($owner)[0]['quantity']);
        $this->assertSame([], CartModel::getForUser($stranger));
    }

    public function testClearEmptiesOnlyThatUsersCart(): void
    {
        $first  = $this->makeUser();
        $second = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($first, $productId, $variantId, 1);
        CartModel::add($second, $productId, $variantId, 2);

        CartModel::clear($first);

        $this->assertSame([], CartModel::getForUser($first));
        $this->assertCount(1, CartModel::getForUser($second));
    }

    // ════════════════════════════════════════════════════════
    // ما تعكسه القراءة من القاعدة
    // ════════════════════════════════════════════════════════

    public function testAPriceChangeShowsInTheCartWithoutTouchingIt(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct(100.00, 0.00, 10);

        CartModel::add($userId, $productId, $variantId, 1);
        $this->assertSame(100.00, CartModel::getForUser($userId)[0]['price']);

        $stmt = $this->pdo->prepare('UPDATE product_variants SET price = ? WHERE id = ?');
        $stmt->execute([75.00, $variantId]);

        // لا عمود سعر في cart_items عمداً: القيمة المخزَّنة خارج مصدرها
        // تصير مصدرَ حقيقة ثانياً ثم يقرأها أحدهم يوماً. والقراءة الحيّة
        // تجعل تغيّر السعر ظاهراً في السلّة قبل الدفع لا عنده.
        $this->assertSame(75.00, CartModel::getForUser($userId)[0]['price']);
    }

    public function testAHiddenProductDropsOutOfTheCart(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($userId, $productId, $variantId, 1);
        $this->assertCount(1, CartModel::getForUser($userId));

        $stmt = $this->pdo->prepare('UPDATE products SET is_visible = 0 WHERE id = ?');
        $stmt->execute([$productId]);

        // يختفي من السلّة بدل أن يصل الدفع فيُرفض هناك — الرفض المتأخّر
        // في آخر خطوة أسوأ من اختفاء مبكّر.
        $this->assertSame([], CartModel::getForUser($userId));
    }

    public function testDeletingAVariantRemovesItsCartLines(): void
    {
        $userId = $this->makeUser();
        [$productId, $variantId] = $this->makeProduct();

        CartModel::add($userId, $productId, $variantId, 1);

        $stmt = $this->pdo->prepare('DELETE FROM product_variants WHERE id = ?');
        $stmt->execute([$variantId]);

        // سلّة تشير إلى variant محذوف ليست بياناً بل عطلاً ينتظر من
        // يقرأه. ON DELETE CASCADE يمنع وجوده أصلاً.
        $this->assertSame(0, $this->countRows('cart_items'));
    }

    public function testTheCounterSumsQuantitiesNotLines(): void
    {
        $userId = $this->makeUser();
        [$p1, $v1] = $this->makeProduct();
        [$p2, $v2] = $this->makeProduct();

        CartModel::add($userId, $p1, $v1, 3);
        CartModel::add($userId, $p2, $v2, 4);

        // الشارة تقول «كم قطعة» لا «كم لوناً».
        $this->assertSame(7, CartModel::countItems($userId));
    }
}
