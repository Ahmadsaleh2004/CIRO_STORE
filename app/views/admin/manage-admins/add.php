<?php
/**
 * app/views/admin/manage-admins/add.php — a fragment only.
 * The variables from AdminManageAdminsController::showAdd():
 *   $formErr, $csrf, $adminRole
 */
?>

<?php // ── Page Header ────────────────────────────────────────── ?>
<div class="admin-page-header">
    <h1>➕ Add New Admin</h1>
    <a href="<?= URLROOT ?>/admin/admins" class="btn btn-secondary btn-sm">← Back to Manage Admins</a>
</div>

<?php // ── Error Message ──────────────────────────────────────── ?>

<?php // ── Add Admin Form ─────────────────────────────────────── ?>
<div class="card p-4">
    <form method="POST" action="<?= URLROOT ?>/admin/admins/add" id="addAdminForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <?php // ── Basic Info ──────────────────────────────────── ?>
        <h5 class="mb-3">👤 Basic Info</h5>
        <div class="row g-3 mb-4">

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="text"
                           id="newAdmName"
                           name="new_name"
                           placeholder=" "
                           required>
                    <label for="newAdmName">Full Name <span class="text-danger">*</span></label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="email"
                           id="newAdmEmail"
                           name="new_email"
                           placeholder=" "
                           required>
                    <label for="newAdmEmail">Email (@gmail.com) <span class="text-danger">*</span></label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group">
                    <input type="tel"
                           id="newAdmPhone"
                           name="new_phone"
                           placeholder=" ">
                    <label for="newAdmPhone">Phone Number (optional)</label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group position-relative">
                    <input type="password"
                           id="newAdmPassword"
                           name="new_password"
                           placeholder=" "
                           required
                           class="u-input-pad-end">
                    <label for="newAdmPassword">Password <span class="text-danger">*</span></label>
                    <button type="button"
                            id="toggleNewAdmPassword"
                            class="btn btn-sm position-absolute u-input-action"
                            tabindex="-1"
                            aria-label="Show/Hide Password">👁</button>
                </div>
                <small class="u-muted u-fs-75">
                    Min 8 chars — uppercase, lowercase, number, symbol
                </small>
            </div>

        </div>

        <?php // ── Role ────────────────────────────────────────── ?>
        <h5 class="mb-3">🎖 Role</h5>
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="float-group">
                    <select name="new_role" id="newAdmRole">
                        <?php
                        // It shows only the ranks strictly below the current admin's
                        $roleMap = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1];
                        $myRank  = $roleMap[$adminRole] ?? 0;
                        foreach (['B' => 'B — Admin', 'C' => 'C — Moderator', 'D' => 'D — Support'] as $val => $label):
                            if (($roleMap[$val] ?? 0) < $myRank):
                        ?>
                        <?php // @escaping-safe: literal rank labels in this file ?>
                        <option value="<?= $val ?>"><?= $label ?></option>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </select>
                    <label for="newAdmRole">Admin Role</label>
                </div>
            </div>
        </div>

        <?php // ── Permissions ─────────────────────────────────── ?>
        <h5 class="mb-3">🔐 Permissions</h5>
        <div class="perm-grid mb-4">

            <label class="perm-item">
                <input type="checkbox" name="perm_admins" value="1">
                <span>👑 Manage Admins</span>
            </label>
            <label class="perm-item">
                <input type="checkbox" name="perm_products" value="1">
                <span>🛍️ Manage Products</span>
            </label>
            <label class="perm-item">
                <input type="checkbox" name="perm_users" value="1">
                <span>👥 Manage Users</span>
            </label>
            <label class="perm-item">
                <input type="checkbox" name="perm_dashboard" value="1">
                <span>📊 View Dashboard</span>
            </label>
            <label class="perm-item">
                <input type="checkbox" name="perm_support" value="1">
                <span>💬 Manage Support</span>
            </label>
            <label class="perm-item">
                <input type="checkbox" name="perm_content" value="1">
                <span>⚙️ Edit Site Content</span>
            </label>
            <label class="perm-item">
                <input type="checkbox" name="perm_checkout" value="1">
                <span>💳 Checkout Settings</span>
            </label>
            <label class="perm-item">
                <input type="checkbox" name="perm_branding" value="1">
                <span>🎬 Manage Branding (Slider)</span>
            </label>
            <label class="perm-item">
                <input type="checkbox" name="perm_orders" value="1">
                <span>📦 Manage Orders</span>
            </label>

        </div>

        <?php // ── Confirmation ────────────────────────────────── ?>
        <h5 class="mb-3">🔒 Confirmation</h5>
        <div class="row g-3 mb-4">

            <div class="col-12">
                <div class="float-group">
                    <textarea id="newAdmReason"
                              name="add_reason"
                              rows="3"
                              placeholder=" "></textarea>
                    <label for="newAdmReason">Reason for adding this admin <span class="text-danger">*</span></label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="float-group position-relative">
                    <input type="password"
                           id="newAdmCurrentPass"
                           name="confirm_current_pass"
                           placeholder=" "
                           required
                           class="u-input-pad-end">
                    <label for="newAdmCurrentPass">Your Current Password <span class="text-danger">*</span></label>
                    <button type="button"
                            id="toggleNewAdmCurrentPass"
                            class="btn btn-sm position-absolute u-input-action"
                            tabindex="-1"
                            aria-label="Show/Hide Password">👁</button>
                </div>
            </div>

        </div>

        <?php // ── Submit (hidden at first; JavaScript reveals it once the fields are complete) ── ?>
        <div class="d-flex justify-content-end">
            <button type="submit"
                    id="addAdminBtn"
                    class="btn btn-success btn-lg px-5 d-none">
                ✅ Add Admin
            </button>
        </div>

    </form>
</div>
