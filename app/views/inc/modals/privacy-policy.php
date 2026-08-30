<?php
/**
 * app/views/inc/modals/privacy-policy.php
 * Privacy Policy Modal — Partial فقط، يُستدعى من footer.php
 * منقول من components/footer.php القديم (سطر 322–368)
 */
?>
<?php // ══ Privacy Policy Modal ════════════════════════════════ ?>
<div class="modal fade" id="privacyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content u-policy-panel">
            <div class="modal-header u-border-bottom">
                <h5 class="modal-title">🔒 Privacy Policy</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body u-scroll-60vh">
                <h3>🔒 Privacy Policy</h3>
                <hr class="u-border-section">

                <h5>1. Information We Collect</h5>
                <p>We collect personal information that you provide to us directly, such as your full name, email address, password, phone number, birth date, gender, country, and city when registering an account or communicating with us.</p>

                <h5>2. How We Use Your Information</h5>
                <p>We use your information to facilitate your purchases, process and deliver your orders, communicate order updates, improve our services, prevent fraudulent activities, and comply with legal obligations.</p>

                <h5>3. Data Storage & Protection</h5>
                <p>All passwords are encrypted using high-security hashing algorithms (BCRYPT). We implement strict security measures to protect your personal data from unauthorized access, alteration, or disclosure.</p>

                <h5>4. Cookies</h5>
                <p>We use cookies to maintain your login session and store your preferences. You can configure your browser to reject cookies, but some features of the website may not function correctly.</p>

                <h5>5. Your Rights</h5>
                <p>You have the right to access, update, or delete your personal information at any time via your 'My Info' page, or by contacting our support team.</p>

                <h5>6. Contact Us</h5>
                <p>If you have any questions or concerns regarding this Privacy Policy, you can reach out via the 'Contact Us' page.</p>
            </div>
            <div class="modal-footer u-border-top">
                <?php
                // كان هنا **دالة JS كاملة مكتوبة داخل سمة onclick** —
                // أربعة أسطر منطق في وسم HTML. صارت نيّةً معلَنة
                // (accept-privacy) وجسمها في js/core/inline-actions.js.
                ?>
                <button type="button" class="btn btn-success"
                    data-action="switch-modal"
                    data-modal-target="registerModal"
                    data-modal-after="accept-privacy">✅ I Agree</button>
            </div>
        </div>
    </div>
</div>
