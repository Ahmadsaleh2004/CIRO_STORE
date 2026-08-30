<?php
/**
 * app/views/errors/403.php
 * The "you do not have permission" page.
 *
 * The twin of views/errors/404.php, and for the same reason: the refusal response used
 * to be `die('Unauthorized — Root admin only (ID=1)')` — raw text with no <head>, no
 * layout and no way back, disclosing the permission rule to the visitor.
 *
 * Included from ErrorPage::forbidden() with a direct require rather than through
 * view(): calling view() from inside an error handler risks a loop if the error is in
 * the layout itself.
 *
 * It never prints the detailed reason for the refusal: that goes to the PHP error log
 * alone.
 *
 * The variables (set by ErrorPage::forbidden before the require):
 *   $backUrl    string  The back button's URL
 *   $backLabel  string  The back button's text
 */

$bareTitle     = '403 — ' . SITENAME;
$bareThemeBoot = true;
$bareCss       = ['css/store.css'];

require APPROOT . '/views/inc/head-bare.php';
?>

<main class="container py-5 text-center u-minh-70vh">
    <p class="display-1 mb-2" aria-hidden="true">🔒</p>
    <h1 class="h3 mb-3">Access denied</h1>
    <p class="text-muted mb-4">
        You do not have permission to view this page.
    </p>
    <a class="btn btn-primary" href="<?= htmlspecialchars($backUrl) ?>">
        <?= htmlspecialchars($backLabel) ?>
    </a>
</main>

<?php require APPROOT . '/views/inc/footer-bare.php'; ?>
