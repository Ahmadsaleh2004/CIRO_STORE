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
<?= jsTag('js/core/inline-actions.js', false) ?>
<?= jsTag('js/core/utils.js', false) ?>
<?= jsTag('js/core/csrf.js', false) ?>
<?= jsTag('js/core/ui.js', false) ?>
<?= jsTag('js/core/flash-toast.js', false) ?>
<?= jsTag('js/core/theme.js', false) ?>
<?= jsTag('js/features/auth.js', false) ?>
<?= jsTag('js/admin/products.js', false) ?>
<?= jsTag('js/admin/branding.js', false) ?>
<?= jsTag('js/admin/category-picker.js', false) ?>
<?= jsTag('js/admin/orders.js', false) ?>
<?= jsTag('js/admin/users.js', false) ?>
<?= jsTag('js/admin/admins.js', false) ?>
<?= jsTag('js/admin/manage-admins.js', false) ?>
<?= jsTag('js/admin/admin-notifications.js', false) ?>
<?= jsTag('js/admin/backup.js', false) ?>
<?= jsTag('js/admin/support.js', false) ?>
<?= jsTag('js/admin/site-settings.js', false) ?>
<!-- Shared JS — زر إلغاء/حذف الطلب المشترك (admin order details + my-info) -->
<?= jsTag('js/shared/order-cancel.js', false) ?>
<?= jsTag('js/admin/admin-layout/admin-navbar.js', false) ?>
<!-- حُذف admin-layout/admin-footer.js: كان يربط أزرار الإشعارات الثلاثة
     التي يربطها admin-notifications.js فعلاً — زرّان منه كعبان فارغان لا
     يفعلان إلا console.log، وزر الإغلاق نسخة ناقصة لا تُخفي الـbackdrop. -->
<?= jsTag('js/main.js', false) ?>
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
