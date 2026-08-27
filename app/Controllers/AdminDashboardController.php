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
        $chartLabels      = json_encode(array_column($salesRows, 'day'));
        $chartValues      = json_encode(array_map('floatval', array_column($salesRows, 'total')));
        $monthToDateSales = AdminDashboardModel::getMonthToDateSales();

        // ── توزيع المستخدمين ─────────────────────────────────
        $usersBreakdown = AdminDashboardModel::getUsersActivityBreakdown();

        // ── أفضل المنتجات مبيعًا ──────────────────────────────
        $bestProducts = AdminDashboardModel::getBestSellingProducts($search);

        // ── سكربت الـ Chart.js ────────────────────────────────
        $extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded",()=>{
    const dark=document.body.classList.contains("dark-mode");
    const grid=dark?"rgba(255,255,255,.07)":"rgba(0,0,0,.06)";
    const tc=dark?"#e6edf3":"#1a1a2e";
    const axes={x:{ticks:{color:tc},grid:{color:grid}},y:{ticks:{color:tc},grid:{color:grid}}};

    new Chart(document.getElementById("salesChart"),{
        type:"line",
        data:{
            labels:' . $chartLabels . ',
            datasets:[{label:"Sales ($)",data:' . $chartValues . ',
                borderColor:"#6366f1",backgroundColor:"rgba(99,102,241,.12)",
                tension:.4,fill:true,pointRadius:4,pointBackgroundColor:"#6366f1"}]
        },
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:tc}}},scales:axes}
    });
});
</script>';

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
            'extraScripts'        => $extraScripts,
        ]);
    }
}
