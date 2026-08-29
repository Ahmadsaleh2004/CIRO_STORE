<?php

namespace Tests\Integration;

use App\Models\OrderModel;
use App\Models\UserModel;
use Tests\Support\DatabaseTestCase;

/**
 * الإنذارات والحظر وحذف المستخدم.
 *
 * ══════════════════════════════════════════════════════════════
 * لماذا
 * ══════════════════════════════════════════════════════════════
 *
 * ثلاثة إنذارات تعني حظراً، والحظر يُلغي الطلبات المعلّقة ويُرجع
 * مخزونها. أي أن هذا المسار **يكتب في عدّاد المخزون** — نفس العدّاد
 * الذي يكتب فيه الطلب والإلغاء — لكن من طرف ثالث بعيد عن الاثنين.
 *
 * وهو أيضاً مسار يصعب اختباره يدوياً: يتطلّب مستخدماً بطلبات معلّقة،
 * وثلاثة إنذارات، ومراقبة عمود في جدول آخر. فبقاؤه بلا اختبار يعني
 * عملياً أنه لا يُجرَّب إلا في الإنتاج.
 *
 * والمشروع يحمل دليلاً على ذلك: سكربت
 * `scripts/backfill_blocked_users_cancel_orders.php` وُجد لإصلاح
 * «طلبات معلّقة لمستخدمين حُظروا **قبل** تفعيل الإلغاء التلقائي» — أي
 * أن هذه العلاقة انكسرت مرّة فعلاً وتُرك أثرها في القاعدة.
 */
final class UserModerationTest extends DatabaseTestCase
{
    private function makeUser(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (full_name, email) VALUES (?, ?)');
        $stmt->execute(['Test User', 'user' . uniqid() . '@example.com']);
        return (int) $this->pdo->lastInsertId();
    }

    private function makeAdmin(string $role = 'A'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admins (full_name, email, password, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            'Test Admin',
            'admin' . uniqid() . '@example.com',
            password_hash('secret123', PASSWORD_BCRYPT),
            $role,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function makeAddress(int $userId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_addresses (user_id, full_address) VALUES (?, ?)');
        $stmt->execute([$userId, '1 Test Street']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} [productId, variantId] */
    private function makeProductWithVariant(int $stock = 10): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, stock_quantity, is_visible) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute(['Test Product', 100.00, $stock]);
        $productId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, stock_quantity) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$productId, 'Black', 100.00, $stock]);

        return [$productId, (int) $this->pdo->lastInsertId()];
    }

    private function placeOrder(int $userId, int $addressId, int $productId, int $variantId, int $qty): int
    {
        $result = OrderModel::placeOrder($userId, $addressId, [[
            'product_id'  => $productId,
            'variant_id'  => $variantId,
            'qty'         => $qty,
            'shown_price' => 100.00,
        ]], 'cash_on_delivery', bin2hex(random_bytes(8)));

        $this->assertSame(OrderModel::PLACE_OK, $result['status']);
        return $result['order_id'];
    }

    private function variantStock(int $variantId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ?');
        $stmt->execute([$variantId]);
        return (int) $stmt->fetchColumn();
    }

    // ════════════════════════════════════════════════════════
    // الإنذارات
    // ════════════════════════════════════════════════════════

    public function testStrikesAccumulateAndAreCountedPerUser(): void
    {
        $adminId = $this->makeAdmin();
        $first   = $this->makeUser();
        $second  = $this->makeUser();

        UserModel::addStrike($first, $adminId, 'سلوك مسيء');
        UserModel::addStrike($first, $adminId, 'طلب وهمي');
        UserModel::addStrike($second, $adminId, 'سبب آخر');

        $this->assertSame(2, UserModel::getStrikesCount($first));
        $this->assertSame(1, UserModel::getStrikesCount($second));
    }

    public function testRemovingAStrikeRequiresItToBelongToThatUser(): void
    {
        $adminId = $this->makeAdmin();
        $owner   = $this->makeUser();
        $other   = $this->makeUser();

        UserModel::addStrike($owner, $adminId, 'سبب');
        $strikes  = UserModel::getStrikes($owner);
        $strikeId = (int) $strikes[0]['id'];

        // شرط الملكية في نفس عبارة DELETE — لا فحص منفصل يمكن أن
        // يُنسى في مسار آخر. بدونه يستطيع طلبٌ بمعرّف إنذار أن يمسح
        // إنذار أي مستخدم (IDOR).
        $this->assertFalse(UserModel::removeStrike($strikeId, $other));
        $this->assertSame(1, UserModel::getStrikesCount($owner));

        $this->assertTrue(UserModel::removeStrike($strikeId, $owner));
        $this->assertSame(0, UserModel::getStrikesCount($owner));
    }

    public function testRemovingANonexistentStrikeReturnsFalse(): void
    {
        $userId = $this->makeUser();

        // DELETE على معرّف غير موجود **ينجح** في SQL ويحذف صفر صفوف.
        // فبلا فحص rowCount كانت الدالة تُرجع true عن حذف لم يحدث،
        // ويكتب الكنترولر صفَّ تدقيق عن فعل لم يقع.
        $this->assertFalse(UserModel::removeStrike(99999, $userId));
    }

    // ════════════════════════════════════════════════════════
    // الحظر يلغي الطلبات المعلّقة
    // ════════════════════════════════════════════════════════

    public function testTheThirdStrikeCancelsPendingOrdersAndReturnsStock(): void
    {
        $adminId   = $this->makeAdmin();
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(20);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 5);
        $this->assertSame(15, $this->variantStock($variantId));

        UserModel::addStrike($userId, $adminId, 'إنذار أوّل');
        UserModel::addStrike($userId, $adminId, 'إنذار ثانٍ');

        // إنذاران لا يكفيان: الطلب سليم والمخزون محجوز كما هو.
        $this->assertSame(15, $this->variantStock($variantId));

        UserModel::addStrike($userId, $adminId, 'إنذار ثالث');

        // الثالث يحظر، والحظر يُرجع ما كان محجوزاً.
        $this->assertSame(20, $this->variantStock($variantId));

        $stmt = $this->pdo->prepare('SELECT status, stock_restored FROM orders WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        $this->assertSame('cancelled', $order['status']);
        $this->assertSame(1, (int) $order['stock_restored']);
    }

    public function testAFourthStrikeDoesNotReturnTheStockAgain(): void
    {
        $adminId   = $this->makeAdmin();
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(20);

        $this->placeOrder($userId, $addressId, $productId, $variantId, 5);

        for ($i = 0; $i < 3; $i++) {
            UserModel::addStrike($userId, $adminId, "إنذار {$i}");
        }
        $this->assertSame(20, $this->variantStock($variantId));

        // الشرط `=== 3` يعني أن الرابع لا يستدعي الإلغاء أصلاً. وحتى
        // لو استدعاه، stock_restored يمنع الإرجاع المضاعف. الطبقتان
        // مقصودتان: الأولى تمنع العمل، والثانية تمنع أثره لو وقع.
        UserModel::addStrike($userId, $adminId, 'إنذار رابع');

        $this->assertSame(20, $this->variantStock($variantId), 'أُرجع المخزون مرّتين.');
    }

    public function testAlreadyDeliveredOrdersAreNotTouchedByABlock(): void
    {
        $adminId   = $this->makeAdmin();
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(20);

        $delivered = $this->placeOrder($userId, $addressId, $productId, $variantId, 4);

        $stmt = $this->pdo->prepare("UPDATE orders SET status = 'completed' WHERE order_id = ?");
        $stmt->execute([$delivered]);

        for ($i = 0; $i < 3; $i++) {
            UserModel::addStrike($userId, $adminId, "إنذار {$i}");
        }

        // طلبٌ سُلّم فعلاً: البضاعة عند الزبون. إرجاع مخزونه يعني
        // زيادة وهمية في المستودع تُباع مرّة ثانية.
        $this->assertSame(16, $this->variantStock($variantId));

        $stmt = $this->pdo->prepare('SELECT status FROM orders WHERE order_id = ?');
        $stmt->execute([$delivered]);
        $this->assertSame('completed', $stmt->fetchColumn());
    }

    // ════════════════════════════════════════════════════════
    // حذف المستخدم
    // ════════════════════════════════════════════════════════

    public function testDeletingAUserRemovesTheirOrdersAndReportsWhatWentWithThem(): void
    {
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(30);

        $this->placeOrder($userId, $addressId, $productId, $variantId, 2);
        $completed = $this->placeOrder($userId, $addressId, $productId, $variantId, 3);

        $stmt = $this->pdo->prepare("UPDATE orders SET status = 'completed' WHERE order_id = ?");
        $stmt->execute([$completed]);

        $result = UserModel::deleteUser($userId);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['ordersDeletedCount']);

        // التقرير التفصيلي ليس زينة: هو ما يُكتب في سجلّ التدقيق.
        // «حُذف مستخدم» جملة لا تكفي حين يُسأل لاحقاً عمّا ضاع معه.
        $this->assertSame(1, $result['ordersByStatus']['not_taken']);
        $this->assertSame(1, $result['ordersByStatus']['completed']);

        $this->assertSame(0, $this->countRows('users'));
        $this->assertSame(0, $this->countRows('orders'));
        $this->assertSame(0, $this->countRows('order_items'));
        $this->assertSame(0, $this->countRows('user_addresses'));
    }
}
