<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\AdminDashboardModel;
use OpenApi\Attributes as OA;

/**
 * AdminDashboardController — the admin's real statistics page.
 * Extends AdminController, which verifies the admin login automatically.
 */
class AdminDashboardController extends AdminController
{
    #[OA\Get(
        path: '/admin/dashboard',
        summary: 'Admin dashboard statistics (sales, orders, users, best-selling products)',
        tags: ['Admin - Dashboard'],
        security: [['adminSessionAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'q',
                description: 'Optional name search within the best-sellers list',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard HTML page — requires a valid admin_session and the can_view_dashboard permission'),
            new OA\Response(response: 302, description: 'Redirect to /admin/login when the session is not valid'),
            new OA\Response(response: 403, description: 'Forbidden — the admin lacks the can_view_dashboard permission'),
        ]
    )]
    public function index(): void
    {
        // A fine-grained permission on top of AdminController's base protection (rank A always overrides it)
        Middleware::requirePermission('can_view_dashboard');

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $search  = trim($_GET['q'] ?? '');

        // ── Stat cards ──────────────────────────────────────
        $todaySales    = AdminDashboardModel::getTodaySales();
        $pendingOrders = AdminDashboardModel::getPendingOrdersCount();
        $newMessages   = AdminDashboardModel::getUnreadNotificationsCount($adminId);
        $newUsersWeek  = AdminDashboardModel::getNewUsersThisWeek();
        $totalStrikes  = AdminDashboardModel::getStrikesThisWeek();

        // ── Chart: sales over the last 30 days ───────────────
        $salesRows        = AdminDashboardModel::getSalesLast30Days();
        $chartLabels      = array_values(array_column($salesRows, 'day'));
        $chartValues      = array_values(array_map('floatval', array_column($salesRows, 'total')));
        $monthToDateSales = AdminDashboardModel::getMonthToDateSales();

        // ── User distribution ────────────────────────────────
        $usersBreakdown = AdminDashboardModel::getUsersActivityBreakdown();

        // ── Best-selling products ────────────────────────────
        $bestProducts = AdminDashboardModel::getBestSellingProducts($search);

        // ── Chart.js assets ──────────────────────────────────
        //
        // ⚠️ There used to be two string-built tags here: a <script src> to the CDN
        // with neither `integrity` nor `crossorigin`, and an inline <script> block
        // assembled by splicing JSON into JavaScript source.
        //
        // And that inline block was **blocked outright**: the CSP in
        // public/.htaccess forbids script-src 'unsafe-inline', and hashing it is
        // impossible because its contents change daily with the sales figures. So
        // the chart was never drawn at all — an empty canvas and a refusal line in
        // the console, nothing more.
        //
        // Now: the library comes from VENDOR_ASSETS with its hash, the logic lives
        // in an external file, and the figures travel as data in a JSON island
        // printed by the view.
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
