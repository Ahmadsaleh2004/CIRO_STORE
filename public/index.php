<?php

// تحميل Autoloader الخاص بـ Composer.
//
// هذا السطر يحمّل ثلاثة أشياء دفعةً واحدة:
//   1. الحزم الخارجية (PHPMailer وغيرها)
//   2. كلاسات المشروع عبر PSR-4 على "App\\" => app/
//   3. ملفات الهيلبرز الستة عبر autoload.files في composer.json
//
// الهيلبرز كانت تُحمَّل هنا بـglob على مجلد helpers. نُقلت إلى composer
// كي تصير قائمة التحميل معلنة في مكان واحد يراه dump-autoload -o، وكي
// لا يعتمد ترتيب التحميل على ترتيب نظام الملفات.
//
// كلها تعريفات دوال فقط ولا تنفّذ شيئاً عند التحميل، فتحميلها قبل
// config.php آمن — الثوابت (URLROOT وأخواتها) تُقرأ داخل أجسام الدوال
// وقت الاستدعاء لا وقت التعريف.
require_once __DIR__ . '/../vendor/autoload.php';

// تحميل ملف .env بأمان (قارئ سطر بسطر، بدون تفسير PHP لأي أقواس أو كلمات محجوزة)
require_once __DIR__ . '/../app/config/env_loader.php';
loadEnv(__DIR__ . '/../.env');

require_once __DIR__ . '/../app/config/config.php';

use App\Core\App;
use App\Controllers\HealthController;
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

// ══════════════════════════════════════════════════════════════
// الحراسة مُعلَنة في المسار
// ══════════════════════════════════════════════════════════════
//
// كل مسار محمي يحمل ->middleware() يعلن ما يحتاجه:
//
//     'auth'       → مستخدم مسجّل الدخول
//     'admin'      → أدمن مسجّل الدخول
//     'perm:<اسم>' → أدمن يملك الصلاحية (رتبة A تتجاوزها دائماً)
//
// لماذا هنا لا داخل الأفعال وحدها؟ لسببين:
//
//   1. الحارس يعمل **قبل** بناء الكنترولر لا بعده. النداء من داخل جسم
//      الفعل يعني أن الكنترولر بُني وربما نفّذ عملاً في بانيه قبل أن
//      يُسأل عن الصلاحية.
//
//   2. السياسة تصير مقروءة في مكان واحد. من يراجع أمان المشروع يقرأ
//      هذا الجدول، لا 24 كنترولراً بحثاً عن سطر Middleware ضائع.
//
// ⚠️ الفحوص داخل أجسام الأفعال **لم تُحذف**، والازدواج مقصود ومؤقّت:
// حذفها في الخطوة نفسها كان سيجعل أي خطأ في النقل ثغرةً صامتة. وبإبقاء
// الاثنين لا يمكن للحارس الجديد أن يكون أضعف من القديم.
//
// و tests/Integration/RouteGuardParityTest.php يقارن الطرفين آلياً:
// أي انحراف — مسار بلا حارس، أو صلاحية تغيّرت في جانب دون الآخر —
// يُفشل البناء.


$app = new App();
$r   = $app->getRouter();

// ── الصفحة الرئيسية ──────────────────────────────────────────
// فحص صحّة — يستدعيه HEALTHCHECK في Dockerfile ودوّار الحمل.
// بلا حارس بالضرورة: الفاحص لا يملك جلسة.
$r->get('/health', [HealthController::class, 'index']);

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
$r->post('/contact/send', [ContactController::class, 'send'])
    ->middleware('throttle:contact,5,60');

// ── Wishlist ─────────────────────────────────────────────────
$r->get('/wishlist', [WishlistController::class, 'index']);
$r->get('/handlers/product_stock_handler.php', [WishlistController::class, 'stock']);
$r->post('/handlers/notify_handler.php',       [WishlistController::class, 'notify']);

// ── Auth ─────────────────────────────────────────────────────
//
// الخنق هنا يعدّ **الطلبات** من مصدر واحد، وهو غير الخنق القائم في
// UserModel::isRateLimited الذي يعدّ إخفاقات الدخول إلى حساب واحد.
// الطبقتان تحرسان شيئين مختلفين: تلك تحمي حساباً بعينه من التخمين،
// وهذه تحمي النقطة نفسها من الاستنزاف — وأوضح مثال /auth/forgot، إذ
// كل استدعاء لها «ناجح» من زاوية عدّاد الإخفاقات بينما هو رسالة بريد.
$r->post('/auth/login',            [AuthController::class, 'login'])
    ->middleware('throttle:store-login,10,15');
$r->post('/auth/register',         [AuthController::class, 'register'])
    ->middleware('throttle:store-register,5,60');
$r->post('/auth/logout',           [AuthController::class, 'logout']);
$r->post('/auth/forgot',           [AuthController::class, 'forgot'])
    ->middleware('throttle:store-forgot,5,60');
$r->get('/auth/verify',            [AuthController::class, 'verifyEmail']);
$r->get('/auth/reset',             [AuthController::class, 'resetForm']);
$r->post('/auth/reset',            [AuthController::class, 'resetSubmit'])
    ->middleware('throttle:store-reset,10,15');
$r->get('/auth/google',            [AuthController::class, 'googleLogin']);
$r->get('/auth/google/callback',   [AuthController::class, 'googleCallback']);
$r->get('/auth/csrf',              [AuthController::class, 'getCsrf']);

// ── Cart ─────────────────────────────────────────────────────
$r->post('/cart/check-stock', [CartController::class, 'checkStock']);

// ── Checkout ─────────────────────────────────────────────────
$r->get('/checkout',               [CheckoutController::class, 'index'])
    ->middleware('auth');
$r->post('/checkout',              [CheckoutController::class, 'placeOrder'])
    ->middleware('auth');
$r->post('/checkout/cancel-order', [CheckoutController::class, 'cancelOrder'])
    ->middleware('auth');
$r->get('/checkout/confirmation',  [CheckoutController::class, 'confirmation'])
    ->middleware('auth');

// ── My Info ──────────────────────────────────────────────────
$r->get('/user/info',              [MyInfoController::class, 'index'])
    ->middleware('auth');
$r->post('/user/info',             [MyInfoController::class, 'updateProfile'])
    ->middleware('auth');
$r->post('/user/addresses',        [MyInfoController::class, 'addAddress'])
    ->middleware('auth');
$r->post('/user/addresses/delete', [MyInfoController::class, 'deleteAddress'])
    ->middleware('auth');

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
$r->post('/admin/login', [AdminAuthController::class, 'login'])
    ->middleware('throttle:admin-login,10,15');
// ⚠️ أهمّ خنق في الجدول. خطوة الـ2FA كانت بلا أي عدّاد: كلمة المرور
// عبرت أصلاً، والكود ست خانات بنافذة ±30 ثانية — أي ثلاثة أكواد صالحة
// من مليون في كل لحظة. بلا حدّ، من يملك كلمة المرور يتجاوز الطبقة
// الثانية بحلقة تخمين. الحدّ هنا أضيق من الدخول عمداً.
$r->post('/admin/login/2fa', [AdminAuthController::class, 'verify2FALogin'])
    ->middleware('throttle:admin-2fa,8,15');
$r->post('/admin/forgot',[AdminAuthController::class, 'forgotPassword'])
    ->middleware('throttle:admin-forgot,5,60');
$r->post('/admin/logout',[AdminAuthController::class, 'logout']);
$r->get('/admin/csrf',   [AdminAuthController::class, 'getCsrf']);
$r->post('/admin/store-mode/enter',  [AdminAuthController::class, 'enterStoreMode']);
$r->get('/admin/store-mode/reauth',  [AdminAuthController::class, 'showReauth']);
$r->post('/admin/store-mode/reauth', [AdminAuthController::class, 'reauth'])
    ->middleware('throttle:admin-reauth,10,15');
$r->get('/admin/home',     [AdminHomeController::class,   'index']);
$r->get('/admin/my-info',  [AdminMyInfoController::class, 'index']);
$r->post('/admin/my-info', [AdminMyInfoController::class, 'updateProfile']);
$r->post('/admin/my-info/2fa/generate', [AdminMyInfoController::class, 'generate2FASecret']);
$r->post('/admin/my-info/2fa/confirm',  [AdminMyInfoController::class, 'confirm2FA']);
$r->post('/admin/my-info/2fa/disable',  [AdminMyInfoController::class, 'disable2FA']);
$r->get('/admin/dashboard', [AdminDashboardController::class, 'index'])
    ->middleware('perm:can_view_dashboard');

// ── Admin Support ────────────────────────────────────────────
$r->get('/admin/support',         [AdminSupportController::class, 'index'])
    ->middleware('perm:can_manage_support');
$r->post('/admin/support/reply',  [AdminSupportController::class, 'reply'])
    ->middleware('perm:can_manage_support');
$r->post('/admin/support/delete', [AdminSupportController::class, 'delete'])
    ->middleware('perm:can_manage_support');

// ── Admin Site Settings ──────────────────────────────────────
$r->get('/admin/settings',  [AdminSiteSettingsController::class, 'index'])
    ->middleware('perm:can_edit_site_content');
$r->post('/admin/settings', [AdminSiteSettingsController::class, 'save'])
    ->middleware('perm:can_edit_site_content');

// ── Manage Admins ─────────────────────────────────────────────
$r->get('/admin/admins',            [AdminManageAdminsController::class, 'index'])
    ->middleware('perm:can_manage_admins');
$r->get('/admin/admins/add',        [AdminManageAdminsController::class, 'showAdd'])
    ->middleware('perm:can_manage_admins');
$r->post('/admin/admins/add',       [AdminManageAdminsController::class, 'storeAdd'])
    ->middleware('perm:can_manage_admins');
$r->post('/admin/admins/edit',      [AdminManageAdminsController::class, 'storeEdit'])
    ->middleware('perm:can_manage_admins');   // id بالـ body
$r->post('/admin/admins/delete',    [AdminManageAdminsController::class, 'delete'])
    ->middleware('perm:can_manage_admins');      // JSON — AJAX
$r->get('/admin/admins/details',    [AdminManageAdminsController::class, 'details'])
    ->middleware('perm:can_manage_admins');     // ?id=123
$r->get('/admin/admins/export-csv', [AdminManageAdminsController::class, 'exportCsv'])
    ->middleware('perm:can_manage_admins');  // تحميل ملف — Role A فقط

// ── Manage Users ────────────────────────────────────────────
$r->get('/admin/users',                [AdminUsersController::class, 'index'])
    ->middleware('perm:can_manage_users');
$r->get('/admin/users/details',        [AdminUsersController::class, 'details'])
    ->middleware('perm:can_manage_users');
$r->post('/admin/users/delete',        [AdminUsersController::class, 'delete'])
    ->middleware('perm:can_manage_users');
$r->post('/admin/users/strikes/add',   [AdminUsersController::class, 'addStrike'])
    ->middleware('perm:can_manage_users');
$r->post('/admin/users/strikes/remove',[AdminUsersController::class, 'removeStrike'])
    ->middleware('perm:can_manage_users');
$r->get('/admin/users/export-csv',     [AdminUsersController::class, 'exportCsv'])
    ->middleware('perm:can_manage_users');

// ── Manage Orders ────────────────────────────────────────────
$r->get('/admin/orders',                  [AdminOrdersController::class, 'index'])
    ->middleware('perm:can_manage_orders');
$r->get('/admin/orders/details',          [AdminOrdersController::class, 'details'])
    ->middleware('perm:can_manage_orders');
$r->post('/admin/orders/take',            [AdminOrdersController::class, 'take'])
    ->middleware('perm:can_manage_orders');
$r->post('/admin/orders/mark-delivered',  [AdminOrdersController::class, 'markDelivered'])
    ->middleware('perm:can_manage_orders');
$r->post('/admin/orders/cancel-delivery', [AdminOrdersController::class, 'cancelDelivery'])
    ->middleware('perm:can_manage_orders');
$r->post('/admin/orders/release',         [AdminOrdersController::class, 'release'])
    ->middleware('perm:can_manage_orders');
$r->post('/admin/orders/delete',          [AdminOrdersController::class, 'delete'])
    ->middleware('perm:can_manage_orders');
$r->post('/admin/orders/report-issue',    [AdminOrdersController::class, 'reportIssue'])
    ->middleware('perm:can_manage_orders');
$r->get('/admin/orders/export-csv',       [AdminOrdersController::class, 'exportCsv'])
    ->middleware('perm:can_manage_orders');

// ── Messaging مشترك (أدمن الآن، يوزرز لاحقًا بنفس الكنترولر) ──
$r->post('/admin/messaging/notify',    [AdminMessagingController::class, 'notify'])
    ->middleware('perm:can_manage_admins');     // JSON — AJAX
$r->post('/admin/messaging/broadcast', [AdminMessagingController::class, 'broadcast']); // JSON — AJAX

// ── Manage Products ───────────────────────────────────────────
$r->get('/admin/products',                     [AdminProductsController::class, 'index'])
    ->middleware('perm:can_manage_products');
$r->get('/admin/products/add',                 [AdminProductsController::class, 'showAdd'])
    ->middleware('perm:can_manage_products');
$r->post('/admin/products/add',                [AdminProductsController::class, 'storeAdd'])
    ->middleware('perm:can_manage_products');
$r->get('/admin/products/edit',                [AdminProductsController::class, 'showEdit'])
    ->middleware('perm:can_manage_products');
$r->post('/admin/products/edit',               [AdminProductsController::class, 'storeEdit'])
    ->middleware('perm:can_manage_products');
$r->post('/admin/products/delete',             [AdminProductsController::class, 'delete'])
    ->middleware('perm:can_manage_products');
$r->post('/admin/products/toggle-visibility',  [AdminProductsController::class, 'toggleVisibility'])
    ->middleware('perm:can_manage_products');
$r->get('/admin/products/export-csv',          [AdminProductsController::class, 'exportCsv'])
    ->middleware('perm:can_manage_products');
$r->post('/admin/products/categories/suggest', [AdminProductsController::class, 'suggestCategory'])
    ->middleware('perm:can_manage_products');
$r->post('/admin/products/categories/add',     [AdminProductsController::class, 'addCategory'])
    ->middleware('perm:can_manage_products');
$r->post('/admin/products/categories/delete',  [AdminProductsController::class, 'deleteCategory'])
    ->middleware('perm:can_manage_products');

// ── Manage Branding / Home Slider ──────────────────────────────
$r->get('/admin/branding',                  [AdminBrandingController::class, 'index'])
    ->middleware('perm:can_manage_branding');
$r->post('/admin/branding/save',            [AdminBrandingController::class, 'save'])
    ->middleware('perm:can_manage_branding');
$r->get('/admin/branding/products/search',  [AdminBrandingController::class, 'searchProducts'])
    ->middleware('perm:can_manage_branding');

// ── Admin Backup (الروت وحده — رتبة A) ───────────────────────
//
// كانت هذه المسارات الأربعة بلا حارس مُعلَن إطلاقاً، والشرط مكتوباً
// بيده أربع مرّات في الجسم كـ`getCurrentAdminId() !== 1` — أي أن حقّ
// تنزيل قاعدة البيانات كاملةً كان معلَّقاً بموضعٍ في طابور المعرّفات لا
// بشخص. الحارس `root` يعتمد رتبة A، وهي هوية لا موضع.
$r->get('/admin/backup',          [BackupController::class, 'index'])
    ->middleware('root');
$r->post('/admin/backup/create',  [BackupController::class, 'create'])
    ->middleware('root');
$r->get('/admin/backup/download', [BackupController::class, 'download'])
    ->middleware('root');
$r->post('/admin/backup/delete',  [BackupController::class, 'delete'])
    ->middleware('root');

// ── Admin Notifications ────────────────────────────────────────
$r->get('/admin/notifications/list',           [AdminNotificationController::class, 'list']);
$r->post('/admin/notifications/mark-read',     [AdminNotificationController::class, 'markRead']);
$r->post('/admin/notifications/mark-all-read', [AdminNotificationController::class, 'markAllRead']);
$r->post('/admin/notifications/delete-all',    [AdminNotificationController::class, 'deleteAll']);
$r->post('/admin/notifications/dismiss',       [AdminNotificationController::class, 'dismiss']);

// ── تشغيل التطبيق ────────────────────────────────────────────
$app->run();
