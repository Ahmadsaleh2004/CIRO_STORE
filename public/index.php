<?php

// تحميل Autoloader الخاص بـ Composer (PHPMailer وغيره من الحزم الخارجية)
require_once __DIR__ . '/../vendor/autoload.php';

// تحميل ملف .env بأمان (قارئ سطر بسطر، بدون تفسير PHP لأي أقواس أو كلمات محجوزة)
require_once __DIR__ . '/../app/config/env_loader.php';
loadEnv(__DIR__ . '/../.env');

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/functions.php';

// تحميل بقية ملفات الـ helpers تلقائياً
foreach (glob(__DIR__ . '/../app/helpers/*.php') as $helperFile) {
    if (basename($helperFile) !== 'functions.php') {
        require_once $helperFile;
    }
}

// ملاحظة: لا حاجة لـautoloader يدوي هنا — composer.json يعرّف
// PSR-4 على "App\\" => app/ (راجع vendor/composer/autoload_psr4.php)
// وvendor/autoload.php المُحمَّل أعلاه يتكفّل بكل كلاسات App.

use App\Core\App;
use App\Controllers\HomeController;
use App\Controllers\ProductController;
use App\Controllers\AboutController;
use App\Controllers\ContactController;
use App\Controllers\WishlistController;
use App\Controllers\AuthController;
use App\Controllers\CartController;
use App\Controllers\CheckoutController;
use App\Controllers\MyInfoController;
use App\Controllers\NotificationController;
use App\Controllers\AdminAuthController;
use App\Controllers\AdminHomeController;
use App\Controllers\AdminMyInfoController;
use App\Controllers\AdminDashboardController;
use App\Controllers\AdminSupportController;
use App\Controllers\AdminSiteSettingsController;
use App\Controllers\AdminManageAdminsController;
use App\Controllers\AdminMessagingController;
use App\Controllers\AdminProductsController;
use App\Controllers\AdminUsersController;
use App\Controllers\AdminOrdersController;
use App\Controllers\AdminNotificationController;
use App\Controllers\AdminBrandingController;
use App\Controllers\BackupController;

$app = new App();
$r   = $app->getRouter();

// ── الصفحة الرئيسية ──────────────────────────────────────────
$r->get('/',     [HomeController::class, 'index']);
$r->get('/home', [HomeController::class, 'index']);

// ── المنتجات ─────────────────────────────────────────────────
$r->get('/products', [ProductController::class, 'index']);
$r->get('/product',  [ProductController::class, 'show']);
$r->post('/product', [ProductController::class, 'show']);

// ── الصفحات التعريفية ────────────────────────────────────────
$r->get('/about',   [AboutController::class,   'about']);
$r->get('/contact', [ContactController::class, 'contact']);
$r->post('/contact',[ContactController::class, 'contact']);
$r->post('/contact/send', [ContactController::class, 'send']);

// ── Wishlist ─────────────────────────────────────────────────
$r->get('/wishlist', [WishlistController::class, 'index']);
$r->get('/handlers/product_stock_handler.php', [WishlistController::class, 'stock']);
$r->post('/handlers/notify_handler.php',       [WishlistController::class, 'notify']);

// ── Auth ─────────────────────────────────────────────────────
$r->post('/auth/login',            [AuthController::class, 'login']);
$r->post('/auth/register',         [AuthController::class, 'register']);
$r->post('/auth/logout',           [AuthController::class, 'logout']);
$r->post('/auth/forgot',           [AuthController::class, 'forgot']);
$r->get('/auth/verify',            [AuthController::class, 'verifyEmail']);
$r->get('/auth/reset',             [AuthController::class, 'resetForm']);
$r->post('/auth/reset',            [AuthController::class, 'resetSubmit']);
$r->get('/auth/google',            [AuthController::class, 'googleLogin']);
$r->get('/auth/google/callback',   [AuthController::class, 'googleCallback']);
$r->get('/auth/csrf',              [AuthController::class, 'getCsrf']);

// ── Cart ─────────────────────────────────────────────────────
$r->post('/cart/check-stock', [CartController::class, 'checkStock']);

// ── Checkout ─────────────────────────────────────────────────
$r->get('/checkout',               [CheckoutController::class, 'index']);
$r->post('/checkout',              [CheckoutController::class, 'placeOrder']);
$r->post('/checkout/cancel-order', [CheckoutController::class, 'cancelOrder']);
$r->get('/checkout/confirmation',  [CheckoutController::class, 'confirmation']);

// ── My Info ──────────────────────────────────────────────────
$r->get('/user/info',              [MyInfoController::class, 'index']);
$r->post('/user/info',             [MyInfoController::class, 'updateProfile']);
$r->post('/user/addresses',        [MyInfoController::class, 'addAddress']);
$r->post('/user/addresses/delete', [MyInfoController::class, 'deleteAddress']);

// ── Notifications ────────────────────────────────────────────
$r->get('/notifications/list',          [NotificationController::class, 'list']);
$r->post('/notifications/mark-read',    [NotificationController::class, 'markRead']);
$r->post('/notifications/mark-all-read',[NotificationController::class, 'markAllRead']);
$r->post('/notifications/dismiss',      [NotificationController::class, 'dismiss']);
$r->post('/notifications/delete-all',   [NotificationController::class, 'deleteAll']);

// ── Admin Auth (مستقل تماماً عن Auth العام) ──────────────────
// ملاحظة: هذه المسارات تستخدم session_name('admin_session') منفصلة
// عن جلسة المستخدم العادي (PHPSESSID) — لا تخلطهما أبداً
$r->get('/admin/login',  [AdminAuthController::class, 'showLogin']);
$r->post('/admin/login', [AdminAuthController::class, 'login']);
$r->post('/admin/login/2fa', [AdminAuthController::class, 'verify2FALogin']);
$r->post('/admin/forgot',[AdminAuthController::class, 'forgotPassword']);
$r->post('/admin/logout',[AdminAuthController::class, 'logout']);
$r->get('/admin/csrf',   [AdminAuthController::class, 'getCsrf']);
$r->post('/admin/store-mode/enter',  [AdminAuthController::class, 'enterStoreMode']);
$r->get('/admin/store-mode/reauth',  [AdminAuthController::class, 'showReauth']);
$r->post('/admin/store-mode/reauth', [AdminAuthController::class, 'reauth']);
$r->get('/admin/home',     [AdminHomeController::class,   'index']);
$r->get('/admin/my-info',  [AdminMyInfoController::class, 'index']);
$r->post('/admin/my-info', [AdminMyInfoController::class, 'updateProfile']);
$r->post('/admin/my-info/2fa/generate', [AdminMyInfoController::class, 'generate2FASecret']);
$r->post('/admin/my-info/2fa/confirm',  [AdminMyInfoController::class, 'confirm2FA']);
$r->post('/admin/my-info/2fa/disable',  [AdminMyInfoController::class, 'disable2FA']);
$r->get('/admin/dashboard', [AdminDashboardController::class, 'index']);

// ── Admin Support ────────────────────────────────────────────
$r->get('/admin/support',         [AdminSupportController::class, 'index']);
$r->post('/admin/support/reply',  [AdminSupportController::class, 'reply']);
$r->post('/admin/support/delete', [AdminSupportController::class, 'delete']);

// ── Admin Site Settings ──────────────────────────────────────
$r->get('/admin/settings',  [AdminSiteSettingsController::class, 'index']);
$r->post('/admin/settings', [AdminSiteSettingsController::class, 'save']);

// ── Manage Admins ─────────────────────────────────────────────
$r->get('/admin/admins',            [AdminManageAdminsController::class, 'index']);
$r->get('/admin/admins/add',        [AdminManageAdminsController::class, 'showAdd']);
$r->post('/admin/admins/add',       [AdminManageAdminsController::class, 'storeAdd']);
$r->post('/admin/admins/edit',      [AdminManageAdminsController::class, 'storeEdit']);   // id بالـ body
$r->post('/admin/admins/delete',    [AdminManageAdminsController::class, 'delete']);      // JSON — AJAX
$r->get('/admin/admins/details',    [AdminManageAdminsController::class, 'details']);     // ?id=123
$r->get('/admin/admins/export-csv', [AdminManageAdminsController::class, 'exportCsv']);  // تحميل ملف — Role A فقط

// ── Manage Users ────────────────────────────────────────────
$r->get('/admin/users',                [AdminUsersController::class, 'index']);
$r->get('/admin/users/details',        [AdminUsersController::class, 'details']);
$r->post('/admin/users/delete',        [AdminUsersController::class, 'delete']);
$r->post('/admin/users/strikes/add',   [AdminUsersController::class, 'addStrike']);
$r->post('/admin/users/strikes/remove',[AdminUsersController::class, 'removeStrike']);
$r->get('/admin/users/export-csv',     [AdminUsersController::class, 'exportCsv']);

// ── Manage Orders ────────────────────────────────────────────
$r->get('/admin/orders',                  [AdminOrdersController::class, 'index']);
$r->get('/admin/orders/details',          [AdminOrdersController::class, 'details']);
$r->post('/admin/orders/take',            [AdminOrdersController::class, 'take']);
$r->post('/admin/orders/mark-delivered',  [AdminOrdersController::class, 'markDelivered']);
$r->post('/admin/orders/cancel-delivery', [AdminOrdersController::class, 'cancelDelivery']);
$r->post('/admin/orders/release',         [AdminOrdersController::class, 'release']);
$r->post('/admin/orders/delete',          [AdminOrdersController::class, 'delete']);
$r->post('/admin/orders/report-issue',    [AdminOrdersController::class, 'reportIssue']);
$r->get('/admin/orders/export-csv',       [AdminOrdersController::class, 'exportCsv']);

// ── Messaging مشترك (أدمن الآن، يوزرز لاحقًا بنفس الكنترولر) ──
$r->post('/admin/messaging/notify',    [AdminMessagingController::class, 'notify']);     // JSON — AJAX
$r->post('/admin/messaging/broadcast', [AdminMessagingController::class, 'broadcast']); // JSON — AJAX

// ── Manage Products ───────────────────────────────────────────
$r->get('/admin/products',                     [AdminProductsController::class, 'index']);
$r->get('/admin/products/add',                 [AdminProductsController::class, 'showAdd']);
$r->post('/admin/products/add',                [AdminProductsController::class, 'storeAdd']);
$r->get('/admin/products/edit',                [AdminProductsController::class, 'showEdit']);
$r->post('/admin/products/edit',               [AdminProductsController::class, 'storeEdit']);
$r->post('/admin/products/delete',             [AdminProductsController::class, 'delete']);
$r->post('/admin/products/toggle-visibility',  [AdminProductsController::class, 'toggleVisibility']);
$r->get('/admin/products/export-csv',          [AdminProductsController::class, 'exportCsv']);
$r->post('/admin/products/categories/suggest', [AdminProductsController::class, 'suggestCategory']);
$r->post('/admin/products/categories/add',     [AdminProductsController::class, 'addCategory']);
$r->post('/admin/products/categories/delete',  [AdminProductsController::class, 'deleteCategory']);

// ── Manage Branding / Home Slider ──────────────────────────────
$r->get('/admin/branding',                  [AdminBrandingController::class, 'index']);
$r->post('/admin/branding/save',            [AdminBrandingController::class, 'save']);
$r->get('/admin/branding/products/search',  [AdminBrandingController::class, 'searchProducts']);

// ── Admin Backup (Role A فقط) ────────────────────────────────
$r->get('/admin/backup',          [BackupController::class, 'index']);
$r->post('/admin/backup/create',  [BackupController::class, 'create']);
$r->get('/admin/backup/download', [BackupController::class, 'download']);
$r->post('/admin/backup/delete',  [BackupController::class, 'delete']);

// ── Admin Notifications ────────────────────────────────────────
$r->get('/admin/notifications/list',           [AdminNotificationController::class, 'list']);
$r->post('/admin/notifications/mark-read',     [AdminNotificationController::class, 'markRead']);
$r->post('/admin/notifications/mark-all-read', [AdminNotificationController::class, 'markAllRead']);
$r->post('/admin/notifications/delete-all',    [AdminNotificationController::class, 'deleteAll']);
$r->post('/admin/notifications/dismiss',       [AdminNotificationController::class, 'dismiss']);

// ── تشغيل التطبيق ────────────────────────────────────────────
$app->run();
