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
                <p class="small u-footer-text">
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
     * ⚠️ مودال السلّة محروس بتسجيل الدخول — وكان يُطبع للجميع.
     *
     * زرّ السلّة في الـnavbar محروس منذ البداية، فكان الزائر يتلقّى
     * الشريط الجانبي كاملاً في صفحته بلا زرّ يفتحه: ترميزٌ ميّت على
     * كل صفحة.
     *
     * وصار الأمر أثقل بعد انتقال السلّة إلى الخادم: cart.js يستدلّ
     * على «هل للمستخدم سلّة؟» بوجود #cartSidebar، فكان يجدها عند
     * الزائر ويطلب /cart في كل تحميل صفحة — طلبٌ يردّ 401 دائماً
     * (مقيس)، وخطأٌ في طرفية كل زائر.
     *
     * الحارس هنا يطابق حارس الزرّ، فيتفق الاثنان على معنى واحد.
     */
    ?>
    <?php if (isset($userLoggedIn) && $userLoggedIn): ?>
        <?php require __DIR__ . '/modals/cart.php'; ?>
    <?php endif; ?>
    <?php require __DIR__ . '/modals/login.php'; ?>
    <?php require __DIR__ . '/modals/register.php'; ?>
    <?php require __DIR__ . '/modals/forgot-password.php'; ?>
    <?php require __DIR__ . '/modals/privacy-policy.php'; ?>

    <?php if (isset($userLoggedIn) && $userLoggedIn): ?>
        <?php require __DIR__ . '/modals/notifications.php'; ?>
    <?php endif; ?>


    <?php // ══ Scripts ═════════════════════════════════════════════ ?>
    <?php // ⚠️ أوّلاً وبلا defer: ينسخ جزيرة بيانات الصفحة إلى window،
         // وكل ما تحته يقرأ منها. نقله لاحقاً يكسر كل صفحة تمرّر بيانات. ?>
    <?= jsTag('js/core/page-data.js', false) ?>

    <?php
    /*
     * ⚠️ jQuery حُذف من هنا. كان يُحمَّل على **كل صفحة** ولا يُستعمل في
     * سطر واحد — مفحوص: صفر `$(` وصفر `jQuery` في public/js وapp/views
     * جميعاً. الكود كلّه vanilla. فكان الوسم يكلّف طلب شبكة على كل
     * صفحة، ويُبقي `code.jquery.com` مسموحاً به في CSP — أي نطاقاً
     * يستطيع تنفيذ جافاسكربت على صفحة الدفع — مقابل لا شيء.
     *
     * الروابط والبصمات في VENDOR_ASSETS داخل assets_helper.php.
     * bootstrap بلا defer عمداً: هذا هو السلوك القائم، وبعض الصفحات
     * تنشئ مودالات فور تحميلها.
     */
    ?>
    <?= vendorJs('bootstrap-js', false) ?>
    <?= vendorJs('sweetalert2') ?>

    <?php // Core JS ?>
    <?php
    // حزمة واحدة بدل ثلاثة عشر وسماً. راجع jsBundle في
    // assets_helper.php — القائمة هنا هي الارتداد عند غياب البناء،
    // وترتيبها هو العقد: الملفات تتشارك النطاق العام.
    ?>
    <?= jsBundle('store', [
        'js/core/inline-actions.js',
        'js/core/utils.js',
        'js/core/csrf.js',
        'js/core/ui.js',
        'js/core/flash-toast.js',
        'js/core/theme.js',
        'js/core/modal-input-colors.js',
        'js/features/cart.js',
        'js/features/products-catalog.js',
        'js/features/auth.js',
        'js/features/wishlist.js',
        'js/main.js',
        'js/shared/order-cancel.js',
    ]) ?>

    <?php // إشعارات المستخدم المسجّل — حزمة منفصلة كي لا يحمّلها الزائر. ?>
    <?php if (isset($userLoggedIn) && $userLoggedIn): ?>
    <?= jsBundle('store-auth', ['js/features/notifications.js']) ?>
    <?php endif; ?>

    <?php if (isset($extraScripts)) echo $extraScripts; elseif (isset($data['extraScripts'])) echo $data['extraScripts']; ?>
</footer>

</body>
</html>
