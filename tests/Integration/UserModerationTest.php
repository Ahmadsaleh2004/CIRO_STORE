<?php

namespace Tests\Integration;

use App\Models\OrderModel;
use App\Models\UserModel;
use Tests\Support\DatabaseTestCase;

/**
 * Strikes, blocking and deleting a user.
 *
 * ══════════════════════════════════════════════════════════════
 * Why
 * ══════════════════════════════════════════════════════════════
 *
 * Three strikes mean a block, and a block cancels the pending orders and returns their
 * stock. Which is to say this path **writes to the stock counter** — the same counter that
 * ordering and cancelling write to — but from a third party far from both.
 *
 * And it is also a path that is hard to test by hand: it needs a user with pending orders,
 * three strikes, and a column in another table watched. So leaving it untested means, in
 * practice, that it is only ever exercised in production.
 *
 * And the project carries evidence of that: the
 * `scripts/backfill_blocked_users_cancel_orders.php` script exists to repair "pending orders
 * of users blocked **before** auto-cancel was switched on" — which is to say this
 * relationship really did break once and left its mark in the database.
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
    // The strikes
    // ════════════════════════════════════════════════════════

    public function testStrikesAccumulateAndAreCountedPerUser(): void
    {
        $adminId = $this->makeAdmin();
        $first   = $this->makeUser();
        $second  = $this->makeUser();

        UserModel::addStrike($first, $adminId, 'Abusive behaviour');
        UserModel::addStrike($first, $adminId, 'A fake order');
        UserModel::addStrike($second, $adminId, 'Another reason');

        $this->assertSame(2, UserModel::getStrikesCount($first));
        $this->assertSame(1, UserModel::getStrikesCount($second));
    }

    public function testRemovingAStrikeRequiresItToBelongToThatUser(): void
    {
        $adminId = $this->makeAdmin();
        $owner   = $this->makeUser();
        $other   = $this->makeUser();

        UserModel::addStrike($owner, $adminId, 'A reason');
        $strikes  = UserModel::getStrikes($owner);
        $strikeId = (int) $strikes[0]['id'];

        // The ownership condition is in the DELETE statement itself — not a separate check
        // that could be forgotten on another path. Without it, a request carrying a strike id
        // can erase any user's strike (IDOR).
        $this->assertFalse(UserModel::removeStrike($strikeId, $other));
        $this->assertSame(1, UserModel::getStrikesCount($owner));

        $this->assertTrue(UserModel::removeStrike($strikeId, $owner));
        $this->assertSame(0, UserModel::getStrikesCount($owner));
    }

    public function testRemovingANonexistentStrikeReturnsFalse(): void
    {
        $userId = $this->makeUser();

        // A DELETE on a non-existent id **succeeds** in SQL and deletes zero rows. So
        // without a rowCount check the function returned true for a deletion that never
        // happened, and the controller wrote an audit row for an act that never occurred.
        $this->assertFalse(UserModel::removeStrike(99999, $userId));
    }

    // ════════════════════════════════════════════════════════
    // A block cancels the pending orders
    // ════════════════════════════════════════════════════════

    public function testTheThirdStrikeCancelsPendingOrdersAndReturnsStock(): void
    {
        $adminId   = $this->makeAdmin();
        $userId    = $this->makeUser();
        $addressId = $this->makeAddress($userId);
        [$productId, $variantId] = $this->makeProductWithVariant(20);

        $orderId = $this->placeOrder($userId, $addressId, $productId, $variantId, 5);
        $this->assertSame(15, $this->variantStock($variantId));

        UserModel::addStrike($userId, $adminId, 'First strike');
        UserModel::addStrike($userId, $adminId, 'Second strike');

        // Two strikes are not enough: the order stands and the stock stays reserved.
        $this->assertSame(15, $this->variantStock($variantId));

        UserModel::addStrike($userId, $adminId, 'Third strike');

        // The third blocks, and the block returns what was reserved.
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
            UserModel::addStrike($userId, $adminId, "Strike {$i}");
        }
        $this->assertSame(20, $this->variantStock($variantId));

        // The `=== 3` condition means the fourth does not invoke the cancellation at all. And
        // even if it did, stock_restored prevents the double return. The two layers are
        // deliberate: the first prevents the work, the second prevents its effect should it
        // happen.
        UserModel::addStrike($userId, $adminId, 'Fourth strike');

        $this->assertSame(20, $this->variantStock($variantId), 'The stock was returned twice.');
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
            UserModel::addStrike($userId, $adminId, "Strike {$i}");
        }

        // An order that was actually delivered: the goods are with the customer. Returning
        // its stock means a phantom increase in the warehouse that gets sold a second time.
        $this->assertSame(16, $this->variantStock($variantId));

        $stmt = $this->pdo->prepare('SELECT status FROM orders WHERE order_id = ?');
        $stmt->execute([$delivered]);
        $this->assertSame('completed', $stmt->fetchColumn());
    }

    // ════════════════════════════════════════════════════════
    // Deleting a user
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

        // The detailed report is not decoration: it is what gets written into the audit log.
        // "A user was deleted" is not enough when somebody later asks what went with them.
        $this->assertSame(1, $result['ordersByStatus']['not_taken']);
        $this->assertSame(1, $result['ordersByStatus']['completed']);

        $this->assertSame(0, $this->countRows('users'));
        $this->assertSame(0, $this->countRows('orders'));
        $this->assertSame(0, $this->countRows('order_items'));
        $this->assertSame(0, $this->countRows('user_addresses'));
    }
}
