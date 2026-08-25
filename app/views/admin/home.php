<?php
/**
 * app/views/admin/home.php — fragment فقط (بدون DOCTYPE/html/head/body)
 * يُحمَّل من AdminController::adminView() بعد inc/head.php و inc/navbar.php
 * المتغيرات المتاحة: $adminName, $adminRole, $adminId, $csrf (من adminView)
 * لا يحتوي على أي منطق خاص باليوزر العادي.
 */

// بناء مصفوفة الـ tiles بناءً على الرتبة والصلاحيات
$tiles = [];

if (hasPermission('can_manage_admins')) {
    $tiles[] = [
        'icon'  => '👑',
        'label' => 'Manage Admins',
        'href'  => URLROOT . '/admin/admins',
        'color' => '#f59e0b',
    ];
}

if (hasPermission('can_view_dashboard')) {
    $tiles[] = [
        'icon'  => '📊',
        'label' => 'Dashboard',
        'href'  => URLROOT . '/admin/dashboard',
        'color' => '#6366f1',
    ];
}

if (hasPermission('can_manage_products')) {
    $tiles[] = [
        'icon'  => '🛍️',
        'label' => 'Products',
        'href'  => URLROOT . '/admin/products',
        'color' => '#16a34a',
    ];
}

if (hasPermission('can_manage_users')) {
    $tiles[] = [
        'icon'  => '👥',
        'label' => 'Users',
        'href'  => URLROOT . '/admin/users',
        'color' => '#0ea5e9',
    ];
}

if (hasPermission('can_manage_support')) {
    $tiles[] = [
        'icon'  => '💬',
        'label' => 'Support',
        'href'  => URLROOT . '/admin/support',
        'color' => '#8b5cf6',
    ];
}

if (hasPermission('can_manage_orders')) {
    $tiles[] = [
        'icon'  => '📦',
        'label' => 'Orders',
        'href'  => URLROOT . '/admin/orders',
        'color' => '#f97316',
    ];
}

if (hasPermission('can_edit_site_content')) {
    $tiles[] = [
        'icon'  => '⚙️',
        'label' => 'Site Configuration',
        'href'  => URLROOT . '/admin/settings',
        'color' => '#64748b',
    ];
}

if (hasPermission('can_manage_branding')) {
    $tiles[] = [
        'icon'  => '🎬',
        'label' => 'Slider',
        'href'  => URLROOT . '/admin/branding',
        'color' => '#a855f7',
    ];
}

// Store Mode — visible to every admin, no permission required (POST form like navbar)
$tiles[] = [
    'icon'  => '🌐',
    'label' => 'Store Mode',
    'href'  => URLROOT . '/admin/store-mode/enter',
    'color' => '#0ea5e9',
    'method' => 'POST',
];

if ($adminId === 1) {
    $tiles[] = [
        'icon'  => '💾',
        'label' => 'Backup DB',
        'href'  => URLROOT . '/admin/backup',
        'color' => '#dc2626',
    ];
}
?>

<!-- رسالة الترحيب -->
<div class="home-welcome">
    <h1>Welcome back, <?= htmlspecialchars($adminName) ?> 👋</h1>
    <p>Choose a section below to get started.</p>
</div>

<?php if (empty($tiles)): ?>
    <!-- لا صلاحيات مخصصة لهذا الأدمن -->
    <div class="text-center py-5 text-muted">
        <div style="font-size:3rem;">🔒</div>
        <p class="mt-3">No sections are available for your current role.<br>Contact a Super Admin to assign permissions.</p>
    </div>

<?php elseif (count($tiles) === 1): ?>
    <!-- tile واحدة فقط -->
    <div class="single-tile-wrap">
        <?php if (($tiles[0]['method'] ?? 'GET') === 'POST'): ?>
        <form method="POST" action="<?= htmlspecialchars($tiles[0]['href']) ?>" class="d-inline m-0 p-0">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="home-tile w-100" style="border:0;background:none;padding:0;">
                <div class="tile-icon"
                     style="background:<?= $tiles[0]['color'] ?>22; color:<?= $tiles[0]['color'] ?>;">
                    <?= $tiles[0]['icon'] ?>
                </div>
                <span class="tile-label"><?= htmlspecialchars($tiles[0]['label']) ?></span>
            </button>
        </form>
        <?php else: ?>
        <a class="home-tile" href="<?= htmlspecialchars($tiles[0]['href']) ?>">
            <div class="tile-icon"
                 style="background:<?= $tiles[0]['color'] ?>22; color:<?= $tiles[0]['color'] ?>;">
                <?= $tiles[0]['icon'] ?>
            </div>
            <span class="tile-label"><?= htmlspecialchars($tiles[0]['label']) ?></span>
        </a>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- grid متعدد -->
    <div class="row g-3">
        <?php foreach ($tiles as $tile): ?>
        <div class="col-6 col-sm-4 col-md-3 col-xl-2">
            <?php if (($tile['method'] ?? 'GET') === 'POST'): ?>
            <form method="POST" action="<?= htmlspecialchars($tile['href']) ?>" class="d-inline m-0 p-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="home-tile h-100 w-100" style="border:0;background:none;padding:0;">
                    <div class="tile-icon"
                         style="background:<?= $tile['color'] ?>22; color:<?= $tile['color'] ?>;">
                        <?= $tile['icon'] ?>
                    </div>
                    <span class="tile-label"><?= htmlspecialchars($tile['label']) ?></span>
                </button>
            </form>
            <?php else: ?>
            <a class="home-tile h-100" href="<?= htmlspecialchars($tile['href']) ?>">
                <div class="tile-icon"
                     style="background:<?= $tile['color'] ?>22; color:<?= $tile['color'] ?>;">
                    <?= $tile['icon'] ?>
                </div>
                <span class="tile-label"><?= htmlspecialchars($tile['label']) ?></span>
            </a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
