<?php
/**
 * app/views/inc/navbar.php
 * هذا الملف يحتوي على HTML فقط للقائمة العلوية المرئية.
 * الـ Controller هو من يتحقق من حالة التسجيل ويمرر المتغيرات جاهزة (مثل $data['userLoggedIn']).
 */
?>
<!-- نقل Skip to content و BASE_URL لداية الـ body لتنظيف ملف الـ head -->
<?= pageData(['BASE_URL' => URLROOT]) ?>
<a href="#main-content" class="skip-nav">Skip to main content</a>

<?php
// قراءة وضع Store Mode من جلسة الزائر (PHPSESSID).
// يُكتب هذا العلم من /admin/store-mode/enter في الجلستين معاً,
// والمتجر يقرأه هنا من جلسة الزائر فقط — الجلستان منفصلتان تماماً.
if (session_status() === PHP_SESSION_NONE) {
    session_name('PHPSESSID');
    session_start();
}
$adminInStoreMode = !empty($_SESSION['admin_in_store_mode']);
?>

<?php if ($adminInStoreMode): ?>
<!-- شريط وضع المتجر — يظهر للأدمن فقط أثناء تصفحه المتجر كزائر -->
<div class="container-fluid store-mode-bar">
    <span>👑 You are browsing the store as a guest</span>
    <a href="<?= URLROOT ?>/admin/store-mode/reauth" class="store-mode-return">
        🔐 Return to Admin Panel
    </a>
</div>
<?php endif; ?>

<nav class="navbar custom-navbar navbar-expand-lg" id="mainNavbar">
    <div class="container">
        <!-- استخدام URLROOT للروابط النظيفة (MVC style) -->
        <a class="navbar-brand fw-bold" href="<?= URLROOT ?>">🏪 Cairo Store</a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#navbarNav" aria-controls="navbarNav"
            aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mx-auto">
                <li class="nav-item">
                    <!-- الـ Controller سيمرر متغير $data['activePage'] لتحديد الصفحة النشطة -->
                    <a class="nav-link <?= (isset($data['activePage']) && $data['activePage'] == 'home') ? 'active fw-bold' : '' ?>" href="<?= URLROOT ?>">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (isset($data['activePage']) && $data['activePage'] == 'products') ? 'active fw-bold' : '' ?>" href="<?= URLROOT ?>/products">Products</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (isset($data['activePage']) && $data['activePage'] == 'about') ? 'active fw-bold' : '' ?>" href="<?= URLROOT ?>/about">About Us</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (isset($data['activePage']) && $data['activePage'] == 'contact') ? 'active fw-bold' : '' ?>" href="<?= URLROOT ?>/contact">Contact Us</a>
                </li>

                <!-- إذا كان المستخدم زائر، اعرض زر Log In -->
                <?php if (!isset($data['userLoggedIn']) || !$data['userLoggedIn']): ?>
                    <li class="nav-item">
                        <a class="nav-link fw-semibold" href="#"
                           data-bs-toggle="modal" data-bs-target="#loginModal">Log In</a>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="d-flex gap-2 align-items-center">
                <!-- Wishlist — متاحة للجميع -->
                <a href="<?= URLROOT ?>/wishlist"
                   class="btn btn-outline-danger position-relative" aria-label="Wishlist">
                    ❤️ <span id="wishlist-count" class="counter-badge" aria-live="polite">0</span>
                </a>

                <!-- Bell الإشعارات — تظهر فقط إذا كان المستخدم مسجلاً -->
                <?php if (isset($data['userLoggedIn']) && $data['userLoggedIn']): ?>
                <button id="notifBell" class="btn btn-outline-light position-relative"
                        aria-label="Notifications" title="Notifications" type="button">
                    🔔 <span id="notifBadge" class="counter-badge"
                             style="background:#ef4444;display:none;top:-4px;right:-4px;" aria-live="polite">0</span>
                </button>
                <?php endif; ?>

                <!-- Cart — تظهر فقط للمستخدم المسجل دخول -->
                <?php if (isset($data['userLoggedIn']) && $data['userLoggedIn']): ?>
                <button type="button"
                    class="btn btn-outline-warning position-relative"
                    data-bs-toggle="offcanvas" data-bs-target="#cartSidebar"
                    aria-controls="cartSidebar" aria-label="Shopping cart">
                    🛒 <span id="cart-count" class="counter-badge" aria-live="polite">0</span>
                </button>
                <?php endif; ?>

                <!-- Theme Toggle -->
                <button id="theme-toggle" class="btn btn-outline-light"
                        aria-label="Toggle theme" title="Toggle Theme">🌙</button>

                <!-- Dropdown المستخدم — يظهر فقط إذا كان المستخدم مسجلاً -->
                <?php if (isset($data['userLoggedIn']) && $data['userLoggedIn']): ?>
                <div class="dropdown">
                    <button class="btn btn-outline-light dropdown-toggle" type="button"
                        data-bs-toggle="dropdown" aria-expanded="false">
                        <!-- اسم المستخدم يأتي جاهزاً من الـ Controller -->
                        👤 <?= htmlspecialchars($data['userName']) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end"
                        style="background:var(--card-bg);border:1px solid var(--section-border);">
                        <li><a class="dropdown-item" href="<?= URLROOT ?>/user/info"
                               style="color:var(--text-color);">👤 My Info</a></li>
                        <li><a class="dropdown-item" href="<?= URLROOT ?>/contact"
                               style="color:var(--text-color);">💬 Contact Us</a></li>
                        <li><hr class="dropdown-divider" style="border-color:var(--section-border);"></li>
                        <!-- دالة الـ logoutUser() هي دالة JS معرفة في ملفات الـ JS الثابتة -->
                        <li><a class="dropdown-item text-danger" href="#"
                               data-action="logout-user">🚪 Log Out</a></li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<?php
/**
 * كان هنا <div id="main-content"></div> فارغ يعمل مرساةً لرابط
 * «تخطَّ إلى المحتوى». حُذف لأنه كان يكرّر معرّفاً موجوداً أصلاً:
 * كل views المتجر التسعة تعلن <main id="main-content"> بنفسها،
 * فكانت كل صفحة متجر تحمل عنصرين بنفس الـid — HTML غير صالح، و
 * getElementById يُرجع الـdiv الفارغ لا المحتوى.
 *
 * رابط التخطّي في head.php ما زال يشير إلى #main-content، وصار يصل
 * إلى <main> الحقيقي — وهو المقصود منه أصلاً.
 */
?>