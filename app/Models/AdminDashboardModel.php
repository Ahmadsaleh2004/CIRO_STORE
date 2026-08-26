<?php

namespace App\Models;

use App\Core\Database;

/**
 * AdminDashboardModel — استعلامات إحصائيات لوحة تحكم الأدمن فقط.
 * موديل منفصل خاص بالـ Dashboard، لا يلمس أي موديل آخر.
 */
class AdminDashboardModel
{
    public static function getTodaySales(): float
    {
        $db = Database::connect();
        return (float) $db->query(
            "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status!='cancelled'"
        )->fetchColumn();
    }

    public static function getTodayOrdersCount(): int
    {
        $db = Database::connect();
        return (int) $db->query(
            "SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()"
        )->fetchColumn();
    }

    public static function getPendingOrdersCount(): int
    {
        $db = Database::connect();
        return (int) $db->query(
            "SELECT COUNT(*) FROM orders WHERE status = 'not_taken'"
        )->fetchColumn();
    }

    public static function getNewUsersThisWeek(): int
    {
        $db = Database::connect();
        return (int) $db->query(
            "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();
    }

    public static function getStrikesThisWeek(): int
    {
        $db = Database::connect();
        return (int) $db->query(
            "SELECT COUNT(*) FROM user_strikes WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();
    }

    /**
     * عدد الإشعارات غير المقروءة لأدمن معيّن.
     * ملاحظة: نظام الإشعارات الكامل (notif sidebar) لسا مو مبني بالجديد،
     * لهيك هون استعلام مباشر وبسيط بس على جدول admin_notifications
     * بدل الاعتماد على helper مو موجود بعد.
     */
    public static function getUnreadNotificationsCount(int $adminId): int
    {
        $db   = Database::connect();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM admin_notifications WHERE admin_id = ? AND is_read = 0"
        );
        $stmt->execute([$adminId]);
        return (int) $stmt->fetchColumn();
    }

    public static function getMonthToDateSales(): float
    {
        $db = Database::connect();
        return (float) $db->query(
            "SELECT COALESCE(SUM(total_amount),0) FROM orders
             WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
               AND status != 'cancelled'"
        )->fetchColumn();
    }

    /** مبيعات آخر 30 يوم — لرسم الـ Chart. */
    public static function getSalesLast30Days(): array
    {
        $db = Database::connect();
        return $db->query(
            "SELECT DATE(created_at) AS day, SUM(total_amount) AS total
             FROM orders
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status != 'cancelled'
             GROUP BY DATE(created_at) ORDER BY day ASC"
        )->fetchAll();
    }

    /** توزيع المستخدمين: نشط / غير نشط / محظور (3 إنذارات فأكثر). */
    public static function getUsersActivityBreakdown(): array
    {
        $db = Database::connect();

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

    /** أفضل 12 منتج مبيعًا، مع دعم بحث اختياري بالاسم. */
    public static function getBestSellingProducts(string $search = ''): array
    {
        $db     = Database::connect();
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
