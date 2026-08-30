<?php
/**
 * app/views/errors/500.php
 * The "service unavailable" page.
 *
 * Included from App\Core\ErrorPage::serverError() with a direct require rather than
 * through view() — for the same reason as 404.php: calling view() from inside an error
 * handler opens the door to an infinite loop.
 *
 * ⚠️ What this page is most often rendered for is **a failed database connection**, so
 * add nothing to it that reads from the database: no site settings, no branding, no cart
 * counter. head-bare.php and footer-bare.php do not touch the database (verified), and
 * that is why they are used here.
 *
 * And it never prints the exception's message: a PDO message contains the host name,
 * the database name and the user name. The detail goes to the error log alone.
 */

$bareTitle     = 'Temporarily unavailable — ' . SITENAME;
// There used to be `$bareLang = 'ar'` and `$bareDir = 'rtl'` here, because this page's
// text was the only Arabic text in the interface — and it was the one page in the
// project that flipped the document's direction. The text is now English like the rest
// of the interface, and head-bare.php's default (en/ltr) is the correct one for it.
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
