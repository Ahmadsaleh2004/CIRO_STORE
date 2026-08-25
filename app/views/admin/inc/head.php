<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> | Cairo Store Admin</title>
    <meta name="robots" content="noindex,nofollow">
<?= themeBootScript() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- store.css أولاً (لوحة التحكم تعيد استخدام كل طبقة المتجر)
         ثم admin.css فوقها. راجع public/css/admin.css للترتيب. -->
<?= cssBundle('admin') ?>
    <?= $extraHead ?? '' ?>
</head>
<body class="page-transitioning admin-layout">
<script>window.URLROOT = "<?= URLROOT ?>";</script>
<a href="#main-content" class="skip-nav">Skip to main content</a>
