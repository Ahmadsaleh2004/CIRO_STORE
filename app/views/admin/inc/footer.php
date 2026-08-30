</main>

<div class="admin-footer">
    Cairo Store Admin Panel © <?= date('Y') ?> — Logged in as
    <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?></strong>
    (Role <?= htmlspecialchars($_SESSION['admin_role'] ?? '') ?>)
</div>

<?php // ⚠️ First and without defer: it copies the page's data island onto window, and
     // everything below reads from it. Moving it later breaks every page passing data. ?>
<?= jsTag('js/core/page-data.js', false) ?>

<?php // jQuery was removed — unused. The URLs and integrity hashes live in assets_helper.php ?>
<?= vendorJs('bootstrap-js', false) ?>
<?= vendorJs('sweetalert2', false) ?>
<?php
// One bundle in place of twenty-one tags. The list is the fallback for when nothing is
// built, and its order is the contract — see jsBundle in assets_helper.php.
?>
<?= jsBundle('admin', [
    'js/core/inline-actions.js',
    'js/core/utils.js',
    'js/core/csrf.js',
    'js/core/ui.js',
    'js/core/flash-toast.js',
    'js/core/theme.js',
    'js/features/auth.js',
    'js/admin/products.js',
    'js/admin/branding.js',
    'js/admin/category-picker.js',
    'js/admin/orders.js',
    'js/admin/users.js',
    'js/admin/admins.js',
    'js/admin/manage-admins.js',
    'js/admin/admin-notifications.js',
    'js/admin/backup.js',
    'js/admin/support.js',
    'js/admin/site-settings.js',
    'js/shared/order-cancel.js',
    'js/admin/admin-layout/admin-navbar.js',
    'js/main.js',
], false) ?>

<?php if (isset($extraScripts)) echo $extraScripts; ?>

<?php // The admin notification sidebar — static HTML, wired to the backend by admin-notifications.js ?>
<div id="adminNotifSidebar" role="region" aria-label="Admin notifications panel">
    <div class="notif-header">
        <span>🔔 Admin Notifications</span>
        <div class="d-flex gap-2">
            <button id="adminNotifMarkAll" class="btn btn-sm btn-outline-theme">Mark All Read</button>
            <button id="adminNotifClose" class="btn btn-sm btn-outline-theme" aria-label="Close">✕</button>
        </div>
    </div>
    <ul id="adminNotifList" class="notif-list"><li class="notif-empty">No notifications yet</li></ul>
    <div class="p-2">
        <button id="adminNotifDeleteAll" class="btn btn-sm btn-outline-danger w-100">🗑️ Delete All</button>
    </div>
</div>

</body>
</html>
