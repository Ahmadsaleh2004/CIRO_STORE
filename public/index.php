<?php

// Loading Composer's autoloader.
//
// This one line loads three things at once:
//   1. the external packages (PHPMailer and the rest)
//   2. the project's classes through PSR-4 on "App\\" => app/
//   3. the six helper files through autoload.files in composer.json
//
// The helpers used to be loaded here with a glob over the helpers directory. They were
// moved into composer so the load list is declared in one place that dump-autoload -o can
// see, and so the load order does not depend on the file system's ordering.
//
// They are all function definitions and execute nothing on load, so loading them before
// config.php is safe — the constants (URLROOT and its siblings) are read inside the
// function bodies at call time rather than at definition time.
require_once __DIR__ . '/../vendor/autoload.php';

// Loading the .env file safely (a line-by-line reader, with no PHP interpretation of any
// braces or reserved words)
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
// The guards are declared on the route
// ══════════════════════════════════════════════════════════════
//
// Every protected route carries a ->middleware() declaring what it needs:
//
//     'auth'        → a signed-in user
//     'admin'       → a signed-in admin
//     'perm:<name>' → an admin holding the permission (rank A always overrides it)
//
// Why here rather than inside the actions alone? For two reasons:
//
//   1. The guard runs **before** the controller is constructed, not after. Calling it from
//      inside an action's body means the controller was built and may have done work in its
//      constructor before the permission was ever asked about.
//
//   2. The policy becomes readable in one place. Anyone reviewing the project's security
//      reads this table, rather than 24 controllers looking for a stray Middleware line.
//
// ⚠️ The checks inside the action bodies **were not removed**, and the duplication is
// deliberate and temporary: removing them in the same step would have turned any mistake in
// the move into a silent hole. With both in place, the new guard cannot be weaker than the
// old one.
//
// And tests/Integration/RouteGuardParityTest.php compares the two sides mechanically: any
// divergence — a route without a guard, or a permission changed on one side and not the
// other — fails the build.


$app = new App();
$r   = $app->getRouter();

// ── The home page ────────────────────────────────────────────
// A health check — called by HEALTHCHECK in the Dockerfile and by the load balancer.
// Necessarily unguarded: the checker holds no session.
$r->get('/health', [HealthController::class, 'index']);

$r->get('/',     [HomeController::class, 'index']);
$r->get('/home', [HomeController::class, 'index']);

// ── The products ─────────────────────────────────────────────
$r->get('/products', [ProductController::class, 'index']);
$r->get('/product',  [ProductController::class, 'show']);
$r->post('/product', [ProductController::class, 'show']);

// ── The informational pages ──────────────────────────────────
$r->get('/about',   [AboutController::class,   'about']);
$r->get('/contact', [ContactController::class, 'contact']);
$r->post('/contact',[ContactController::class, 'contact']);
$r->post('/contact/send', [ContactController::class, 'send'])
    ->middleware('throttle:contact,10,60');

// ── Wishlist ─────────────────────────────────────────────────
$r->get('/wishlist', [WishlistController::class, 'index']);
$r->get('/handlers/product_stock_handler.php', [WishlistController::class, 'stock']);
$r->post('/handlers/notify_handler.php',       [WishlistController::class, 'notify']);

// ── Auth ─────────────────────────────────────────────────────
//
// The throttle here counts **requests** from one source, which is a different thing from
// the throttle in UserModel::isRateLimited that counts failed sign-ins to one account.
// The two layers guard different things: that one protects a particular account from
// guessing, and this one protects the endpoint itself from exhaustion — the clearest
// example being /auth/forgot, where every call is "successful" from the failure counter's
// point of view while being an email.
//
// ⚠️ The numbers count the retry. js/core/csrf.js resends the request once automatically
// when the token expires, so every user action may arrive as two requests. Which is to say
// the limit shown here is roughly **half** of it in user actions: 12 on registration ≈ six
// real attempts. The CSRF contract test revealed this when it exhausted limits that had
// been set against requests rather than against actions.
//
// And the endpoints that send email are deliberately tighter (6/hour ≈ three attempts):
// they are the only ones where every call costs an actual message.
$r->post('/auth/login',            [AuthController::class, 'login'])
    ->middleware('throttle:store-login,10,15');
$r->post('/auth/register',         [AuthController::class, 'register'])
    ->middleware('throttle:store-register,12,60');
$r->post('/auth/logout',           [AuthController::class, 'logout']);
$r->post('/auth/forgot',           [AuthController::class, 'forgot'])
    ->middleware('throttle:store-forgot,6,60');
$r->get('/auth/verify',            [AuthController::class, 'verifyEmail']);
$r->get('/auth/reset',             [AuthController::class, 'resetForm']);
$r->post('/auth/reset',            [AuthController::class, 'resetSubmit'])
    ->middleware('throttle:store-reset,10,15');
$r->get('/auth/google',            [AuthController::class, 'googleLogin']);
$r->get('/auth/google/callback',   [AuthController::class, 'googleCallback']);
$r->get('/auth/csrf',              [AuthController::class, 'getCsrf']);

// ── Cart ─────────────────────────────────────────────────────
//
// The cart moved to the server (migration 0011). And every endpoint carries the `auth`
// guard because **there is no guest cart at all**: the cart button and the "add to cart"
// button are login-guarded in all three templates (navbar · product · product_dit), and a
// signed-out visitor is pushed to the login modal. So the guard here enforces what the
// interface shows rather than assuming it — the difference being that the interface hides
// the button while the guard refuses the request.
//
// And `check-stock` stays unguarded: it is called from the product pages to refresh their
// cards, and it discloses nothing that is not already on display.
$r->post('/cart/check-stock', [CartController::class, 'checkStock']);
$r->get('/cart',          [CartController::class, 'index'])->middleware('auth');
$r->post('/cart/add',     [CartController::class, 'add'])->middleware('auth');
$r->post('/cart/update',  [CartController::class, 'update'])->middleware('auth');
$r->post('/cart/remove',  [CartController::class, 'remove'])->middleware('auth');

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

// ── Admin Auth (entirely separate from the general Auth) ─────
// Note: these routes use session_name('admin_session'), separate from the ordinary user's
// session (PHPSESSID) — never mix the two
$r->get('/admin/login',  [AdminAuthController::class, 'showLogin']);
$r->post('/admin/login', [AdminAuthController::class, 'login'])
    ->middleware('throttle:admin-login,10,15');
// ⚠️ The most important throttle in the table. The 2FA step had no counter at all: the
// password has already passed, and the code is six digits with a ±30-second window — that
// is, three valid codes out of a million at any instant. Without a limit, whoever holds the
// password gets past the second layer with a guessing loop. The limit here is deliberately
// tighter than the sign-in's.
$r->post('/admin/login/2fa', [AdminAuthController::class, 'verify2FALogin'])
    ->middleware('throttle:admin-2fa,8,15');
$r->post('/admin/forgot',[AdminAuthController::class, 'forgotPassword'])
    ->middleware('throttle:admin-forgot,6,60');
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
    ->middleware('perm:can_manage_admins');   // the id comes in the body
$r->post('/admin/admins/delete',    [AdminManageAdminsController::class, 'delete'])
    ->middleware('perm:can_manage_admins');      // JSON — AJAX
$r->get('/admin/admins/details',    [AdminManageAdminsController::class, 'details'])
    ->middleware('perm:can_manage_admins');     // ?id=123
$r->get('/admin/admins/export-csv', [AdminManageAdminsController::class, 'exportCsv'])
    ->middleware('perm:can_manage_admins');  // a file download — Role A only

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

// ── Shared messaging (admins today, users later on the same controller) ──
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

// ── Admin Backup (the root admin alone — rank A) ─────────────
//
// These four routes had no declared guard at all, with the condition written by hand four
// times in the bodies as `getCurrentAdminId() !== 1` — that is, the right to download the
// entire database hung on a position in the id sequence rather than on a person. The `root`
// guard rests on rank A, which is an identity rather than a position.
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

// ── Running the application ──────────────────────────────────
$app->run();
