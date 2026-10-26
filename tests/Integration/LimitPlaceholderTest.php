<?php

namespace Tests\Integration;

use App\Models\AdminModel;
use App\Models\AdminNotificationModel;
use App\Models\AdminProductModel;
use App\Models\BrandingModel;
use App\Models\NotificationModel;
use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\UserModel;
use Tests\Support\DatabaseTestCase;

/**
 * Every query with a `LIMIT ?` or `OFFSET ?` still returns rows under emulated prepares.
 *
 * ══════════════════════════════════════════════════════════════
 * Why each one is called with data
 * ══════════════════════════════════════════════════════════════
 *
 * The connection emulates prepares, to halve the round trips to the database (see
 * app/Core/Database.php). Under emulation `execute([...])` sends every value as a string, and
 * `LIMIT '9'` is a syntax error — so each of these queries binds its limit and offset as
 * integers, through Model::executeTyped or bindValue(…, PDO::PARAM_INT).
 *
 * And a regression here would be silent. Every one of these methods catches its exception,
 * logs it and returns an empty list, so a test that only checks "no error" passes on a broken
 * query, and so does a page: no bell notifications, an empty orders panel, "no products".
 * None of the twelve was called by any test when the switch was made. So each is given a row
 * that must come back, and an empty result is the failure.
 */
final class LimitPlaceholderTest extends DatabaseTestCase
{
    private function makeUser(string $name = 'Limit User'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (full_name, email) VALUES (?, ?)');
        $stmt->execute([$name, 'limit' . uniqid() . '@example.com']);

        return (int) $this->pdo->lastInsertId();
    }

    private function makeAdmin(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admins (full_name, email, password, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['Limit Admin', 'admin' . uniqid() . '@example.com', password_hash('secret123', PASSWORD_BCRYPT), 'A']);

        return (int) $this->pdo->lastInsertId();
    }

    private function makeProduct(string $name = 'Limit Product'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, discount_percentage, stock_quantity, is_visible) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, 100.00, 0.00, 5, 1]);

        return (int) $this->pdo->lastInsertId();
    }

    private function audit(int $adminId, string $targetType, int $targetId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_audit_log (admin_id, action, target_type, target_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$adminId, 'test_action', $targetType, $targetId]);
    }

    // ════════════════════════════════════════════════════════
    // The store
    // ════════════════════════════════════════════════════════

    public function testTheVisibleProductsPage(): void
    {
        $this->makeProduct('First');
        $this->makeProduct('Second');

        $this->assertCount(1, ProductModel::findVisiblePaginated(1, 0));
        $this->assertCount(1, ProductModel::findVisiblePaginated(1, 1));
    }

    public function testAUsersNotifications(): void
    {
        $userId = $this->makeUser();
        $this->assertTrue(NotificationModel::insert($userId, 'Hello', 'A message'));
        $this->assertTrue(NotificationModel::insert($userId, 'Again', 'Another'));

        $this->assertCount(1, NotificationModel::getList($userId, 1));
        $this->assertCount(2, NotificationModel::getList($userId));
    }

    // ════════════════════════════════════════════════════════
    // The control panel
    // ════════════════════════════════════════════════════════

    public function testAnAdminsNotifications(): void
    {
        $adminId = $this->makeAdmin();
        $stmt = $this->pdo->prepare('INSERT INTO admin_notifications (admin_id, title, message) VALUES (?, ?, ?)');
        $stmt->execute([$adminId, 'New order', 'Order #1']);
        $stmt->execute([$adminId, 'New order', 'Order #2']);

        $this->assertCount(1, AdminNotificationModel::getList($adminId, 1));
    }

    public function testTheAuditLogInAllFourShapes(): void
    {
        $adminId = $this->makeAdmin();
        $userId  = $this->makeUser();
        $this->audit($adminId, 'user', $userId);
        $this->audit($adminId, 'product', 7);

        $this->assertCount(2, AdminModel::getAuditLogForAdmin($adminId));
        $this->assertCount(1, AdminModel::getAuditLogForAdmin($adminId, 1));
        $this->assertCount(1, AdminModel::getAuditLogByTypes($adminId, ['product']));
        $this->assertCount(1, AdminModel::getAuditLogExcludingTypes($adminId, ['user']));
        $this->assertCount(1, AdminModel::getAuditLogForUser($userId));
    }

    public function testTheOrdersPanelAndAnAdminsHandledOrders(): void
    {
        $adminId = $this->makeAdmin();
        $userId  = $this->makeUser();
        $stmt = $this->pdo->prepare(
            "INSERT INTO orders (user_id, total_amount, status, taken_by_admin_id) VALUES (?, ?, 'taken', ?)"
        );
        $stmt->execute([$userId, 50.00, $adminId]);
        $stmt->execute([$userId, 70.00, $adminId]);

        $page = OrderModel::getAdminOrdersList([], 1, 1);
        $this->assertCount(1, $page['orders']);
        $this->assertSame(2, $page['total']);

        $this->assertCount(2, OrderModel::getOrdersHandledByAdmin($adminId));
    }

    public function testTheUsersPanel(): void
    {
        $this->makeUser('Panel One');
        $this->makeUser('Panel Two');

        $result = UserModel::getAllForAdmin('Panel', 'all', 2, 1);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(2, $result['total']);
    }

    public function testTheProductsPanelAndTheSliderProductSearch(): void
    {
        $this->makeProduct('Panel Phone');
        $this->makeProduct('Panel Laptop');

        $this->assertCount(1, AdminProductModel::getPaginated('Panel', [], null, null, null, 1, 1));
        $this->assertCount(1, BrandingModel::searchProducts('Panel', 1));
    }
}
