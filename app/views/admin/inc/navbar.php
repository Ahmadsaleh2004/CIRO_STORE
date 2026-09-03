<?php
/**
 * app/views/admin/inc/navbar.php
 * The available variables (injected automatically by AdminController::adminView()):
 *   $adminName  — the admin's name
 *   $adminRole  — their rank (A/B/C/D)
 *   $adminId    — their id
 *   $csrf       — the current CSRF token
 *
 * ── Why the actions sit outside the collapse ─────────────────────────
 * The bar is navbar-expand-xl, so below 1200px Bootstrap folds the collapse away — and
 * everything inside it went with it. That included the theme toggle, the notification bell
 * and the admin's own menu, which are the three controls most wanted on a phone and were
 * the three hardest to reach: two taps behind a hamburger, under nine page links.
 *
 * So the action group is now a direct child of the container rather than a child of
 * #adminNav. On a desktop nothing moves — the collapse is expanded at that width anyway,
 * and the row is still brand | links | actions. Below 1200px the four controls stay on the
 * bar and the nine links move to #adminSidebar.
 *
 * #adminNav keeps no toggler of its own now. A collapse with nothing targeting it stays at
 * `display: none` below the expand width and is forced visible above it by
 * .navbar-expand-xl — which is exactly the behaviour wanted, with no utility class needed.
 */

/** @var list<array{href: string, label: string, class: string, badge: int, form: bool}> $adminNavLinks */
$adminNavLinks = require __DIR__ . '/_nav-links.php';
?>
<nav class="navbar custom-navbar navbar-expand-xl" id="mainNavbar">
    <div class="container-fluid px-3">
        <a class="navbar-brand fw-bold" href="<?= URLROOT ?>/admin/home">
            🏪 Cairo Store
            <span class="badge bg-warning text-dark ms-1 u-fs-60 align-middle">ADMIN</span>
        </a>

        <?php // Desktop only: hidden below 1200px by .collapse, shown by .navbar-expand-xl above it ?>
        <div class="collapse navbar-collapse" id="adminNav">
            <ul class="navbar-nav mx-auto gap-1">
                <?php foreach ($adminNavLinks as $link): ?>
                <li class="nav-item<?= $link['badge'] > 0 ? ' position-relative' : '' ?>">
                    <?php if ($link['form']): ?>
                    <form method="POST" action="<?= htmlspecialchars($link['href']) ?>" class="d-inline m-0 p-0">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <button type="submit" class="nav-link btn btn-link p-0 u-bare-button"
                                title="Browse the store as a visitor"><?= $link['label'] ?></button>
                    </form>
                    <?php else: ?>
                    <a class="nav-link <?= $link['class'] ?>" href="<?= htmlspecialchars($link['href']) ?>">
                        <?= $link['label'] ?>
                        <?php if ($link['badge'] > 0): ?>
                        <span class="counter-badge u-badge-corner-out"><?= $link['badge'] ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php // Always on the bar, at every width ?>
        <div class="d-flex gap-2 align-items-center justify-content-end admin-nav-actions">
            <button id="theme-toggle" class="btn btn-outline-light" title="Toggle Theme">🌙</button>

            <?php // The admin notification bell — wired to admin-notifications.js and /admin/notifications/* ?>
            <button id="adminNotifBell" class="btn btn-outline-light position-relative"
                    type="button" aria-label="Admin Notifications" title="Notifications">
                🔔 <span id="adminNotifBadge" class="counter-badge u-badge-dot d-none" aria-live="polite">0</span>
            </button>

            <?php /*
The admin's own menu, in two shapes. The dropdown is the desktop one and is
                 untouched. Below 1200px it is replaced by a plain link straight to My Info:
                 with Backup and Log Out now at the foot of the sidebar, the menu had one
                 item left in it, and a dropdown holding one item is a tap spent on nothing.
*/ ?>
            <div class="dropdown d-none d-xl-block">
                <button class="btn btn-outline-warning dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    👑 <?= htmlspecialchars($adminName) ?>
                    <span class="badge bg-dark ms-1 u-fs-60"><?= htmlspecialchars($adminRole) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end u-surface-card">
                    <li><a class="dropdown-item u-text" href="<?= URLROOT ?>/admin/my-info">👤 My Info</a></li>
                    <?php if ($adminId === 1): ?>
                    <li><a class="dropdown-item u-text" href="<?= URLROOT ?>/admin/backup">💾 Backup DB</a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider u-border-section"></li>
                    <li><a class="dropdown-item text-danger" href="#" data-action="logout-admin">🚪 Log Out</a></li>
                </ul>
            </div>

            <a href="<?= URLROOT ?>/admin/my-info"
               class="btn btn-outline-warning d-xl-none admin-nav-me" title="My Info">
                👑 <span class="admin-nav-me-name"><?= htmlspecialchars($adminName) ?></span>
                <span class="badge bg-dark ms-1 u-fs-60"><?= htmlspecialchars($adminRole) ?></span>
            </a>

            <button class="navbar-toggler d-xl-none" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#adminSidebar"
                    aria-controls="adminSidebar" aria-label="Open the admin menu">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
    </div>
</nav>

<?php /*
The sidebar the hamburger opens. Bootstrap's offcanvas, the same component the store's
     cart already uses (app/views/inc/modals/cart.php) — so its JavaScript is loaded on every
     admin page, and the backdrop, the Escape key, the focus trap and the scroll lock all come
     with it rather than being written again.
*/ ?>
<div class="offcanvas offcanvas-end admin-sidebar" tabindex="-1"
     id="adminSidebar" aria-labelledby="adminSidebarLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title fw-bold" id="adminSidebarLabel">
            🏪 Cairo Store
            <span class="badge bg-warning text-dark ms-1 u-fs-60 align-middle">ADMIN</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">
        <ul class="admin-sidebar-nav">
            <?php foreach ($adminNavLinks as $link): ?>
            <li>
                <?php if ($link['form']): ?>
                <form method="POST" action="<?= htmlspecialchars($link['href']) ?>" class="m-0 p-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <button type="submit" class="admin-sidebar-link u-bare-button"
                            title="Browse the store as a visitor"><?= $link['label'] ?></button>
                </form>
                <?php else: ?>
                <a class="admin-sidebar-link <?= $link['class'] ?>" href="<?= htmlspecialchars($link['href']) ?>">
                    <span><?= $link['label'] ?></span>
                    <?php if ($link['badge'] > 0): ?>
                    <span class="counter-badge admin-sidebar-count"><?= $link['badge'] ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="admin-sidebar-foot">
            <a class="admin-sidebar-link" href="<?= URLROOT ?>/admin/my-info">👤 My Info</a>
            <?php if ($adminId === 1): ?>
            <a class="admin-sidebar-link" href="<?= URLROOT ?>/admin/backup">💾 Backup DB</a>
            <?php endif; ?>
            <a class="admin-sidebar-link text-danger" href="#" data-action="logout-admin">🚪 Log Out</a>
        </div>
    </div>
</div>

<?php // Data only — logoutAdmin() lives in js/admin/admin-layout/admin-navbar.js ?>
<?= pageData(['_csrfToken' => $csrf]) ?>

<main id="main-content" class="container-fluid py-4 px-4">
