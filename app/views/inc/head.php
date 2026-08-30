<?php
/**
 * app/views/inc/head.php
 * هذا الملف يحتوي على <head> الـ HTML فقط.
 * البيانات (مثل العنوان والوصف) تأتي جاهزة من الـ Controller ويتم طباعتها هنا.
 */
?>
<!DOCTYPE html>
<?php // الـ Controller سيمرر لغة واتجاه الصفحة (مثلاً en/ltr أو ar/rtl) ?>
<html lang="<?= htmlspecialchars($data['htmlLang'] ?? 'en') ?>" dir="<?= htmlspecialchars($data['htmlDir'] ?? 'ltr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <?php // تأكد أن الـ Controller يمرر متغيراً اسمه $data['title'] ?>
    <title><?= htmlspecialchars($data['title'] ?? 'Cairo Store') ?></title>
    
    <?php // Meta Tags الخاصة بـ SEO ?>
    <meta name="description" content="<?= htmlspecialchars($data['desc'] ?? 'Cairo Store Best Electronics') ?>">
    <meta name="robots" content="<?= htmlspecialchars($data['robots'] ?? 'index, follow') ?>">
    
    <?php // Meta Tags الخاصة بـ Social Media (Open Graph & Twitter) ?>
    <?php if (isset($data['pageImage']) && $data['pageImage']): ?>
    <meta property="og:image" content="<?= htmlspecialchars($data['pageImage']) ?>">
    <?php endif; ?>
    <meta property="og:title"       content="<?= htmlspecialchars($data['title'] ?? 'Cairo Store') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($data['desc'] ?? 'Cairo Store Best Electronics') ?>">
    <meta property="og:type"        content="website">
    <meta name="twitter:card"       content="summary_large_image">
    <meta name="twitter:title"      content="<?= htmlspecialchars($data['title'] ?? 'Cairo Store') ?>">
    <meta name="twitter:description"content="<?= htmlspecialchars($data['desc'] ?? 'Cairo Store Best Electronics') ?>">
    
    <?php /*
ضبط الثيم قبل أول رسم — يمنع الومضة البيضاء ويجعل مكوّنات
         Bootstrap تقرأ الوضع الليلي. راجع assets_helper.php.
*/ ?>
<?= themeBootScript() ?>
    <?php // روابط ملفات الـ CSS الخارجية والداخلية ?>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<?= vendorCss('bootstrap-css') ?>
    <?php /*
أنماط SweetAlert2 — ورقة خارجية لا حقنٌ من الجافاسكربت.
         تُدرَج مع vendorJs('sweetalert2') في الفوتر ولا تنفصل عنه.
         راجع assets_helper.php للسبب.
*/ ?>
<?= vendorCss('sweetalert2-css') ?>

    <?php /*
حزمة المتجر — ملف @import واحد يجمع base/vendor/layout/
         components/animations. راجع public/css/store.css للترتيب.
*/ ?>
<?= cssBundle('store') ?>

    <?php // إذا كان هناك أكواد إضافية للـ head من صفحات معينة ?>
    <?php if (isset($extraHead)) echo $extraHead; elseif (isset($data['extraHead'])) echo $data['extraHead']; ?>
</head>
<body class="page-transitioning">
    <?php // Skip to content و BASE_URL سيوضعان في بداية ملف الـ Navbar ?>