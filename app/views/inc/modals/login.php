<?php
/**
 * app/views/inc/modals/login.php
 * Login Modal — Partial فقط، يُستدعى من footer.php
 * منقول من components/footer.php القديم (سطر 90–143)
 * CSRF Token يأتي جاهزاً من $csrfToken الممررة عبر footer.php
 */
?>
<!-- ══ Login Modal ═════════════════════════════════════════ -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-header-login">
                <div>
                    <span class="modal-icon">🔐</span>
                    <h5 class="modal-title">Welcome Back</h5>
                    <small style="color:rgba(255,255,255,.7);font-size:.8rem;">Sign in to your account</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="loginForm" novalidate>
                    <input type="hidden" name="action"     value="login">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

                    <div class="float-group">
                        <input type="email" id="loginEmail" name="email"
                               class="form-control"
                               placeholder=" " required autocomplete="email"
                               style="background-color:var(--input-bg);color:var(--input-text);">
                        <label>Email Address</label>
                    </div>
                    <div class="float-group mb-1">
                        <div class="input-group">
                            <input type="password" id="loginPass" name="password"
                                   class="form-control"
                                   placeholder=" " required autocomplete="current-password"
                                   style="background-color:var(--input-bg);color:var(--input-text);">
                            <span class="input-group-text"
                                  data-action="toggle-password" data-input="loginPass" data-eye="eyeLogin"
                                  id="eyeLogin" style="cursor:pointer;">👁️</span>
                        </div>
                        <label>Password</label>
                    </div>
                    <div class="d-flex justify-content-end mb-3">
                        <a href="#" class="small"
                           data-action="switch-modal" data-modal-target="forgotModal">Forgot Password?</a>
                    </div>
                    <div id="loginError" class="alert alert-danger py-2 small mb-3" style="display:none;"></div>
                    <button type="submit" class="btn btn-dark w-100 mb-3 py-2" id="loginBtn">Sign In</button>

                    <!-- زر تسجيل الدخول عبر جوجل -->
                    <div class="modal-divider">or</div>
                    <a href="<?= URLROOT ?>/auth/google"
                       class="btn btn-outline-danger w-100 mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 48 48"
                             style="vertical-align:middle;margin-right:6px;">
                            <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.2l6.7-6.7C35.6 2.4 30.2 0 24 0 14.8 0 6.9 5.4 3 13.3l7.8 6.1C12.8 13.2 17.9 9.5 24 9.5z"/>
                            <path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v8.5h12.7c-.6 3-2.3 5.5-4.8 7.2l7.6 5.9c4.4-4.1 7-10.1 7-17.1z"/>
                            <path fill="#FBBC05" d="M10.8 28.6A14.5 14.5 0 0 1 9.5 24c0-1.6.3-3.2.8-4.6L2.5 13.3A23.9 23.9 0 0 0 0 24c0 3.8.9 7.4 2.5 10.6l8.3-6z"/>
                            <path fill="#34A853" d="M24 48c6.2 0 11.5-2 15.3-5.5l-7.6-5.9c-2.1 1.4-4.8 2.2-7.7 2.2-6.1 0-11.2-3.7-13.2-9l-8.3 6C6.9 42.6 14.8 48 24 48z"/>
                        </svg>
                        Sign in with Google
                    </a>

                    <div class="modal-divider">or</div>
                    <p class="text-center small mb-0">
                        Don't have an account?
                        <a href="#" class="fw-bold"
                           data-action="switch-modal" data-modal-target="registerModal">Create one</a>
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>
