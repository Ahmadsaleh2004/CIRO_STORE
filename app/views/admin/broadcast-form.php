<?php
/**
 * app/views/admin/broadcast-form.php
 * Modal مشترك للـ Broadcast — يُفعَّل من أي صفحة أدمن تحتاجه.
 * الإرسال عبر JS (fetch) لـ /admin/messaging/broadcast
 * data-target-type: 'admin' | 'user' — يتحدد عبر $broadcastTargetType من الـ view الأب
 */
$broadcastTargetType = $broadcastTargetType ?? 'admin';
?>
<div class="modal fade" id="broadcastModal" tabindex="-1"
     aria-labelledby="broadcastModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header" style="border-color:var(--section-border);">
                <h5 class="modal-title" id="broadcastModalLabel">
                    📢 Broadcast to <?= $broadcastTargetType === 'user' ? 'Users' : 'Admins' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <form id="broadcastForm" data-target-type="<?= $broadcastTargetType ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                    <!-- Title -->
                    <div class="float-group mb-3">
                        <input type="text"
                               id="adminBroadTitle"
                               name="title"
                               placeholder=" ">
                        <label for="adminBroadTitle">Broadcast Title <span class="text-danger">*</span></label>
                    </div>

                    <!-- Body -->
                    <div class="float-group mb-4">
                        <textarea id="adminBroadBody"
                                  name="body"
                                  rows="4"
                                  placeholder=" "></textarea>
                        <label for="adminBroadBody">Message Body <span class="text-danger">*</span></label>
                    </div>

                    <?php if ($broadcastTargetType === 'user'): ?>
                    <!-- User Status Filter -->
                    <h6 class="mb-2">👤 Send to users with status (at least one of):</h6>
                    <div class="perm-grid mb-4">
                        <label class="perm-item">
                            <input type="checkbox" name="statuses[]" value="active" class="broad-filter">
                            <span>🟢 Active</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="statuses[]" value="not_active" class="broad-filter">
                            <span>⚪ Not Active</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="statuses[]" value="blocked" class="broad-filter">
                            <span>🔴 Blocked</span>
                        </label>
                    </div>
                    <?php else: ?>
                    <!-- Permissions Filter -->
                    <h6 class="mb-2">🔐 Send to admins who have (at least one of):</h6>
                    <div class="perm-grid mb-4">
                        <label class="perm-item">
                            <input type="checkbox" name="perms[]"
                                   value="can_manage_admins" class="broad-filter">
                            <span>👑 Manage Admins</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perms[]"
                                   value="can_manage_products" class="broad-filter">
                            <span>🛍️ Manage Products</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perms[]"
                                   value="can_manage_users" class="broad-filter">
                            <span>👥 Manage Users</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perms[]"
                                   value="can_view_dashboard" class="broad-filter">
                            <span>📊 View Dashboard</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perms[]"
                                   value="can_manage_support" class="broad-filter">
                            <span>💬 Manage Support</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perms[]"
                                   value="can_edit_site_content" class="broad-filter">
                            <span>⚙️ Edit Site Content</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perms[]"
                                   value="can_manage_checkout_settings" class="broad-filter">
                            <span>💳 Checkout Settings</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perms[]"
                                   value="can_manage_orders" class="broad-filter">
                            <span>📦 Manage Orders</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perms[]"
                                   value="can_manage_branding" class="broad-filter">
                            <span>🎬 Manage Branding</span>
                        </label>
                    </div>

                    <!-- Ranks Filter -->
                    <h6 class="mb-2">🎖 AND role is one of:</h6>
                    <div class="perm-grid mb-4">
                        <label class="perm-item">
                            <input type="checkbox" name="ranks[]"
                                   value="A" class="broad-filter">
                            <span>A — Super Admin</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="ranks[]"
                                   value="B" class="broad-filter">
                            <span>B — Admin</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="ranks[]"
                                   value="C" class="broad-filter">
                            <span>C — Moderator</span>
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="ranks[]"
                                   value="D" class="broad-filter">
                            <span>D — Support</span>
                        </label>
                    </div><!-- ← كان ناقصًا: بدونه يبقى #broadcastModal مفتوحًا
                         فيبتلع كل ما يُضمَّن بعده (وأهمه #permModal في
                         manage-admins/index.php)، فيرث display:none منه
                         وينهار حجمه إلى 0×0 رغم فتحه بنجاح. -->
                    <?php endif; ?>

                    <button type="submit"
                            id="adminBroadSendBtn"
                            class="btn btn-info w-100 btn-disabled-faded"
                            disabled>
                        📢 Send Broadcast
                    </button>

                </form>
            </div>

        </div>
    </div>
</div>
