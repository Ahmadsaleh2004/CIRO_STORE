<?php
/**
 * app/views/admin/settings.php — fragment فقط (بدون DOCTYPE/html/head/body)
 * Loaded by AdminController::adminView() after inc/head.php and inc/navbar.php
 * المتغيرات الجاهزة من AdminSiteSettingsController::index():
 *   $settings, $canEditCheckout, $csrf
 */
?>

<div class="admin-page-header">
    <h1>⚙️ Site Configuration</h1>
    <span class="u-muted u-fs-85">
        Changes are saved instantly without page reload
    </span>
</div>

<form id="siteSettingsForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <?php /*
══════════════════════════════════════════════
         Block 1 — General & Contact Info
         ══════════════════════════════════════════════
*/ ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-4">🌐 General &amp; Contact Info</h5>

        <div class="row g-4">

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="text" name="site_url" id="site_url"
                           value="<?= htmlspecialchars($settings['site_url'] ?? '') ?>"
                           placeholder=" ">
                    <label for="site_url">Site URL</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="text" name="phone_number" id="phone_number"
                           value="<?= htmlspecialchars($settings['phone_number'] ?? '') ?>"
                           placeholder=" ">
                    <label for="phone_number">Phone Number</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="text" name="working_hours" id="working_hours"
                           value="<?= htmlspecialchars($settings['working_hours'] ?? '') ?>"
                           placeholder=" ">
                    <label for="working_hours">Working Hours</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="number" name="employees_count" id="employees_count"
                           value="<?= htmlspecialchars($settings['employees_count'] ?? '') ?>"
                           min="0" placeholder=" ">
                    <label for="employees_count">Employees Count</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="text" name="copyright_text" id="copyright_text"
                           value="<?= htmlspecialchars($settings['copyright_text'] ?? '') ?>"
                           placeholder=" ">
                    <label for="copyright_text">Copyright Text</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="text" name="google_maps_url" id="google_maps_url"
                           value="<?= htmlspecialchars($settings['google_maps_url'] ?? '') ?>"
                           placeholder=" ">
                    <label for="google_maps_url">Google Maps URL</label>
                </div>
            </div>

            <div class="col-12">
                <div class="float-group">
                    <textarea name="footer_text" id="footer_text"
                              placeholder=" "><?= htmlspecialchars($settings['footer_text'] ?? '') ?></textarea>
                    <label for="footer_text">Footer Text</label>
                </div>
            </div>

        </div>
    </div>

    <?php /*
══════════════════════════════════════════════
         Block 2 — Social Media Links
         ══════════════════════════════════════════════
*/ ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-4">📱 Social Media Links</h5>

        <div class="row g-4">

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="url" name="facebook_url" id="facebook_url"
                           value="<?= htmlspecialchars($settings['facebook_url'] ?? '') ?>"
                           placeholder=" ">
                    <label for="facebook_url">Facebook URL</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="url" name="instagram_url" id="instagram_url"
                           value="<?= htmlspecialchars($settings['instagram_url'] ?? '') ?>"
                           placeholder=" ">
                    <label for="instagram_url">Instagram URL</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="url" name="snapchat_url" id="snapchat_url"
                           value="<?= htmlspecialchars($settings['snapchat_url'] ?? '') ?>"
                           placeholder=" ">
                    <label for="snapchat_url">Snapchat URL</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="text" name="whatsapp_number" id="whatsapp_number"
                           value="<?= htmlspecialchars($settings['whatsapp_number'] ?? '') ?>"
                           placeholder=" ">
                    <label for="whatsapp_number">WhatsApp Number</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="url" name="tiktok_url" id="tiktok_url"
                           value="<?= htmlspecialchars($settings['tiktok_url'] ?? '') ?>"
                           placeholder=" ">
                    <label for="tiktok_url">TikTok URL</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="url" name="twitter_x_url" id="twitter_x_url"
                           value="<?= htmlspecialchars($settings['twitter_x_url'] ?? '') ?>"
                           placeholder=" ">
                    <label for="twitter_x_url">X (Twitter) URL</label>
                </div>
            </div>

        </div>
    </div>

    <?php /*
══════════════════════════════════════════════
         Block 3 — Policies
         ══════════════════════════════════════════════
*/ ?>
    <div class="card p-4 mb-4">
        <h5 class="mb-4">📋 Policies &amp; Legal</h5>

        <div class="row g-4">

            <div class="col-12">
                <div class="float-group">
                    <textarea name="return_policy" id="return_policy"
                              placeholder=" "><?= htmlspecialchars($settings['return_policy'] ?? '') ?></textarea>
                    <label for="return_policy">Return Policy</label>
                </div>
            </div>

            <div class="col-12">
                <div class="float-group">
                    <textarea name="privacy_policy" id="privacy_policy"
                              placeholder=" "><?= htmlspecialchars($settings['privacy_policy'] ?? '') ?></textarea>
                    <label for="privacy_policy">Privacy Policy</label>
                </div>
            </div>

            <div class="col-12">
                <div class="float-group">
                    <textarea name="terms_and_conditions" id="terms_and_conditions"
                              placeholder=" "><?= htmlspecialchars($settings['terms_and_conditions'] ?? '') ?></textarea>
                    <label for="terms_and_conditions">Terms &amp; Conditions</label>
                </div>
            </div>

        </div>
    </div>

    <?php /*
══════════════════════════════════════════════
         Block 4 — Checkout Settings (شرطي — can_manage_checkout_settings)
         الفلترة الأمنية الفعلية تصير في الكونترولر (AdminSiteSettingsController::save)
         هذا الإخفاء بالـ View هو UX فقط — ليس الحماية الوحيدة
         ══════════════════════════════════════════════
*/ ?>
    <?php if ($canEditCheckout): ?>
    <div class="card p-4 mb-4 u-alert-amber">
        <h5 class="mb-1">💳 Checkout Settings</h5>
        <p class="small mb-4 u-muted">
            Visible only to admins with <code>can_manage_checkout_settings</code> permission.
        </p>

        <div class="row g-4">

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="text" name="default_currency" id="default_currency"
                           value="<?= htmlspecialchars($settings['default_currency'] ?? '') ?>"
                           placeholder=" ">
                    <label for="default_currency">Default Currency (e.g. USD, EGP)</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="text" name="default_language" id="default_language"
                           value="<?= htmlspecialchars($settings['default_language'] ?? '') ?>"
                           placeholder=" ">
                    <label for="default_language">Default Language (e.g. en, ar)</label>
                </div>
            </div>

        </div>
    </div>
    <?php endif; ?>

    <?php /*
══════════════════════════════════════════════
         Save Button
         ══════════════════════════════════════════════
*/ ?>
    <div class="d-flex justify-content-end mt-2 mb-4">
        <button type="submit" id="saveSettingsBtn" class="btn btn-success btn-lg px-5">
            💾 Save All Settings
        </button>
    </div>

</form>
