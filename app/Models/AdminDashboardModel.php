<?php

namespace App\Models;

use App\Core\Model;

/**
 * AdminDashboardModel — statistics queries for the admin dashboard alone.
 * A model of its own for the dashboard; it touches no other model.
 */
class AdminDashboardModel extends Model
{
    public static function getTodaySales(): float
    {
        $db = self::db();
        return (float) $db->query(
            "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status!='cancelled'"
        )->fetchColumn();
    }

    public static function getTodayOrdersCount(): int
    {
        $db = self::db();
        return (int) $db->query(
            "SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()"
        )->fetchColumn();
    }

    public static function getPendingOrdersCount(): int
    {
        $db = self::db();
        return (int) $db->query(
            "SELECT COUNT(*) FROM orders WHERE status = 'not_taken'"
        )->fetchColumn();
    }

    public static function getNewUsersThisWeek(): int
    {
        $db = self::db();
        return (int) $db->query(
            "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();
    }

    public static function getStrikesThisWeek(): int
    {
        $db = self::db();
        return (int) $db->query(
            "SELECT COUNT(*) FROM user_strikes WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();
    }

    /**
     * The unread notification count for one admin.
     * Note: the full notification system (the sidebar) is not built in the rewrite yet,
     * so this is a simple direct query against admin_notifications rather than a
     * dependency on a helper that does not exist.
     */
    public static function getUnreadNotificationsCount(int $adminId): int
    {
        $db   = self::db();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM admin_notifications WHERE admin_id = ? AND is_read = 0"
        );
        $stmt->execute([$adminId]);
        return (int) $stmt->fetchColumn();
    }

    public static function getMonthToDateSales(): float
    {
        $db = self::db();
        return (float) $db->query(
            "SELECT COALESCE(SUM(total_amount),0) FROM orders
             WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
               AND status != 'cancelled'"
        )->fetchColumn();
    }

    /** Sales over the last 30 days — for the chart. */
    /**
     * @return list<array<string, mixed>>
     */
    public static function getSalesLast30Days(): array
    {
        $db = self::db();
        return $db->query(
            "SELECT DATE(created_at) AS day, SUM(total_amount) AS total
             FROM orders
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status != 'cancelled'
             GROUP BY DATE(created_at) ORDER BY day ASC"
        )->fetchAll();
    }

    /** User distribution: active / inactive / blocked (three strikes or more). */
    /**
     * @return array<string, mixed>
     */
    public static function getUsersActivityBreakdown(): array
    {
        $db = self::db();

        $active = (int) $db->query(
            "SELECT COUNT(*) FROM users u
             WHERE (SELECT COUNT(*) FROM user_strikes WHERE user_id=u.id) < 3
               AND u.last_activity >= DATE_SUB(NOW(), INTERVAL 3 MONTH)"
        )->fetchColumn();

        $notActive = (int) $db->query(
            "SELECT COUNT(*) FROM users u
             WHERE (SELECT COUNT(*) FROM user_strikes WHERE user_id=u.id) < 3
               AND (u.last_activity < DATE_SUB(NOW(), INTERVAL 3 MONTH) OR u.last_activity IS NULL)"
        )->fetchColumn();

        $blocked = (int) $db->query(
            "SELECT COUNT(*) FROM users u
             WHERE (SELECT COUNT(*) FROM user_strikes WHERE user_id=u.id) >= 3"
        )->fetchColumn();

        return ['active' => $active, 'not_active' => $notActive, 'blocked' => $blocked];
    }

    /** The 12 best-selling products, with optional search by name. */
    /**
     * @return list<array<string, mixed>>
     */
    public static function getBestSellingProducts(string $search = ''): array
    {
        $db     = self::db();
        $where  = '';
        $params = [];

        if ($search !== '') {
            $where    = " WHERE name LIKE ?";
            $params[] = "%{$search}%";
        }

        $stmt = $db->prepare(
            "SELECT id, name, image_path, sales_count, stock_quantity
             FROM products{$where}
             ORDER BY sales_count DESC LIMIT 12"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
