</main>

<div class="admin-footer">
    Cairo Store Admin Panel © <?= date('Y') ?> — Logged in as
    <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?></strong>
    (Role <?= htmlspecialchars($_SESSION['admin_role'] ?? '') ?>)
</div>

<?php // ⚠️ أوّلاً وبلا defer: ينسخ جزيرة بيانات الصفحة إلى window،
     // وكل ما تحته يقرأ منها. نقله لاحقاً يكسر كل صفحة تمرّر بيانات. ?>
<?= jsTag('js/core/page-data.js', false) ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php
// حزمة واحدة بدل واحد وعشرين وسماً. القائمة هي الارتداد عند غياب
// البناء، وترتيبها هو العقد — راجع jsBundle في assets_helper.php.
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

<!-- سايدبار إشعارات الأدمن — HTML ثابت، يربطه بالباك اند admin-notifications.js -->
<div id="adminNotifSidebar" role="region" aria-label="Admin notifications panel">
    <div class="notif-header">
        <span>🔔 Admin Notifications</span>
        <div class="d-flex gap-2">
            <button id="adminNotifMarkAll" class="btn btn-sm btn-outline-theme">Mark All Read</button>
            <button id="adminNotifClose" class="btn btn-sm btn-outline-theme" aria-label="Close">✕</button>
        </div>
    </div>
    <ul id="adminNotifList" class="notif-list"><li class="notif-empty">لا يوجد إشعارات بعد</li></ul>
    <div class="p-2">
        <button id="adminNotifDeleteAll" class="btn btn-sm btn-outline-danger w-100">🗑️ Delete All</button>
    </div>
</div>

</body>
</html>
