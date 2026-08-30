<?php
/**
 * app/views/admin/dashboard.php — a fragment only (no DOCTYPE/html/head/body).
 * Loaded by AdminController::adminView() after inc/head.php and inc/navbar.php
 * Every variable arrives ready from AdminDashboardController::index().
 * It contains no queries and no logic — those belong in the controller and the model alone.
 */
?>

<div class="admin-page-header">
    <h1>📊 Dashboard</h1>
</div>

<?php // ── Stats ──────────────────────────────────────────────── ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="stat-icon">💰</span>
            <div class="stat-value">$<?= number_format($todaySales, 2) ?></div>
            <div class="stat-label">Today's Sales</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card <?= $pendingOrders > 0 ? 'u-alert-amber' : '' ?>">
            <span class="stat-icon">📦</span>
            <div class="stat-value <?= $pendingOrders > 0 ? 'u-alert-amber-text' : '' ?>"><?= $pendingOrders ?></div>
            <div class="stat-label">Pending Orders</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card <?= $newMessages > 0 ? 'u-alert-indigo' : '' ?>">
            <span class="stat-icon">💬</span>
            <div class="stat-value <?= $newMessages > 0 ? 'u-alert-indigo-text' : '' ?>"><?= $newMessages ?></div>
            <div class="stat-label">New Messages</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="stat-icon">👤</span>
            <div class="stat-value"><?= $newUsersWeek ?></div>
            <div class="stat-label">New Users (7d)</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="stat-icon">⚠️</span>
            <div class="stat-value u-danger"><?= $totalStrikes ?></div>
            <div class="stat-label">Strikes (7d)</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="stat-icon">📈</span>
            <div class="stat-value">$<?= number_format($monthToDateSales, 2) ?></div>
            <div class="stat-label">Revenue This Month</div>
        </div>
    </div>
</div>

<?php // ── Charts ─────────────────────────────────────────────── ?>
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="chart-card">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <h5 class="mb-0">📈 Sales — Last 30 Days</h5>
                <span class="badge bg-success dash-badge-month">
                    Total this month: $<?= number_format($monthToDateSales, 2) ?>
                </span>
            </div>
            <div class="u-chart-box"><canvas id="salesChart"></canvas></div>
            <?php
            // The chart's figures — data in a JSON island, not code.
            //
            // They used to be injected inside a <script> block the controller assembled by
            // string concatenation — an executable block that CSP blocks and that cannot be
            // hashed, because its contents change daily. See AdminDashboardController.
            //
            // And it belongs here rather than in $extraScripts: the footer prints
            // $extraScripts after the synchronous js/core/page-data.js.
            echo pageData([
                'ADMIN_SALES_CHART' => [
                    'labels' => $chartLabels,
                    'values' => $chartValues,
                ],
            ]);
            ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="chart-card">
            <h5>👥 Users</h5>
            <div class="table-responsive">
                <table class="table admin-table mb-0">
                    <tbody>
                        <tr>
                            <td>🟢 Active</td>
                            <td class="text-end fw-bold"><?= $activeUsersCount ?></td>
                        </tr>
                        <tr>
                            <td>⚪ Not Active</td>
                            <td class="text-end fw-bold"><?= $notActiveUsersCount ?></td>
                        </tr>
                        <tr>
                            <td>🔴 Blocked</td>
                            <td class="text-end fw-bold"><?= $blockedUsersCount ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php // ── Best Sellers ───────────────────────────────────────── ?>
<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="mb-0">⭐ Best Selling Products</h5>
        <form method="GET" action="<?= URLROOT ?>/admin/dashboard" class="d-flex gap-2 flex-wrap">
            <input type="text" name="q" class="form-control form-control-sm dash-search-input"
                   placeholder="Search..." value="<?= htmlspecialchars($bsQ) ?>">
            <button class="btn btn-sm btn-success">Go</button>
            <?php if ($bsQ): ?>
            <a href="<?= URLROOT ?>/admin/dashboard" class="btn btn-sm btn-outline-secondary">✕</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="row g-3">
        <?php if (empty($bestProducts)): ?>
        <p class="text-center u-muted">No products found.</p>
        <?php endif; ?>
        <?php foreach ($bestProducts as $bp): ?>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card p-2 text-center h-100 d-block u-cursor-default">
                <img src="<?= htmlspecialchars(fixImagePath($bp['image_path'] ?? '')) ?>"
                     alt="<?= htmlspecialchars($bp['name']) ?>"
                     class="dash-product-img"
                     loading="lazy">
                <p class="small fw-bold mb-0 mt-1 dash-product-name">
                    <?= htmlspecialchars($bp['name']) ?>
                </p>
                <span class="badge bg-success mt-1"><?= (int) $bp['sales_count'] ?> sold</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
