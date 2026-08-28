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

$bareTitle     = 'خطأ مؤقت — ' . SITENAME;
$bareLang      = 'ar';
$bareDir       = 'rtl';
$bareThemeBoot = true;
$bareCss       = ['css/store.css'];

require APPROOT . '/views/inc/head-bare.php';
?>

<main class="container py-5 text-center u-minh-70vh">
    <p class="display-1 mb-2" aria-hidden="true">🛠️</p>
    <h1 class="h3 mb-3">الخدمة غير متاحة مؤقتاً</h1>
    <p class="text-muted mb-4">
        نواجه عطلاً تقنياً قصيراً. حاول مرّة أخرى بعد قليل.
    </p>
    <a class="btn btn-primary" href="<?= URLROOT ?>/">إعادة المحاولة</a>
</main>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>
