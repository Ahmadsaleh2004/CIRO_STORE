<?php
/**
 * app/views/inc/footer.php
 * It includes the partials and loads the JavaScript files.
 * The data arrives ready from the controller through $data (extracted in Controller::view()).
 */

// Generate a CSRF token for use inside the modals
$csrfToken = generateCsrfToken();
?>
<footer class="custom-footer mt-5">
    <div class="container py-5">
        <div class="row">
            <?php
            // Two columns, not three. The middle one was "Quick Links": Home, Products,
            // About Us, Contact Us — the same four links the navbar carries, and the navbar
            // now shows all four at every width without a hamburger, so the footer copy
            // repeated what was already on screen and gave a visitor a second place to
            // maintain when a page is renamed.
            //
            // The remaining two take half the row each rather than a third, or the space
            // the links held would stay empty.
            ?>
            <div class="col-lg-6 mb-4">
                <h4 class="fw-bold mb-2">🏪 Cairo Store</h4>
                <p class="small u-footer-text">
                    <?= htmlspecialchars($footerText ?? 'Premium electronics store offering smartphones, laptops, gaming devices and smart accessories.') ?>
                </p>
            </div>
            <div class="col-lg-6 mb-4">
                <h5 class="fw-semibold mb-3">Stay Connected</h5>
                <p class="small mb-3 u-footer-text">Stay updated with our latest news and offers!</p>
                <div class="d-flex gap-3 mt-2">
                    <a href="<?= htmlspecialchars($fbUrl ?? '#') ?>" title="Facebook">
                        <img src="<?= URLROOT ?>/images/icons/facebook.svg" width="26" height="26" alt="Facebook" loading="lazy"></a>
                    <a href="<?= htmlspecialchars($igUrl ?? '#') ?>" title="Instagram">
                        <img src="<?= URLROOT ?>/images/icons/instagram.svg" width="26" height="26" alt="Instagram" loading="lazy"></a>
                    <a href="<?= htmlspecialchars($snapUrl ?? '#') ?>" title="Snapchat">
                        <img src="<?= URLROOT ?>/images/icons/snapchat.svg" width="26" height="26" alt="Snapchat" loading="lazy"></a>
                </div>
            </div>
        </div>
        <hr>
        <div class="text-center">
            <p class="mb-0 small u-footer-text">
                <?= htmlspecialchars($copyrightText ?? ('© ' . date('Y') . ' Cairo Store. All Rights Reserved.')) ?>
            </p>
        </div>
    </div>

    <?php // ══ Partials ════════════════════════════════════════════ ?>
    <?php
    /*
     * ⚠️ The cart modal is login-guarded — and it used to be printed for everyone.
     *
     * The cart button in the navbar has been guarded from the start, so a signed-out
     * visitor received the entire sidebar on their page with no button to open it: dead
     * markup on every page.
     *
     * And it grew heavier once the cart moved to the server: cart.js infers "does this
     * user have a cart?" from the presence of #cartSidebar, so it found one for a visitor
     * and requested /cart on every page load — a request that always answered 401
     * (measured), and an error in every visitor's console.
     *
     * The guard here matches the button's guard, so the two agree on one meaning.
     */
    ?>
    <?php if (isset($userLoggedIn) && $userLoggedIn): ?>
        <?php require __DIR__ . '/modals/cart.php'; ?>
        <?php
        /*
         * The CSRF token onto window._csrfToken — the same thing the admin panel does in
         * admin/inc/navbar.php.
         *
         * ⚠️ The store pages used to pass it in hidden fields alone, with nothing setting
         * `window._csrfToken`. And cart.js reads from that, so it sent an empty string on
         * every cart operation.
         *
         * That was not a visible fault — the safety net in csrf.js catches the failure,
         * fetches a token and retries — but it meant **three requests instead of one** on
         * every add, increment or delete. Measured in the browser:
         *
         *     POST /cart/add  →  GET /auth/csrf  →  POST /cart/add
         *
         * And that price is paid on every click, at the heaviest moment: a customer
         * filling their cart.
         *
         * And for signed-in users alone: a visitor has no cart, and there is no reason to
         * send a token to somebody with no endpoint to use it on.
         */
        ?>
        <?= pageData(['_csrfToken' => $csrfToken]) ?>
    <?php endif; ?>
    <?php require __DIR__ . '/modals/login.php'; ?>
    <?php require __DIR__ . '/modals/register.php'; ?>
    <?php require __DIR__ . '/modals/forgot-password.php'; ?>
    <?php require __DIR__ . '/modals/privacy-policy.php'; ?>

    <?php if (isset($userLoggedIn) && $userLoggedIn): ?>
        <?php require __DIR__ . '/modals/notifications.php'; ?>
    <?php endif; ?>


    <?php // ══ Scripts ═════════════════════════════════════════════ ?>
    <?php // ⚠️ First and without defer: it copies the page's data island onto window, and
         // everything below reads from it. Moving it later breaks every page passing data. ?>
    <?= jsTag('js/core/page-data.js', false) ?>

    <?php
    /*
     * ⚠️ jQuery was removed from here. It was loaded on **every page** and used on not
     * one line — verified: zero `$(` and zero `jQuery` across all of public/js and
     * app/views. The code is entirely vanilla. So the tag cost a network request on every
     * page and kept `code.jquery.com` permitted in the CSP — a domain able to execute
     * JavaScript on the checkout page — in exchange for nothing.
     *
     * The URLs and integrity hashes live in VENDOR_ASSETS, inside assets_helper.php.
     * bootstrap without defer, deliberately: that is the existing behaviour, and some
     * pages create modals as soon as they load.
     */
    ?>
    <?= vendorJs('bootstrap-js', false) ?>
    <?= vendorJs('sweetalert2') ?>

    <?php // Core JS ?>
    <?php
    // One bundle in place of thirteen tags. See jsBundle in assets_helper.php — the list
    // here is the fallback for when nothing is built, and its order is the contract: the
    // files share the global scope.
    ?>
    <?= jsBundle('store', [
        'js/core/inline-actions.js',
        'js/core/utils.js',
        'js/core/csrf.js',
        'js/core/ui.js',
        'js/core/flash-toast.js',
        'js/core/theme.js',
        'js/core/modal-input-colors.js',
        'js/store/slider-chunk.js',
        'js/features/cart.js',
        'js/features/products-catalog.js',
        'js/features/auth.js',
        'js/features/wishlist.js',
        'js/main.js',
        'js/shared/order-cancel.js',
    ]) ?>

    <?php // A signed-in user's notifications — a separate bundle, so a visitor does not load it ?>
    <?php if (isset($userLoggedIn) && $userLoggedIn): ?>
    <?= jsBundle('store-auth', ['js/features/notifications.js']) ?>
    <?php endif; ?>

    <?php if (isset($extraScripts)) echo $extraScripts; elseif (isset($data['extraScripts'])) echo $data['extraScripts']; ?>
</footer>

</body>
</html>
