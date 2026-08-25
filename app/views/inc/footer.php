<?php
/**
 * app/views/inc/footer.php
 * يستدعي الـ Partials + يحمّل ملفات JS
 * البيانات تأتي جاهزة من الـ Controller عبر $data (تم extract في Controller::view())
 */

// توليد CSRF Token لاستخدامه داخل المودالز
$csrfToken = generateCsrfToken();
?>
<footer class="custom-footer mt-5">
    <div class="container py-5">
        <div class="row">
            <div class="col-lg-4 mb-4">
                <h4 class="fw-bold mb-2">🏪 Cairo Store</h4>
                <p class="small" style="color:var(--footer-text);">
                    <?= htmlspecialchars($footerText ?? 'Premium electronics store offering smartphones, laptops, gaming devices and smart accessories.') ?>
                </p>
            </div>
            <div class="col-lg-4 mb-4">
                <h5 class="fw-semibold mb-3">Quick Links</h5>
                <ul class="list-unstyled">
                    <li class="mb-1"><a href="<?= URLROOT ?>">Home</a></li>
                    <li class="mb-1"><a href="<?= URLROOT ?>/products">Products</a></li>
                    <li class="mb-1"><a href="<?= URLROOT ?>/about">About Us</a></li>
                    <li class="mb-1"><a href="<?= URLROOT ?>/contact">Contact Us</a></li>
                </ul>
            </div>
            <div class="col-lg-4 mb-4">
                <h5 class="fw-semibold mb-3">Stay Connected</h5>
                <p class="small mb-3" style="color:var(--footer-text);">Stay updated with our latest news and offers!</p>
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
            <p class="mb-0 small" style="color:var(--footer-text);">
                <?= htmlspecialchars($copyrightText ?? ('© ' . date('Y') . ' Cairo Store. All Rights Reserved.')) ?>
            </p>
        </div>
    </div>

    <!-- ══ Partials ════════════════════════════════════════════ -->
    <?php require __DIR__ . '/modals/cart.php'; ?>
    <?php require __DIR__ . '/modals/login.php'; ?>
    <?php require __DIR__ . '/modals/register.php'; ?>
    <?php require __DIR__ . '/modals/forgot-password.php'; ?>
    <?php require __DIR__ . '/modals/privacy-policy.php'; ?>

    <?php if (isset($userLoggedIn) && $userLoggedIn): ?>
        <?php require __DIR__ . '/modals/notifications.php'; ?>
    <?php endif; ?>

    <!-- ══ إصلاح لون الـ inputs في dark mode ══════════════════ -->
    <script>
    (function fixInputFocus() {
        function applyInputColors() {
            const isDark = document.body.classList.contains('dark-mode');
            const bg     = isDark ? '#21262d' : '#ffffff';
            const fg     = isDark ? '#e6edf3' : '#1a1a2e';
            document.querySelectorAll(
                '#loginModal input:not([type="checkbox"]), #forgotModal input, #registerModal input:not([type="checkbox"]),'
                + ' #registerModal select, #registerModal textarea'
            ).forEach(el => {
                el.style.setProperty('background-color', bg, 'important');
                el.style.setProperty('color', fg, 'important');
            });
        }
        document.addEventListener('shown.bs.modal', applyInputColors);
        const themeToggle = document.getElementById('theme-toggle');
        if (themeToggle) themeToggle.addEventListener('click', () => setTimeout(applyInputColors, 50));
        document.addEventListener('focusin', function(e) {
            if (e.target.type === 'checkbox') return;
            const modal = e.target.closest('#loginModal, #forgotModal, #registerModal');
            if (!modal) return;
            const isDark = document.body.classList.contains('dark-mode');
            const bg     = isDark ? '#21262d' : '#ffffff';
            const fg     = isDark ? '#e6edf3' : '#1a1a2e';
            e.target.style.setProperty('background-color', bg, 'important');
            e.target.style.setProperty('color', fg, 'important');
        });
    })();
    </script>

    <!-- ══ Scripts ═════════════════════════════════════════════ -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>

    <!-- Core JS -->
    <script src="<?= URLROOT ?>/js/core/utils.js" defer></script>
    <script src="<?= URLROOT ?>/js/core/csrf.js" defer></script>
    <script src="<?= URLROOT ?>/js/core/ui.js" defer></script>
    <script src="<?= URLROOT ?>/js/core/theme.js" defer></script>

    <!-- Features JS — محمّلة دائماً -->
    <script src="<?= URLROOT ?>/js/features/cart.js" defer></script>
    <script src="<?= URLROOT ?>/js/features/products-catalog.js" defer></script>
    <script src="<?= URLROOT ?>/js/features/auth.js" defer></script>
    <script src="<?= URLROOT ?>/js/features/wishlist.js" defer></script>
    <script src="<?= URLROOT ?>/js/main.js" defer></script>

    <!-- Notifications JS — فقط للمستخدم المسجّل -->
    <?php if (isset($userLoggedIn) && $userLoggedIn): ?>
    <script src="<?= URLROOT ?>/js/features/notifications.js" defer></script>
    <?php endif; ?>

    <!-- Shared JS — زر إلغاء/حذف الطلب المشترك (my-info + admin order details) -->
    <script src="<?= URLROOT ?>/js/shared/order-cancel.js" defer></script>

    <!-- Extra Scripts من الـ Controller (مثلاً صفحة Checkout / My Info) -->
    <?php if (isset($extraScripts)) echo $extraScripts; elseif (isset($data['extraScripts'])) echo $data['extraScripts']; ?>
</footer>

</body>
</html>
