<?php
/**
 * app/views/account/my-info.php
 * صفحة حسابي — 3 تبويبات: بياناتي | طلباتي | عناويني
 * البيانات تأتي جاهزة من MyInfoController::index()
 * هذه صفحة اليوزر العادي حصرًا. الأدمن له صفحة منفصلة كليًا: views/admin/my-info.php
 */
?>
<main id="main-content" class="container py-5">

    <nav class="store-breadcrumb mb-4">
        <a href="<?= URLROOT ?>">🏠 Home</a>
        <span class="sep">/</span>
        <span class="current">My Account</span>
    </nav>

    <div class="d-flex align-items-center gap-3 mb-4">
        <div style="font-size:3rem;">👤</div>
        <div>
            <h1 class="fw-bold mb-0"><?= htmlspecialchars($user['full_name'] ?? '') ?></h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($user['email'] ?? '') ?></p>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="myInfoTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-profile" type="button">
                👤 My Info
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-orders" type="button">
                📦 My Orders <span class="badge bg-secondary"><?= count($orders ?? []) ?></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-addresses" type="button">
                📍 Addresses <span class="badge bg-secondary"><?= count($addresses ?? []) ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ── تبويب بياناتي ──────────────────────────── -->
        <div class="tab-pane fade show active" id="tab-profile">
            <div class="card p-4" style="max-width:550px;">
                <h4 class="mb-4">✏️ Edit Profile</h4>
                <form id="profileForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <div class="float-group">
                        <input type="text" name="full_name" id="profileName"
                               value="<?= htmlspecialchars($user['full_name'] ?? '') ?>"
                               placeholder=" " required autocomplete="name">
                        <label>Full Name</label>
                    </div>
                    <div class="float-group">
                        <input type="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                               placeholder=" " disabled
                               style="opacity:.6;cursor:not-allowed;">
                        <label>Email Address <small class="text-muted">(cannot change)</small></label>
                    </div>
                    
                    <!-- Phone Number — نفس الـpartial المستعمل في صفحة الأدمن -->
                    <div class="float-group mb-3">
                        <?php
                        $phoneValue   = $user['phone_number'] ?? '';
                        $phoneInputId = 'profilePhone';
                        require APPROOT . '/views/shared/phone-input.php';
                        ?>
                        <label class="phone-group-label">Phone Number</label>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="float-group">
                                <input type="text" name="country" id="profileCountry"
                                       value="<?= htmlspecialchars($user['country'] ?? '') ?>"
                                       placeholder=" " autocomplete="country-name">
                                <label>Country</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="float-group">
                                <input type="text" name="city" id="profileCity"
                                       value="<?= htmlspecialchars($user['city'] ?? '') ?>"
                                       placeholder=" " autocomplete="address-level2">
                                <label>City</label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- New Password (optional) -->
                    <div class="float-group mb-3">
                        <input type="password" name="new_password" id="newPassword"
                               placeholder=" " autocomplete="new-password" minlength="8" maxlength="128">
                        <label>New Password <small class="text-muted">(leave blank to keep current)</small></label>
                        <button type="button" class="btn btn-sm btn-link toggle-password-btn" data-target="newPassword"
                                style="position:absolute; left:8px; top:8px;" tabindex="-1">👁</button>
                    </div>
                    
                    <!-- Current Password — always required to save -->
                    <div class="float-group mb-3">
                        <input type="password" name="current_password" id="currentPassword"
                               placeholder=" " required autocomplete="current-password" maxlength="128">
                        <label>Current Password <span class="text-danger">*</span> <small class="text-muted">(required to save any change)</small></label>
                        <button type="button" class="btn btn-sm btn-link toggle-password-btn" data-target="currentPassword"
                                style="position:absolute; left:8px; top:8px;" tabindex="-1">👁</button>
                    </div>
                    
                    <div id="profileMsg" class="alert py-2 small" style="display:none;"></div>
                    <button type="submit" class="btn btn-success w-100">💾 Save Changes</button>
                </form>
            </div>
        </div>

        <!-- ── تبويب طلباتي ───────────────────────────── -->
        <div class="tab-pane fade" id="tab-orders">
            <?php if (empty($orders)): ?>
                <div class="text-center py-5">
                    <div style="font-size:3rem;">📦</div>
                    <p class="text-muted mt-2">No orders yet.</p>
                    <a href="<?= URLROOT ?>/products" class="btn btn-warning">Start Shopping</a>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                <?php foreach ($orders as $order): ?>
                <div class="card p-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <strong>Order #<?= htmlspecialchars((string)$order['order_id']) ?></strong>
                            <?php
                            $orderStatus     = $order['status'];
                            $badgeExtraClass = 'ms-2';
                            $badgeLabel      = '';
                            require APPROOT . '/views/shared/order-status-badge.php';
                            ?>
                        </div>
                        <div class="text-end">
                            <strong>$<?= number_format((float)$order['total_amount'], 2) ?></strong><br>
                            <small class="text-muted"><?= date('d M Y', strtotime($order['created_at'])) ?></small>
                        </div>
                    </div>

                    <?php if (!empty($order['items'])): ?>
                    <ul class="list-unstyled small mt-2 mb-2">
                        <?php foreach ($order['items'] as $item): ?>
                        <li class="d-flex justify-content-between">
                            <span><?= htmlspecialchars($item['product_name'] ?? '—') ?>
                                <?= $item['color_name'] ? '— ' . htmlspecialchars($item['color_name']) : '' ?>
                                × <?= (int)$item['quantity'] ?>
                            </span>
                            <span>$<?= number_format((float)$item['price_at_purchase'] * $item['quantity'], 2) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php if ($order['status'] === 'not_taken'): ?>
                    <div class="mt-2">
                        <?php $orderId = (int)$order['order_id']; $orderContext = 'user'; ?>
                        <?php include APPROOT . '/views/shared/order-cancel-button.php'; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── تبويب عناويني ──────────────────────────── -->
        <div class="tab-pane fade" id="tab-addresses">
            <div class="row g-3 mb-4">
                <?php if (empty($addresses)): ?>
                <p class="text-muted">No saved addresses yet.</p>
                <?php else: ?>
                <?php foreach ($addresses as $addr): ?>
                <div class="col-md-6">
                    <div class="card p-3 h-100">
                        <div class="d-flex justify-content-between">
                            <strong><?= htmlspecialchars($addr['label'] ?? 'Home') ?></strong>
                            <?php if ($addr['is_default']): ?>
                            <span class="badge bg-success">Default</span>
                            <?php endif; ?>
                        </div>
                        <p class="mb-1 small mt-1"><?= htmlspecialchars($addr['full_address']) ?></p>
                        <p class="mb-1 small text-muted">
                            <?= htmlspecialchars($addr['city'] ?? '') ?>
                            <?= ($addr['city'] && $addr['country']) ? ', ' : '' ?>
                            <?= htmlspecialchars($addr['country'] ?? '') ?>
                        </p>
                        <?php if ($addr['phone_number']): ?>
                        <p class="mb-2 small text-muted">📞 <?= htmlspecialchars($addr['phone_number']) ?></p>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-danger delete-addr-btn"
                                data-address-id="<?= (int)$addr['id'] ?>"
                                data-csrf="<?= htmlspecialchars($csrf) ?>">
                            🗑️ Delete
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- إضافة عنوان جديد -->
            <div class="card p-4" style="max-width:600px;">
                <h5 class="mb-3">➕ Add New Address</h5>
                <form id="addAddrForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="float-group">
                                <input type="text" name="label" placeholder=" ">
                                <label>Label (Home / Work…)</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="float-group">
                                <input type="tel" name="phone_number" placeholder=" ">
                                <label>Phone Number</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="float-group">
                                <input type="text" name="country" placeholder=" ">
                                <label>Country</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="float-group">
                                <input type="text" name="city" placeholder=" ">
                                <label>City</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="float-group">
                                <textarea name="full_address" rows="2" placeholder=" " required></textarea>
                                <label>Full Address <span class="text-danger">*</span></label>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <input type="checkbox" id="addrIsDefault" name="is_default" value="1"
                               style="width:16px;height:16px;">
                        <label for="addrIsDefault" class="small">Set as default</label>
                    </div>
                    <div id="addrMsg" class="alert py-2 small" style="display:none;"></div>
                    <button type="submit" class="btn btn-outline-success">💾 Save Address</button>
                </form>
            </div>
        </div>

    </div><!-- /tab-content -->
</main>

<?php
// كانت هنا كتلة <script> تعرّف ثابتين ثم تنسخ أحدهما إلى window.
// الثابتان كانا في النطاق العام أصلاً (كتلة غير مغلَّفة)، فالنتيجة
// واحدة — و js/features/account.js يقرأ window.URLROOT_INFO و
// window.CSRF_INFO، لا الثابتين.
?>
<?= pageData([
    'URLROOT_INFO' => URLROOT,
    'CSRF_INFO'    => $csrf,
]) ?>
