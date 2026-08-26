<?php

// ==========================================
// 1. إعدادات البيئة وتتبع الأخطاء (Error Reporting)
// ==========================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ==========================================
// 2. الثوابت الأساسية للمسارات (Path Constants)
// ==========================================

// مسار مجلد app الرئيسي على القرص الصلب (App Root)
define('APPROOT', dirname(__DIR__));

// مسار المجلد الرئيسي للمشروع (Project Root)
define('ROOTPATH', dirname(dirname(__DIR__)));

// رابط الموقع الرئيسي الذي يصل إليه المتصفح (URL Root)
define('URLROOT', 'http://localhost/STORE/public');

// اسم المتجر
define('SITENAME', 'Cairo Store');

// ==========================================
// 3. إعدادات قاعدة البيانات (Database Config)
// ==========================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ciro_db');
define('DB_CHARSET', 'utf8mb4');

// ==========================================
// 4. تصليب الجلسة (Session Hardening)
// ==========================================
//
// مكانها هنا لا في auth_helper: كلا الإعدادين يجب أن يُضبط **قبل** أي
// session_start()، والجلسات تبدأ من مواضع كثيرة (startAdminSession،
// وسطور session_start مباشرة في AdminAuthController، وجلسة المتجر).
// وconfig.php يُحمَّل من public/index.php قبل الراوتر ومن كل سكربت في
// scripts/ — فهو الموضع الوحيد المضمون قبل الجميع.

// معرّف جلسة لم يولّده الخادم يُرفض ويُستبدل. بدونه يستطيع مهاجم أن
// يزرع معرّفاً يعرفه (عبر رابط أو كوكي على نطاق فرعي) ثم ينتظر الضحية
// لتسجّل الدخول به — تثبيت الجلسة (Session Fixation).
ini_set('session.use_strict_mode', '1');

// secure يُضبط حسب البروتوكول الفعلي: تثبيته true على http يمنع إرسال
// الكوكي أصلاً فتنكسر الجلسة كلها على بيئة التطوير المحلية.
$httpsOn = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',

    // httponly: صفر موضع في طبقة الـJS يقرأ document.cookie (مفحوص)،
    // فإخفاء الكوكي عن السكربتات لا يكسر شيئاً ويقطع سرقة الجلسة
    // بأي XSS مستقبلي.
    'httponly' => true,

    // samesite: Lax **لا Strict** عن قصد. Strict يمنع إرسال الكوكي مع
    // أي تنقّل قادم من موقع آخر — وعودة Google OAuth
    // (/auth/google/callback) هي بالضبط تنقّل من نطاق google.com،
    // فكانت الجلسة ستصل فارغة ويفشل تسجيل الدخول بجوجل. وLax يسمح
    // بذلك للتنقّلات العليا بـGET وحدها، ويمنع الطلبات العابرة
    // للمواقع بـPOST — وهي مسار CSRF الفعلي.
    'samesite' => 'Lax',

    'secure'   => $httpsOn,
]);
