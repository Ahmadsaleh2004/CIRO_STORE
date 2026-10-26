<?php

namespace Tests\Integration;

use App\Models\OrderModel;
use Tests\Support\DatabaseTestCase;

/**
 * An admin's "Total completed profit" counts every completed order, not the newest fifty.
 *
 * The admin details page used to add up the rows of getOrdersHandledByAdmin, which stops at
 * fifty — so the total fell short once an admin passed fifty completed orders. It is summed
 * by the database now.
 */
final class AdminProfitTotalTest extends DatabaseTestCase
{
    private function admin(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO admins (full_name, email, password, role) VALUES (?, ?, ?, ?)');
        $stmt->execute(['Profit Admin', 'profit' . uniqid() . '@example.com', password_hash('secret123', PASSWORD_BCRYPT), 'A']);

        return (int) $this->pdo->lastInsertId();
    }

    public function testEveryCompletedOrderIsCountedPastTheListsFifty(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (full_name, email) VALUES (?, ?)');
        $stmt->execute(['Buyer', 'buyer' . uniqid() . '@example.com']);
        $userId = (int) $this->pdo->lastInsertId();

        $admin = $this->admin();
        $other = $this->admin();

        $order = $this->pdo->prepare(
            'INSERT INTO orders (user_id, total_amount, status, taken_by_admin_id) VALUES (?, ?, ?, ?)'
        );
        for ($i = 0; $i < 55; $i++) {
            $order->execute([$userId, 10.00, 'completed', $admin]);
        }
        $order->execute([$userId, 999.00, 'taken', $admin]);      // not completed
        $order->execute([$userId, 777.00, 'completed', $other]);  // somebody else's

        $this->assertCount(50, OrderModel::getOrdersHandledByAdmin($admin), 'The list stays capped');
        $this->assertSame(550.0, OrderModel::completedTotalForAdmin($admin));
    }
}
