<?php
/**
 * app/views/errors/404.php
 * The "page not found" page.
 *
 * Included from Controller::renderViewNotFound() with a direct require rather than
 * through view() — calling view() from inside the missing-view handler means an infinite
 * loop if the missing file is this page itself.
 *
 * It never prints the missing file's path: that goes to the PHP error log alone.
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
        The page you requested does not exist, or it has moved.
    </p>
    <a class="btn btn-primary" href="<?= URLROOT ?>/">Back to home</a>
</main>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>
