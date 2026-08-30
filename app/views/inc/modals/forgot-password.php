<?php
/**
 * app/views/inc/modals/forgot-password.php
 * Forgot password modal — a partial only, included from footer.php.
 * Moved out of the old components/footer.php (lines 288–321).
 */
?>
<?php // ══ Forgot Password Modal ════════════════════════════════ ?>
<div class="modal fade" id="forgotModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-header-forgot">
                <div>
                    <span class="modal-icon">🔑</span>
                    <h5 class="modal-title">Reset Password</h5>
                    <small class="u-on-dark u-fs-80">We'll send you a reset link</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="forgotForm">
                    <input type="hidden" name="action" value="forgot">
                    <?php /*
Without this field, AuthController::forgot() refused every request
                         with "Invalid CSRF token" before ever reaching the email send.
                         $csrfToken is defined by footer.php before the modals are included.
*/ ?>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                    <div class="float-group">
                        <input type="email" name="email" id="forgotEmail"
                               class="form-control"
                               placeholder=" " required autocomplete="email">
                        <label>Email Address</label>
                    </div>
                    <div id="forgotMsg" class="alert py-2 small mb-3 d-none"></div>
                    <button type="submit" id="forgotBtn" class="btn btn-warning w-100 mb-3 py-2">Send Reset Link</button>
                    <div class="modal-divider">or</div>
                    <p class="text-center small mb-0">
                        <a href="#" class="fw-bold"
                           data-action="switch-modal" data-modal-target="loginModal">← Back to Sign In</a>
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>
