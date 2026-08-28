<?php
/**
 * app/views/inc/modals/privacy-policy.php
 * Privacy Policy Modal — Partial فقط، يُستدعى من footer.php
 * منقول من components/footer.php القديم (سطر 322–368)
 */
?>
<!-- ══ Privacy Policy Modal ════════════════════════════════ -->
<div class="modal fade" id="privacyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" class="u-policy-panel">
            <div class="modal-header u-border-bottom">
                <h5 class="modal-title">🔒 Privacy Policy / سياسة الخصوصية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body u-scroll-60vh">
                <h3>🔒 Privacy Policy / سياسة الخصوصية</h3>
                <hr class="u-border-section">

                <h5>1. Information We Collect / البيانات التي نجمعها</h5>
                <p>We collect personal information that you provide to us directly, such as your full name, email address, password, phone number, birth date, gender, country, and city when registering an account or communicating with us.</p>
                <p>نقوم بجمع المعلومات الشخصية التي تقدمها لنا مباشرة، مثل الاسم الكامل، عنوان البريد الإلكتروني، كلمة المرور، رقم الهاتف، تاريخ الميلاد، الجنس، الدولة، والمدينة عند إنشاء حساب أو التواصل معنا.</p>

                <h5>2. How We Use Your Information / كيف نستخدم بياناتك</h5>
                <p>We use your information to facilitate your purchases, process and deliver your orders, communicate order updates, improve our services, prevent fraudulent activities, and comply with legal obligations.</p>
                <p>نستخدم بياناتك لتسهيل عمليات الشراء، ومعالجة طلباتك وتوصيلها، وإعلامك بآخر التحديثات حول طلباتك، وتحسين خدماتنا، ومنع الأنشطة الاحتيالية، والالتزام بالقوانين المعمول بها.</p>

                <h5>3. Data Storage & Protection / تخزين وحماية البيانات</h5>
                <p>All passwords are encrypted using high-security hashing algorithms (BCRYPT). We implement strict security measures to protect your personal data from unauthorized access, alteration, or disclosure.</p>
                <p>تُشفر جميع كلمات المرور باستخدام خوارزميات تشفير عالية الأمان (BCRYPT). نتخذ تدابير أمنية صارمة لحماية بياناتك الشخصية من الوصول غير المصرح به، أو التعديل، أو الإفصاح.</p>

                <h5>4. Cookies / ملفات تعريف الارتباط</h5>
                <p>We use cookies to maintain your login session and store your preferences. You can configure your browser to reject cookies, but some features of the website may not function correctly.</p>
                <p>نستخدم ملفات تعريف الارتباط للحفاظ على جلسة تسجيل الدخول وتخزين تفضيلاتك. يمكنك ضبط متصفحك لرفض ملفات تعريف الارتباط، ولكن قد لا تعمل بعض ميزات الموقع بشكل صحيح.</p>

                <h5>5. Your Rights / حقوقك كـ مستخدم</h5>
                <p>You have the right to access, update, or delete your personal information at any time via your 'My Info' page, or by contacting our support team.</p>
                <p>لديك الحق في الوصول إلى معلوماتك الشخصية، أو تحديثها، أو حذفها في أي وقت من خلال صفحة 'بياناتي' أو بالتواصل مع فريق الدعم لدينا.</p>

                <h5>6. Contact Us / اتصل بنا</h5>
                <p>If you have any questions or concerns regarding this Privacy Policy, you can reach out via the 'Contact Us' page.</p>
                <p>إذا كان لديك أي أسئلة أو مخاوف بشأن سياسة الخصوصية هذه، يمكنك التواصل معنا عبر صفحة 'اتصل بنا'.</p>
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
                    data-modal-after="accept-privacy">✅ I Agree / موافق</button>
            </div>
        </div>
    </div>
</div>
