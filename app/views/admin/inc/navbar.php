<?php
/**
 * app/views/admin/inc/navbar.php
 * المتغيرات المتاحة (تأتي تلقائياً من AdminController::adminView()):
 *   $adminName  — اسم الأدمن
 *   $adminRole  — رتبته (A/B/C/D)
 *   $adminId    — معرّفه
 *   $csrf       — توكن CSRF الحالي
 */
?>
<nav class="navbar custom-navbar navbar-expand-xl" id="mainNavbar">
    <div class="container-fluid px-3">
        <a class="navbar-brand fw-bold" href="<?= URLROOT ?>/admin/home">
            🏪 Cairo Store
            <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;vertical-align:middle;">ADMIN</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="adminNav">
            <ul class="navbar-nav mx-auto gap-1">

                <?php if (hasPermission('can_manage_admins')): ?>
                <li class="nav-item">
                    <!-- TODO: راوت /admin/admins لسا مو مسجل بـ Router.php -->
                    <a class="nav-link text-warning fw-semibold" href="<?= URLROOT ?>/admin/admins">👑 Admins</a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('can_view_dashboard')): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= URLROOT ?>/admin/dashboard">📊 Dashboard</a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('can_manage_products')): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= URLROOT ?>/admin/products">🛍️ Products</a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('can_manage_users')): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= URLROOT ?>/admin/users">👥 Users</a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('can_manage_support')): ?>
                <li class="nav-item position-relative">
                    <a class="nav-link" href="<?= URLROOT ?>/admin/support">
                        💬 Support
                        <?php if ($newMessages > 0): ?>
                        <span class="counter-badge" style="top:-4px;right:-8px;"><?= $newMessages ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('can_manage_orders')): ?>
                <li class="nav-item position-relative">
                    <a class="nav-link" href="<?= URLROOT ?>/admin/orders">
                        📦 Orders
                        <?php if ($newOrders > 0): ?>
                        <span class="counter-badge" style="top:-4px;right:-8px;"><?= $newOrders ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('can_edit_site_content')): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= URLROOT ?>/admin/settings">⚙️ Site Configuration</a>
                </li>
                <?php endif; ?>

                <?php if (hasPermission('can_manage_branding')): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= URLROOT ?>/admin/branding">🎬 Slider</a>
                </li>
                <?php endif; ?>

                <li class="nav-item">
                    <!-- دخول وضع تصفح المتجر كزائر — POST مع CSRF -->
                    <form method="POST" action="<?= URLROOT ?>/admin/store-mode/enter" class="d-inline m-0 p-0">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <button type="submit" class="nav-link btn btn-link p-0"
                                title="تصفح المتجر كزائر" style="border:0;background:none;">🌐 Store</button>
                    </form>
                </li>

            </ul>

            <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end mt-2 mt-lg-0">
                <button id="theme-toggle" class="btn btn-outline-light" title="Toggle Theme">🌙</button>

                <!-- جرس إشعارات الأدمن — مربوط بـ admin-notifications.js + /admin/notifications/* -->
                <button id="adminNotifBell" class="btn btn-outline-light position-relative me-2"
                        type="button" aria-label="Admin Notifications" title="Notifications">
                    🔔 <span id="adminNotifBadge" class="counter-badge"
                             style="background:#ef4444;display:none;top:-4px;right:-4px;" aria-live="polite">0</span>
                </button>

                <div class="dropdown">
                    <button class="btn btn-outline-warning dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        👑 <?= htmlspecialchars($adminName) ?>
                        <span class="badge bg-dark ms-1" style="font-size:.6rem;"><?= htmlspecialchars($adminRole) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end"
                        style="background:var(--card-bg);border:1px solid var(--section-border);">
        <!-- My Info للأدمن — متاح الآن عبر AdminMyInfoController -->
                        <li><a class="dropdown-item" href="<?= URLROOT ?>/admin/my-info"
                               style="color:var(--text-color);">👤 My Info</a></li>
                        <?php if ($adminId === 1): ?>
                        <li><a class="dropdown-item" href="<?= URLROOT ?>/admin/backup"
                               style="color:var(--text-color);">💾 Backup DB</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider" style="border-color:var(--section-border);"></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="logoutAdmin()">🚪 Log Out</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- تمرير بيانات فقط — logoutAdmin() في js/admin/admin-layout/admin-navbar.js -->
<script>
window._csrfToken = <?= json_encode($csrf) ?>;
</script>

<main id="main-content" class="container-fluid py-4 px-4">
