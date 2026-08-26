<?php
/**
 * app/views/errors/403.php
 * صفحة «ليس لك صلاحية».
 *
 * توأم views/errors/404.php، ومن نفس السبب: كان الرد على الرفض
 * `die('Unauthorized — Root admin only (ID=1)')` — نصّاً خاماً بلا
 * <head> ولا لايوت ولا طريق رجوع، ويكشف قاعدة الصلاحية للزائر.
 *
 * تُستدعى من ErrorPage::forbidden() عبر require مباشر لا عبر view():
 * استدعاء view() من داخل معالج خطأ يعني تكراراً محتملاً لو كان الخطأ
 * في الـlayout نفسه.
 *
 * لا تطبع أبداً سبب الرفض التفصيلي: السبب في سجل أخطاء PHP وحده.
 *
 * المتغيرات (يضبطها ErrorPage::forbidden قبل الـrequire):
 *   $backUrl    string  رابط زر الرجوع
 *   $backLabel  string  نصّ زر الرجوع
 */

$bareTitle     = '403 — ' . SITENAME;
$bareThemeBoot = true;
$bareCss       = ['css/store.css'];

require APPROOT . '/views/inc/head-bare.php';
?>

<main class="container py-5 text-center" style="min-height:70vh">
    <p class="display-1 mb-2" aria-hidden="true">🔒</p>
    <h1 class="h3 mb-3">Access denied</h1>
    <p class="text-muted mb-4">
        ليس لديك صلاحية الوصول إلى هذه الصفحة.
    </p>
    <a class="btn btn-primary" href="<?= htmlspecialchars($backUrl) ?>">
        <?= htmlspecialchars($backLabel) ?>
    </a>
</main>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>
