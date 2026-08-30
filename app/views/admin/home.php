<?php
/**
 * app/views/admin/home.php — a fragment only (no DOCTYPE/html/head/body).
 * Loaded by AdminController::adminView() after inc/head.php and inc/navbar.php
 * The available variables: $adminName, $adminRole, $adminId, $csrf (from adminView).
 * It contains no logic belonging to the regular user.
 */

// Build the tiles array from the rank and the permissions
$tiles = [];

if (hasPermission('can_manage_admins')) {
    $tiles[] = [
        'icon'  => '👑',
        'label' => 'Manage Admins',
        'href'  => URLROOT . '/admin/admins',
        'tone'  => 'amber',
    ];
}

if (hasPermission('can_view_dashboard')) {
    $tiles[] = [
        'icon'  => '📊',
        'label' => 'Dashboard',
        'href'  => URLROOT . '/admin/dashboard',
        'tone'  => 'indigo',
    ];
}

if (hasPermission('can_manage_products')) {
    $tiles[] = [
        'icon'  => '🛍️',
        'label' => 'Products',
        'href'  => URLROOT . '/admin/products',
        'tone'  => 'green',
    ];
}

if (hasPermission('can_manage_users')) {
    $tiles[] = [
        'icon'  => '👥',
        'label' => 'Users',
        'href'  => URLROOT . '/admin/users',
        'tone'  => 'sky',
    ];
}

if (hasPermission('can_manage_support')) {
    $tiles[] = [
        'icon'  => '💬',
        'label' => 'Support',
        'href'  => URLROOT . '/admin/support',
        'tone'  => 'violet',
    ];
}

if (hasPermission('can_manage_orders')) {
    $tiles[] = [
        'icon'  => '📦',
        'label' => 'Orders',
        'href'  => URLROOT . '/admin/orders',
        'tone'  => 'orange',
    ];
}

if (hasPermission('can_edit_site_content')) {
    $tiles[] = [
        'icon'  => '⚙️',
        'label' => 'Site Configuration',
        'href'  => URLROOT . '/admin/settings',
        'tone'  => 'slate',
    ];
}

if (hasPermission('can_manage_branding')) {
    $tiles[] = [
        'icon'  => '🎬',
        'label' => 'Slider',
        'href'  => URLROOT . '/admin/branding',
        'tone'  => 'purple',
    ];
}

// Store Mode — visible to every admin, no permission required (POST form like navbar)
$tiles[] = [
    'icon'  => '🌐',
    'label' => 'Store Mode',
    'href'  => URLROOT . '/admin/store-mode/enter',
    'tone'  => 'sky',
    'method' => 'POST',
];

if ($adminId === 1) {
    $tiles[] = [
        'icon'  => '💾',
        'label' => 'Backup DB',
        'href'  => URLROOT . '/admin/backup',
        'tone'  => 'red',
    ];
}
?>

<?php // The welcome message ?>
<div class="home-welcome">
    <h1>Welcome back, <?= htmlspecialchars($adminName) ?> 👋</h1>
    <p>Choose a section below to get started.</p>
</div>

<?php if (empty($tiles)): ?>
    <?php // No specific permissions for this admin ?>
    <div class="text-center py-5 text-muted">
        <div class="u-fs-xxl">🔒</div>
        <p class="mt-3">No sections are available for your current role.<br>Contact a Super Admin to assign permissions.</p>
    </div>

<?php elseif (count($tiles) === 1): ?>
    <?php // A single tile ?>
    <div class="single-tile-wrap">
        <?php if (($tiles[0]['method'] ?? 'GET') === 'POST'): ?>
        <form method="POST" action="<?= htmlspecialchars($tiles[0]['href']) ?>" class="d-inline m-0 p-0">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="home-tile w-100 u-bare-button">
                <div class="tile-icon u-tone-<?= htmlspecialchars($tiles[0]['tone']) ?>">
                    <?php // @escaping-safe: $tiles is an array of literal strings defined in this file ?>
                    <?= $tiles[0]['icon'] ?>
                </div>
                <span class="tile-label"><?= htmlspecialchars($tiles[0]['label']) ?></span>
            </button>
        </form>
        <?php else: ?>
        <a class="home-tile" href="<?= htmlspecialchars($tiles[0]['href']) ?>">
            <div class="tile-icon u-tone-<?= htmlspecialchars($tiles[0]['tone']) ?>">
                <?php // @escaping-safe: $tiles is an array of literal strings defined in this file ?>
                <?= $tiles[0]['icon'] ?>
            </div>
            <span class="tile-label"><?= htmlspecialchars($tiles[0]['label']) ?></span>
        </a>
        <?php endif; ?>
    </div>

<?php else: ?>
    <?php // A multi-column grid ?>
    <div class="row g-3">
        <?php foreach ($tiles as $tile): ?>
        <div class="col-6 col-sm-4 col-md-3 col-xl-2">
            <?php if (($tile['method'] ?? 'GET') === 'POST'): ?>
            <form method="POST" action="<?= htmlspecialchars($tile['href']) ?>" class="d-inline m-0 p-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="home-tile h-100 w-100 u-bare-button">
                    <div class="tile-icon u-tone-<?= htmlspecialchars($tile['tone']) ?>">
                        <?php // @escaping-safe: $tiles is an array of literal strings defined in this file ?>
                        <?= $tile['icon'] ?>
                    </div>
                    <span class="tile-label"><?= htmlspecialchars($tile['label']) ?></span>
                </button>
            </form>
            <?php else: ?>
            <a class="home-tile h-100" href="<?= htmlspecialchars($tile['href']) ?>">
                <div class="tile-icon u-tone-<?= htmlspecialchars($tile['tone']) ?>">
                    <?php // @escaping-safe: $tiles is an array of literal strings defined in this file ?>
                    <?= $tile['icon'] ?>
                </div>
                <span class="tile-label"><?= htmlspecialchars($tile['label']) ?></span>
            </a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
