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


    <!-- ══ Scripts ═════════════════════════════════════════════ -->
    <?php // ⚠️ أوّلاً وبلا defer: ينسخ جزيرة بيانات الصفحة إلى window،
         // وكل ما تحته يقرأ منها. نقله لاحقاً يكسر كل صفحة تمرّر بيانات. ?>
    <?= jsTag('js/core/page-data.js', false) ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" defer></script>

    <!-- Core JS -->
    <?= jsTag('js/core/utils.js') ?>
    <?= jsTag('js/core/csrf.js') ?>
    <?= jsTag('js/core/ui.js') ?>
    <?= jsTag('js/core/flash-toast.js') ?>
    <?= jsTag('js/core/theme.js') ?>
    <!-- فرض ألوان حقول النوافذ المنبثقة — كان كتلة مضمّنة هنا -->
    <?= jsTag('js/core/modal-input-colors.js') ?>

    <!-- Features JS — محمّلة دائماً -->
    <?= jsTag('js/features/cart.js') ?>
    <?= jsTag('js/features/products-catalog.js') ?>
    <?= jsTag('js/features/auth.js') ?>
    <?= jsTag('js/features/wishlist.js') ?>
    <?= jsTag('js/main.js') ?>

    <!-- Notifications JS — فقط للمستخدم المسجّل -->
    <?php if (isset($userLoggedIn) && $userLoggedIn): ?>
    <?= jsTag('js/features/notifications.js') ?>
    <?php endif; ?>

    <!-- Shared JS — زر إلغاء/حذف الطلب المشترك (my-info + admin order details) -->
    <?= jsTag('js/shared/order-cancel.js') ?>

    <!-- Extra Scripts من الـ Controller (مثلاً صفحة Checkout / My Info) -->
    <?php if (isset($extraScripts)) echo $extraScripts; elseif (isset($data['extraScripts'])) echo $data['extraScripts']; ?>
</footer>

</body>
</html>
