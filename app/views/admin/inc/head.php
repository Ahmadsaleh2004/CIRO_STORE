<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> | Cairo Store Admin</title>
    <meta name="robots" content="noindex,nofollow">
<?= themeBootScript() ?>
<?= vendorCss('bootstrap-css') ?>
<?= vendorCss('sweetalert2-css') ?>
    <?php /*
store.css first (the admin panel reuses the store's entire layer),
         then admin.css on top of it. See public/css/admin.css for the order.
*/ ?>
<?= cssBundle('admin') ?>
    <?= $extraHead ?? '' ?>
</head>
<body class="page-transitioning admin-layout">
<?= pageData(['URLROOT' => URLROOT]) ?>
<a href="#main-content" class="skip-nav">Skip to main content</a>
