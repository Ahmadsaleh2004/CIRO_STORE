<?php
/**
 * app/views/inc/head.php
 * This file contains the HTML <head> and nothing else.
 * The data (the title and description, for instance) arrives ready from the controller
 * and is printed here.
 */
?>
<!DOCTYPE html>
<?php // The controller passes the page's language and direction (en/ltr, for instance) ?>
<html lang="<?= htmlspecialchars($data['htmlLang'] ?? 'en') ?>" dir="<?= htmlspecialchars($data['htmlDir'] ?? 'ltr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <?php // Make sure the controller passes a variable named $data['title'] ?>
    <title><?= htmlspecialchars($data['title'] ?? 'Cairo Store') ?></title>
    
    <?php // The SEO meta tags ?>
    <meta name="description" content="<?= htmlspecialchars($data['desc'] ?? 'Cairo Store Best Electronics') ?>">
    <meta name="robots" content="<?= htmlspecialchars($data['robots'] ?? 'index, follow') ?>">
    
    <?php // The social media meta tags (Open Graph and Twitter) ?>
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
Setting the theme before the first paint — it prevents the white flash and lets
         Bootstrap's components read dark mode. See assets_helper.php.
*/ ?>
<?= themeBootScript() ?>
    <?php // The external and internal CSS links ?>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<?= vendorCss('bootstrap-css') ?>
    <?php /*
SweetAlert2's styles — an external stylesheet, not injected from JavaScript.
         Included alongside vendorJs('sweetalert2') in the footer, and never separated
         from it. See assets_helper.php for why.
*/ ?>
<?= vendorCss('sweetalert2-css') ?>

    <?php /*
The store bundle — one @import file gathering base/vendor/layout/components/
         animations. See public/css/store.css for the order.
*/ ?>
<?= cssBundle('store') ?>

    <?php // Any extra head markup from particular pages ?>
    <?php if (isset($extraHead)) echo $extraHead; elseif (isset($data['extraHead'])) echo $data['extraHead']; ?>
</head>
<body class="page-transitioning">
    <?php // "Skip to content" and BASE_URL are placed at the start of the navbar file ?>