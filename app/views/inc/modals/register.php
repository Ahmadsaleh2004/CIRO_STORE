<?php
/**
 * app/views/inc/modals/register.php
 * Register Modal — Partial فقط، يُستدعى من footer.php
 * منقول من components/footer.php القديم (سطر 144–287)
 * CSRF Token يأتي جاهزاً من $csrfToken الممررة عبر footer.php
 */
?>
<!-- ══ Register Modal ══════════════════════════════════════ -->
<div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-register">
                <div>
                    <span class="modal-icon">✨</span>
                    <h5 class="modal-title">Create Account</h5>
                    <small class="u-on-dark u-fs-80">Join Cairo Store today</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="signupForm" novalidate>
                    <input type="hidden" name="action"     value="register">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                    <input type="hidden" name="phone"      value=""><!-- يُملأ بـ auth.js -->

                    <div class="row">
                        <div class="col-md-6">
                            <div class="float-group">
                                <input type="text" name="full_name" id="regName"
                                       placeholder=" " required autocomplete="name">
                                <label>Full Name</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="float-group">
                                <input type="email" name="email" id="regEmail"
                                       placeholder=" " required autocomplete="email">
                                <label>Email Address</label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="float-group">
                                <div class="input-group">
                                    <input type="password" id="regPass" name="password"
                                           placeholder=" " required autocomplete="new-password">
                                    <span class="input-group-text"
                                          data-action="toggle-both-passwords" data-eye="eyeReg"
                                          id="eyeReg" class="u-clickable">👁️</span>
                                </div>
                                <label>Password</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="float-group">
                                <input type="password" id="regConfirmPass" name="confirm_password"
                                       placeholder=" " required autocomplete="new-password">
                                <label>Confirm Password</label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-5">
                            <div class="float-group">
                                <div class="input-group">
                                    <select id="phoneCountryCode" class="form-select phone-code-select">
                                        <option value="+962">🇯🇴 +962</option>
                                        <option value="+20">🇪🇬 +20</option>
                                        <option value="+966">🇸🇦 +966</option>
                                        <option value="+971">🇦🇪 +971</option>
                                        <option value="+1">🇺🇸 +1</option>
                                        <option value="+44">🇬🇧 +44</option>
                                        <option value="+90">🇹🇷 +90</option>
                                        <option value="+49">🇩🇪 +49</option>
                                    </select>
                                    <input type="tel" id="regPhoneLocal" placeholder=" " maxlength="9">
                                </div>
                                <label>Phone Number</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="float-group">
                                <select name="gender" id="regGender" required>
                                    <option value="">Select</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </select>
                                <label>Gender</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="float-group">
                                <input type="date" name="birth_date" id="regBirthDate" placeholder=" ">
                                <label>Birth Date</label>
                            </div>
                            <small class="text-muted d-block mt-n2 mb-2 u-fs-75 ps-1">
                                Must be 13 years or older
                            </small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="float-group">
                                <input type="text" name="country" id="regCountry"
                                       placeholder=" " autocomplete="country-name">
                                <label>Country</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="float-group">
                                <input type="text" name="city" id="regCity"
                                       placeholder=" " autocomplete="address-level2">
                                <label>City</label>
                            </div>
                        </div>
                    </div>

                    <div class="u-consent-row">
                        <input type="checkbox"
                               id="privacyCheck" name="privacy_policy_accepted" required
                               class="u-consent-box">
                        <label for="privacyCheck" class="u-consent-label">
                            I agree to the
                        </label>
                        <a href="#" class="small"
                           data-action="switch-modal" data-modal-target="privacyModal">Privacy Policy</a>
                    </div>

                    <div id="regError" class="alert alert-danger py-2 small mb-3 d-none"></div>
                    <button type="submit" id="regBtn" class="btn btn-success w-100 mb-3 py-2">
                        Create Account
                    </button>
                    <div class="modal-divider">or</div>
                    <p class="text-center small mb-0">
                        Already have an account?
                        <a href="#" class="fw-bold"
                           data-action="switch-modal" data-modal-target="loginModal">Sign In</a>
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>
