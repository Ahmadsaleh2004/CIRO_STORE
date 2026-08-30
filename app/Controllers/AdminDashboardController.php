<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\AdminDashboardModel;
use OpenApi\Attributes as OA;

/**
 * AdminDashboardController — صفحة الإحصائيات الحقيقية للأدمن.
 * يرث من AdminController الذي يتحقق من تسجيل دخول الأدمن تلقائياً.
 */
class AdminDashboardController extends AdminController
{
    #[OA\Get(
        path: '/admin/dashboard',
        summary: 'صفحة إحصائيات لوحة تحكم الأدمن (مبيعات، طلبات، مستخدمون، أفضل المنتجات مبيعًا)',
        tags: ['Admin - Dashboard'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'q',
                description: 'بحث اختياري بالاسم ضمن قائمة أفضل المنتجات مبيعًا',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML للداشبورد — يتطلب جلسة admin_session صالحة وصلاحية can_view_dashboard'),
            new OA\Response(response: 302, description: 'إعادة توجيه لـ /admin/login إذا لم تكن الجلسة صالحة'),
            new OA\Response(response: 403, description: 'ممنوع — الأدمن ليس لديه صلاحية can_view_dashboard'),
        ]
    )]
    public function index(): void
    {
        // صلاحية دقيقة إضافية فوق حماية AdminController الأساسية (رتبة A تتجاوزها دائمًا)
        Middleware::requirePermission('can_view_dashboard');

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $search  = trim($_GET['q'] ?? '');

        // ── بطاقات إحصائية ──────────────────────────────────
        $todaySales    = AdminDashboardModel::getTodaySales();
        $pendingOrders = AdminDashboardModel::getPendingOrdersCount();
        $newMessages   = AdminDashboardModel::getUnreadNotificationsCount($adminId);
        $newUsersWeek  = AdminDashboardModel::getNewUsersThisWeek();
        $totalStrikes  = AdminDashboardModel::getStrikesThisWeek();

        // ── رسم بياني: مبيعات آخر 30 يوم ─────────────────────
        $salesRows        = AdminDashboardModel::getSalesLast30Days();
        $chartLabels      = array_values(array_column($salesRows, 'day'));
        $chartValues      = array_values(array_map('floatval', array_column($salesRows, 'total')));
        $monthToDateSales = AdminDashboardModel::getMonthToDateSales();

        // ── توزيع المستخدمين ─────────────────────────────────
        $usersBreakdown = AdminDashboardModel::getUsersActivityBreakdown();

        // ── أفضل المنتجات مبيعًا ──────────────────────────────
        $bestProducts = AdminDashboardModel::getBestSellingProducts($search);

        // ── أصول الـ Chart.js ─────────────────────────────────
        //
        // ⚠️ كان هنا وسمان مبنيّان بالنصّ: <script src> إلى الـCDN بلا
        // `integrity` ولا `crossorigin`، وكتلة <script> مضمّنة تُبنى
        // بضمّ JSON داخل كود JS.
        //
        // والكتلة المضمّنة كانت **محجوبة أصلاً**: سياسة CSP في
        // public/.htaccess تمنع script-src 'unsafe-inline'، وبصمتها
        // مستحيلة لأن محتواها يتغيّر كل يوم بتغيّر أرقام المبيعات.
        // فالرسم البياني لم يكن يُرسم إطلاقاً — canvas فارغ وسطر رفض
        // في الـconsole لا غير.
        //
        // الآن: المكتبة من VENDOR_ASSETS ببصمتها، والمنطق في ملف
        // خارجي، والأرقام تعبر كبيانات في جزيرة JSON يطبعها الـview.
        $extraScripts = vendorJs('chartjs', false)
            . jsTag('js/admin/dashboard-chart.js', false);

        $this->adminView('dashboard', [
            'pageTitle'           => 'Dashboard',
            'todaySales'          => $todaySales,
            'pendingOrders'       => $pendingOrders,
            'newMessages'         => $newMessages,
            'newUsersWeek'        => $newUsersWeek,
            'totalStrikes'        => $totalStrikes,
            'monthToDateSales'    => $monthToDateSales,
            'activeUsersCount'    => $usersBreakdown['active'],
            'notActiveUsersCount' => $usersBreakdown['not_active'],
            'blockedUsersCount'   => $usersBreakdown['blocked'],
            'bestProducts'        => $bestProducts,
            'bsQ'                 => $search,
            'chartLabels'         => $chartLabels,
            'chartValues'         => $chartValues,
            'extraScripts'        => $extraScripts,
        ]);
    }
}
