<?php
/**
 * app/views/errors/404.php
 * صفحة «الصفحة غير موجودة».
 *
 * تُستدعى من Controller::renderViewNotFound() عبر require مباشر لا عبر
 * view() — استدعاء view() من داخل معالج خطأ الـview يعني تكراراً لا
 * نهائياً لو كان الملف الغائب هو هذه الصفحة نفسها.
 *
 * لا تطبع أبداً مسار الملف الغائب: المسار في سجل أخطاء PHP وحده.
 */

$bareTitle     = '404 — ' . SITENAME;
$bareThemeBoot = true;
$bareCss       = ['css/store.css'];

require APPROOT . '/views/inc/head-bare.php';
?>

<main class="container py-5 text-center u-minh-70vh">
    <p class="display-1 mb-2" aria-hidden="true">🧭</p>
    <h1 class="h3 mb-3">Page not found</h1>
    <p class="text-muted mb-4">
        الصفحة التي طلبتها غير موجودة أو تم نقلها.
    </p>
    <a class="btn btn-primary" href="<?= URLROOT ?>/">العودة للصفحة الرئيسية</a>
</main>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>
