<?php
/**
 * app/views/errors/500.php
 * صفحة «الخدمة غير متاحة».
 *
 * تُستدعى من App\Core\ErrorPage::serverError() عبر require مباشر لا عبر
 * view() — لنفس سبب 404.php: استدعاء view() من داخل معالج خطأ يفتح باب
 * تكرار لا نهائي.
 *
 * ⚠️ أكثر ما تُستدعى له هذه الصفحة هو **فشل الاتصال بقاعدة البيانات**،
 * فلا تضف إليها أي شيء يقرأ من القاعدة: لا إعدادات موقع، ولا هوية
 * بصرية، ولا عدّاد سلّة. head-bare.php و footer-bare.php لا يلمسان
 * القاعدة (مفحوص)، وهذا هو سبب استعمالهما هنا.
 *
 * ولا تطبع أبداً رسالة الاستثناء: رسالة PDO تحوي اسم المضيف واسم
 * القاعدة واسم المستخدم. التفصيل في سجلّ الأخطاء وحده.
 */

$bareTitle     = 'Temporarily unavailable — ' . SITENAME;
// كانت هنا `$bareLang = 'ar'` و`$bareDir = 'rtl'` لأن نصّ الصفحة كان
// عربياً وحده — وهي الصفحة الوحيدة في المشروع التي كانت تقلب اتجاه
// المستند. النصّ صار إنجليزياً كبقيّة الواجهة، والافتراضي في
// head-bare.php (en/ltr) هو الصحيح لها الآن.
$bareThemeBoot = true;
$bareCss       = ['css/store.css'];

require APPROOT . '/views/inc/head-bare.php';
?>

<main class="container py-5 text-center u-minh-70vh">
    <p class="display-1 mb-2" aria-hidden="true">🛠️</p>
    <h1 class="h3 mb-3">Service temporarily unavailable</h1>
    <p class="text-muted mb-4">
        We are having a brief technical problem. Please try again in a moment.
    </p>
    <a class="btn btn-primary" href="<?= URLROOT ?>/">Try again</a>
</main>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>
