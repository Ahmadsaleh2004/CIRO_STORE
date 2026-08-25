</main>

<div class="admin-footer">
    Cairo Store Admin Panel © <?= date('Y') ?> — Logged in as
    <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?></strong>
    (Role <?= htmlspecialchars($_SESSION['admin_role'] ?? '') ?>)
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?= URLROOT ?>/js/core/utils.js"></script>
<script src="<?= URLROOT ?>/js/core/csrf.js"></script>
<script src="<?= URLROOT ?>/js/core/ui.js"></script>
<script src="<?= URLROOT ?>/js/core/flash-toast.js"></script>
<script src="<?= URLROOT ?>/js/core/theme.js"></script>
<script src="<?= URLROOT ?>/js/features/auth.js"></script>
<script src="<?= URLROOT ?>/js/admin/products.js"></script>
<script src="<?= URLROOT ?>/js/admin/branding.js"></script>
<script src="<?= URLROOT ?>/js/admin/category-picker.js"></script>
<script src="<?= URLROOT ?>/js/admin/orders.js"></script>
<script src="<?= URLROOT ?>/js/admin/users.js"></script>
<script src="<?= URLROOT ?>/js/admin/admins.js"></script>
<script src="<?= URLROOT ?>/js/admin/manage-admins.js"></script>
<script src="<?= URLROOT ?>/js/admin/admin-notifications.js"></script>
<script src="<?= URLROOT ?>/js/admin/backup.js"></script>
<script src="<?= URLROOT ?>/js/admin/support.js"></script>
<script src="<?= URLROOT ?>/js/admin/site-settings.js"></script>
<!-- Shared JS — زر إلغاء/حذف الطلب المشترك (admin order details + my-info) -->
<script src="<?= URLROOT ?>/js/shared/order-cancel.js"></script>
<script src="<?= URLROOT ?>/js/admin/admin-layout/admin-navbar.js"></script>
<script src="<?= URLROOT ?>/js/admin/admin-layout/admin-footer.js"></script>
<script src="<?= URLROOT ?>/js/main.js"></script>
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
