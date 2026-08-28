<?php
/**
 * app/views/admin/manage-admins/_perm-modal.php
 * Modal تعديل الرتبة/الصلاحيات — يُملأ بالكامل عبر JS (openPermModal)
 * من بيانات الصف نفسه بالجدول، بدون أي طلب إضافي للسيرفر.
 * الإرسال: POST تقليدي → /admin/admins/edit → إعادة توجيه مع flash
 */
?>
<div class="modal fade" id="permModal" tabindex="-1"
     aria-labelledby="permModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header u-border-section">
                <h5 class="modal-title" id="permModalTitle">Edit Permissions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <form method="POST" action="<?= URLROOT ?>/admin/admins/edit" id="permForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="target_id"  id="permTargetId">

                    <!-- Role -->
                    <div class="float-group mb-3 u-mw-220">
                        <select name="edit_role" id="permRole">
                            <?php
                            $roleMap = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1];
                            $myRank  = $roleMap[$adminRole] ?? 0;
                            $roleLabels = ['A' => 'A — Super Admin', 'B' => 'B — Admin', 'C' => 'C — Moderator', 'D' => 'D — Support'];
                            foreach ($roleLabels as $val => $label):
                                if (($roleMap[$val] ?? 0) < $myRank):
                            ?>
                            <option value="<?= $val ?>"><?= $label ?></option>
                            <?php
                                endif;
                            endforeach;
                            ?>
                        </select>
                        <label>Role</label>
                    </div>

                    <!-- Permissions Grid -->
                    <div class="perm-grid mb-3">
                        <label class="perm-item">
                            <input type="checkbox" name="perm_admins"    id="ep_admins">
                            <span>👑 Manage Admins</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_products"  id="ep_products">
                            <span>🛍️ Manage Products</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_users"     id="ep_users">
                            <span>👥 Manage Users</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_dashboard" id="ep_dashboard">
                            <span>📊 View Dashboard</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_support"   id="ep_support">
                            <span>💬 Manage Support</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_content"   id="ep_content">
                            <span>⚙️ Edit Site Content</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_checkout"  id="ep_checkout">
                            <span>💳 Checkout Settings</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_branding" id="ep_branding">
                            <span>🎬 Manage Branding (Slider)</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_orders"    id="ep_orders">
                            <span>📦 Manage Orders</span>
                        </label>
                    </div>

                    <!-- Reason -->
                    <div class="float-group mb-3">
                        <textarea name="edit_reason"
                                  id="editAdminReason"
                                  rows="3"
                                  placeholder=" "
                                  required></textarea>
                        <label>Reason for this edit <span class="text-danger">*</span></label>
                    </div>

                    <!-- Password re-auth -->
                    <div class="float-group mb-4">
                        <input type="password"
                               name="confirm_edit_pass"
                               id="confirm_edit_pass"
                               placeholder=" "
                               required>
                        <label>Your Password (re-auth) <span class="text-danger">*</span></label>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button"
                                class="btn btn-secondary"
                                data-bs-dismiss="modal">Cancel</button>
                        <button type="submit"
                                class="btn btn-success btn-disabled-faded"
                                id="savePermsBtn"
                                disabled>💾 Save Permissions</button>
                    </div>

                </form>
            </div>

        </div>
    </div>
</div>
