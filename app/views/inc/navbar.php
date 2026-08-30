<?php
/**
 * app/views/inc/navbar.php
 * This file contains the HTML for the visible top navigation and nothing else.
 * The controller is what checks the sign-in state and passes the variables ready (such as
 * $data['userLoggedIn']).
 */
?>
<?php // "Skip to content" and BASE_URL moved to the start of the body, to keep the head file clean ?>
<?= pageData(['BASE_URL' => URLROOT]) ?>
<a href="#main-content" class="skip-nav">Skip to main content</a>

<?php
// Reading store mode from the visitor's session (PHPSESSID).
// The flag is written by /admin/store-mode/enter into both sessions, and the store reads
// it here from the visitor's session alone — the two sessions are entirely separate.
if (session_status() === PHP_SESSION_NONE) {
    session_name('PHPSESSID');
    session_start();
}
$adminInStoreMode = !empty($_SESSION['admin_in_store_mode']);
?>

<?php if ($adminInStoreMode): ?>
<?php // The store-mode bar — shown to an admin only while browsing the store as a visitor ?>
<div class="container-fluid store-mode-bar">
    <span>👑 You are browsing the store as a guest</span>
    <a href="<?= URLROOT ?>/admin/store-mode/reauth" class="store-mode-return">
        🔐 Return to Admin Panel
    </a>
</div>
<?php endif; ?>

<nav class="navbar custom-navbar navbar-expand-lg" id="mainNavbar">
    <div class="container">
        <?php // Using URLROOT for clean links ?>
        <a class="navbar-brand fw-bold" href="<?= URLROOT ?>">🏪 Cairo Store</a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#navbarNav" aria-controls="navbarNav"
            aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mx-auto">
                <li class="nav-item">
                    <?php // The controller passes $data['activePage'] to mark the active page ?>
                    <a class="nav-link <?= (isset($data['activePage']) && $data['activePage'] == 'home') ? 'active fw-bold' : '' ?>" href="<?= URLROOT ?>">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (isset($data['activePage']) && $data['activePage'] == 'products') ? 'active fw-bold' : '' ?>" href="<?= URLROOT ?>/products">Products</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (isset($data['activePage']) && $data['activePage'] == 'about') ? 'active fw-bold' : '' ?>" href="<?= URLROOT ?>/about">About Us</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= (isset($data['activePage']) && $data['activePage'] == 'contact') ? 'active fw-bold' : '' ?>" href="<?= URLROOT ?>/contact">Contact Us</a>
                </li>

                <?php // For a signed-out visitor, show the Log In button ?>
                <?php if (!isset($data['userLoggedIn']) || !$data['userLoggedIn']): ?>
                    <li class="nav-item">
                        <a class="nav-link fw-semibold" href="#"
                           data-bs-toggle="modal" data-bs-target="#loginModal">Log In</a>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="d-flex gap-2 align-items-center">
                <?php // Wishlist — available to everyone ?>
                <a href="<?= URLROOT ?>/wishlist"
                   class="btn btn-outline-danger position-relative" aria-label="Wishlist">
                    ❤️ <span id="wishlist-count" class="counter-badge" aria-live="polite">0</span>
                </a>

                <?php // The notification bell — shown only to a signed-in user ?>
                <?php if (isset($data['userLoggedIn']) && $data['userLoggedIn']): ?>
                <button id="notifBell" class="btn btn-outline-light position-relative"
                        aria-label="Notifications" title="Notifications" type="button">
                    🔔 <span id="notifBadge" class="counter-badge u-badge-dot d-none" aria-live="polite">0</span>
                </button>
                <?php endif; ?>

                <?php // Cart — shown only to a signed-in user ?>
                <?php if (isset($data['userLoggedIn']) && $data['userLoggedIn']): ?>
                <button type="button"
                    class="btn btn-outline-warning position-relative"
                    data-bs-toggle="offcanvas" data-bs-target="#cartSidebar"
                    aria-controls="cartSidebar" aria-label="Shopping cart">
                    🛒 <span id="cart-count" class="counter-badge" aria-live="polite">0</span>
                </button>
                <?php endif; ?>

                <?php // Theme Toggle ?>
                <button id="theme-toggle" class="btn btn-outline-light"
                        aria-label="Toggle theme" title="Toggle Theme">🌙</button>

                <?php // The user dropdown — shown only to a signed-in user ?>
                <?php if (isset($data['userLoggedIn']) && $data['userLoggedIn']): ?>
                <div class="dropdown">
                    <button class="btn btn-outline-light dropdown-toggle" type="button"
                        data-bs-toggle="dropdown" aria-expanded="false">
                        <?php // The user's name arrives ready from the controller ?>
                        👤 <?= htmlspecialchars($data['userName']) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end u-surface-card">
                        <li><a class="dropdown-item u-text" href="<?= URLROOT ?>/user/info">👤 My Info</a></li>
                        <li><a class="dropdown-item u-text" href="<?= URLROOT ?>/contact">💬 Contact Us</a></li>
                        <li><hr class="dropdown-divider u-border-section"></li>
                        <?php // logoutUser() is a JavaScript function defined in the static JS files ?>
                        <li><a class="dropdown-item text-danger" href="#"
                               data-action="logout-user">🚪 Log Out</a></li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<?php
/**
 * There used to be an empty <div id="main-content"></div> here, serving as the anchor
 * for the "skip to content" link. It was removed because it duplicated an id that
 * already existed: all nine store views declare <main id="main-content"> themselves, so
 * every store page carried two elements with the same id — invalid HTML, and
 * getElementById returned the empty div rather than the content.
 *
 * The skip link in head.php still points at #main-content, and it now reaches the real
 * <main> — which is what it was meant to do all along.
 */
?>