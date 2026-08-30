<?php
/**
 * app/views/inc/head-bare.php
 * <head> صفحات layout الـ'bare' — الصفحات المستقلة التي لا تحمّل
 * navbar المتجر ولا navbar الأدمن.
 *
 * وُجد هذا الملف لأن ثلاث صفحات كانت تكتب <!DOCTYPE html> و<head>
 * كاملين بيدها: admin/login و admin/store-reauth و auth/reset-password.
 * ثلاث نسخ من نفس الوسوم، تتفرّق كلما عُدِّلت واحدة وحدها.
 *
 * المتغيرات — كلها اختيارية عدا $bareTitle:
 *
 *   $bareTitle      string  عنوان الصفحة (يُهرَّب هنا)
 *   $bareLang       string  قيمة lang على <html>            (افتراضي 'en')
 *   $bareDir        string  قيمة dir على <html>             (افتراضي 'ltr')
 *   $bareBodyClass  string  كلاسات <body>                   (افتراضي '')
 *   $bareThemeBoot  bool    اطبع themeBootScript()          (افتراضي false)
 *   $bareSwal       bool    أدرج أنماط SweetAlert2          (افتراضي false)
 *   $bareCss        array   مسارات CSS نسبية لـURLROOT، بالترتيب
 *   $bareHead       string  HTML خام يُطبع آخر الترويسة (وسم meta إضافي،
 *                           أو كتلة أنماط خاصة بالصفحة)
 *
 * كل صفحات الـbare حتى الآن صفحات مصادقة، فوسم robots المانع للفهرسة
 * مطبوع هنا دائماً لا كخيار. أول صفحة bare تحتاج الفهرسة تحوّله لمتغيّر.
 */

$bareLang      = $bareLang      ?? 'en';
$bareDir       = $bareDir       ?? 'ltr';
$bareBodyClass = $bareBodyClass ?? '';
$bareThemeBoot = $bareThemeBoot ?? false;
$bareCss       = $bareCss       ?? [];

// صفحة bare واحدة اليوم تستدعي vendorJs('sweetalert2') — admin/login.
// وأنماط SweetAlert صارت ورقة خارجية لا حقناً من الجافاسكربت، فمن
// يُدرج السكربت يجب أن يُدرج الورقة، وإلّا ظهر الحوار نصّاً عارياً.
// والعَلَم هنا بدل الإدراج الدائم كي لا تحمل صفحات الأخطاء وإعادة
// تعيين كلمة المرور ورقةً لا تستعملها.
$bareSwal      = $bareSwal      ?? false;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($bareLang) ?>" dir="<?= htmlspecialchars($bareDir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php // منع الفهرسة — كل صفحات الـbare خاصة (مصادقة أو أخطاء) ?>
    <meta name="robots" content="noindex, nofollow">

    <title><?= htmlspecialchars($bareTitle ?? SITENAME) ?></title>
<?php if ($bareThemeBoot): ?>
<?= themeBootScript() ?>
<?php endif; ?>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<?= vendorCss('bootstrap-css') ?>
<?php if ($bareSwal): ?>
<?= vendorCss('sweetalert2-css') ?>
<?php endif; ?>
<?php foreach ($bareCss as $bareCssFile): ?>
    <link rel="stylesheet" href="<?= URLROOT ?>/<?= ltrim($bareCssFile, '/') ?>">
<?php endforeach; ?>
    <?= $bareHead ?? '' ?>
</head>
<body<?= $bareBodyClass !== '' ? ' class="' . htmlspecialchars($bareBodyClass) . '"' : '' ?>>
