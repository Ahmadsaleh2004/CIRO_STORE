<?php
/**
 * app/views/admin/product/edit.php — fragment فقط
 * المتغيرات من AdminProductsController::showEdit():
 *   $product   — بيانات المنتج (مع category_ids + variants من findByIdWithCategories)
 *   $categories, $formErr, $csrf
 */
$p        = $product;
$variants = $p['variants'] ?? [];
?>

<?php // ── Page Header ────────────────────────────────────────── ?>
<div class="admin-page-header">
    <h1>✏️ Edit: <?= htmlspecialchars($p['name']) ?></h1>
    <a href="<?= URLROOT ?>/admin/products" class="btn btn-secondary btn-sm">← Back to Products</a>
</div>

<?php if (!empty($formErr)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($formErr) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="card p-4">
<form id="editProductForm"
      method="POST"
      action="<?= URLROOT ?>/admin/products/edit"
      enctype="multipart/form-data"
      novalidate>

    <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="product_id"  value="<?= (int)$p['id'] ?>">

    <?php // ══ Shared Info ══════════════════════════════════════════ ?>
    <h5 class="mb-3">Shared Info</h5>
    <div class="row g-3 mb-4">

        <div class="col-12 col-md-6">
            <div class="float-group">
                <input type="text"
                       name="name"
                       id="productName"
                       placeholder=" "
                       required
                       maxlength="200"
                       value="<?= htmlspecialchars($p['name']) ?>">
                <label for="productName">Product Name <span class="text-danger">*</span></label>
            </div>
        </div>

        <div class="col-12">
            <div class="float-group">
                <textarea name="description"
                          id="productDesc"
                          rows="3"
                          placeholder=" "><?= htmlspecialchars($p['description'] ?? '') ?></textarea>
                <label for="productDesc">Description</label>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="float-group">
                <input type="text"
                       name="country"
                       id="productCountry"
                       placeholder=" "
                       maxlength="80"
                       value="<?= htmlspecialchars($p['country_of_origin'] ?? '') ?>">
                <label for="productCountry">Country of Origin</label>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="float-group">
                <input type="text"
                       name="manufacturer"
                       id="productManufacturer"
                       placeholder=" "
                       maxlength="100"
                       value="<?= htmlspecialchars($p['manufacturer'] ?? '') ?>">
                <label for="productManufacturer">Brand / Manufacturer</label>
            </div>
        </div>

    </div>

    <?php // ══ Categories ═══════════════════════════════════════════ ?>
    <label class="small fw-bold mb-2 d-block">
        Categories <span class="text-danger">*</span>
    </label>
    <button type="button"
            class="btn btn-outline-secondary btn-sm mb-2"
            id="openCategoryPickerBtn">
        🏷️ Choose Categories
    </button>
    <div id="selectedCategoriesChips" class="d-flex flex-wrap gap-2 mb-2"></div>
    <div id="categoryHiddenInputs"></div>
    <div class="form-text text-danger mb-3 d-none"
         id="categoryRequiredError">
        Please select at least one category.
    </div>

    <?php // ══ Product Image (اختياري بصفحة Edit) ══════════════════ ?>
    <div class="mb-4">
        <label class="fw-bold d-block mb-1">Product Image</label>
        <?php if (!empty($p['image_path'])): ?>
        <div class="mb-2">
            <img src="<?= htmlspecialchars(fixImagePath($p['image_path'])) ?>"
                 alt="Current image"
                 class="u-thumb-80">
        </div>
        <p class="small text-muted mb-2">
            Current image shown above. Upload a new one to replace it, or leave empty to keep the current image.
        </p>
        <?php else: ?>
        <p class="small text-muted mb-2">No image yet. Upload one below.</p>
        <?php endif; ?>
        <input type="file"
               name="variants[0][image]"
               class="form-control"
               accept="image/jpeg,image/png,image/webp">
    </div>

    <?php // ══ Colors (صفوف موجودة + إضافة جديدة) ════════ ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Colors <span class="text-danger">*</span></h5>
        <button type="button"
                class="btn btn-outline-success btn-sm"
                id="addVariantBtn">+ Add Color</button>
    </div>

    <div id="variantsContainer">
        <?php foreach ($variants as $i => $v): ?>
        <div class="variant-row card p-3 mb-3 u-surface-page">

            <?php // Hidden: variant ID + صورة موجودة ?>
            <?php if (!empty($v['id'])): ?>
            <input type="hidden"
                   class="field-id"
                   name="variants[<?= $i ?>][id]"
                   value="<?= (int)$v['id'] ?>">
            <?php endif; ?>
            <input type="hidden"
                   name="variants[<?= $i ?>][existing_image]"
                   value="<?= htmlspecialchars($v['image_path'] ?? '') ?>">

            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold small text-muted">
                    Color <span class="variant-number"><?= $i + 1 ?></span>
                </span>
                <button type="button"
                        class="btn btn-sm btn-outline-danger remove-variant-btn">
                    ✕ Remove
                </button>
            </div>

            <div class="row g-2">
                <?php // Color Name ?>
                <div class="col-12 col-sm-4">
                    <div class="float-group">
                        <input type="text"
                               class="field-color-name"
                               name="variants[<?= $i ?>][color_name]"
                               placeholder=" "
                               required
                               maxlength="50"
                               value="<?= htmlspecialchars($v['color_name']) ?>">
                        <label>Color Name <span class="text-danger">*</span></label>
                    </div>
                </div>
                <?php // Color Swatch ?>
                <div class="col-4 col-sm-2">
                    <label class="small d-block mb-1 u-fs-75">Swatch</label>
                    <input type="color"
                           class="field-color-hex form-control form-control-sm u-color-input"
                           name="variants[<?= $i ?>][color_hex]"
                           value="<?= htmlspecialchars($v['color_hex'] ?? '#000000') ?>">
                </div>
                <?php // Price ?>
                <div class="col-4 col-sm-2">
                    <div class="float-group">
                        <input type="number"
                               class="field-price"
                               name="variants[<?= $i ?>][price]"
                               placeholder=" "
                               min="0.01"
                               step="0.01"
                               required
                               value="<?= htmlspecialchars($v['price']) ?>">
                        <label>Price ($) <span class="text-danger">*</span></label>
                    </div>
                </div>
                <?php // Discount ?>
                <div class="col-4 col-sm-2">
                    <div class="float-group">
                        <input type="number"
                               class="field-discount"
                               name="variants[<?= $i ?>][discount]"
                               placeholder=" "
                               min="0"
                               max="100"
                               step="0.1"
                               value="<?= htmlspecialchars($v['discount_percentage'] ?? 0) ?>">
                        <label>Discount %</label>
                    </div>
                </div>
                <?php // Stock ?>
                <div class="col-4 col-sm-2">
                    <div class="float-group">
                        <input type="number"
                               class="field-stock"
                               name="variants[<?= $i ?>][stock]"
                               placeholder=" "
                               min="0"
                               value="<?= (int)($v['stock_quantity'] ?? 0) ?>">
                        <label>Stock</label>
                    </div>
                </div>
                <?php // Gender ?>
                <div class="col-6 col-sm-3">
                    <div class="float-group">
                        <select class="field-gender"
                                name="variants[<?= $i ?>][gender]">
                            <?php foreach (['both' => 'Both', 'male' => 'Male', 'female' => 'Female'] as $val => $lbl): ?>
                            <option value="<?= $val ?>"
                                    <?= ($v['gender_category'] ?? 'both') === $val ? 'selected' : '' ?>>
                                <?php // @escaping-safe: $lbl من مصفوفة حرفية في حلقة هذا الملف ?>
                                <?= $lbl ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <label>Gender</label>
                    </div>
                </div>
                <?php // Image — Replace (optional) ?>
                <div class="col-12 col-sm-6">
                    <label class="small d-block mb-1">
                        Replace Image
                        <span class="text-muted">(optional — leave empty to keep current)</span>
                    </label>
                    <?php if (!empty($v['image_path'])): ?>
                    <img src="<?= htmlspecialchars(fixImagePath($v['image_path'])) ?>"
                         alt="current"
                         class="u-variant-thumb">
                    <?php endif; ?>
                    <input type="file"
                           class="field-image form-control form-control-sm"
                           name="variants[<?= $i ?>][image]"
                           accept="image/jpeg,image/png,image/webp">
                </div>
                <?php // Default Radio ?>
                <div class="col-6 col-sm-3 d-flex align-items-center">
                    <div class="form-check mb-0">
                        <input type="radio"
                               class="form-check-input field-default"
                               name="default_variant"
                               value="<?= $i ?>"
                               <?= ($v['is_default'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label small">Default Color</label>
                    </div>
                </div>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <?php // Template: لصفوف الألوان الجديدة المُضافة بـ JS ?>
    <template id="variantRowTemplate">
        <div class="variant-row card p-3 mb-3 u-surface-page">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold small text-muted">
                    Color <span class="variant-number"></span>
                </span>
                <button type="button"
                        class="btn btn-sm btn-outline-danger remove-variant-btn">
                    ✕ Remove
                </button>
            </div>
            <div class="row g-2">
                <div class="col-12 col-sm-4">
                    <div class="float-group">
                        <input type="text" class="field-color-name" placeholder=" " required maxlength="50">
                        <label>Color Name <span class="text-danger">*</span></label>
                    </div>
                </div>
                <div class="col-4 col-sm-2">
                    <label class="small d-block mb-1 u-fs-75">Swatch</label>
                    <input type="color" class="field-color-hex form-control form-control-sm u-color-input"
                           value="#000000">
                </div>
                <div class="col-4 col-sm-2">
                    <div class="float-group">
                        <input type="number" class="field-price" placeholder=" " min="0.01" step="0.01" required>
                        <label>Price ($) <span class="text-danger">*</span></label>
                    </div>
                </div>
                <div class="col-4 col-sm-2">
                    <div class="float-group">
                        <input type="number" class="field-discount" placeholder=" " min="0" max="100" step="0.1" value="0">
                        <label>Discount %</label>
                    </div>
                </div>
                <div class="col-4 col-sm-2">
                    <div class="float-group">
                        <input type="number" class="field-stock" placeholder=" " min="0" value="0">
                        <label>Stock</label>
                    </div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="float-group">
                        <select class="field-gender">
                            <option value="both">Both</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                        <label>Gender</label>
                    </div>
                </div>
                <div class="col-12 col-sm-6">
                    <label class="small d-block mb-1">
                        Color Image <span class="text-muted">(optional)</span>
                    </label>
                    <input type="file" class="field-image form-control form-control-sm"
                           accept="image/jpeg,image/png,image/webp">
                </div>
                <div class="col-6 col-sm-3 d-flex align-items-center">
                    <div class="form-check mb-0">
                        <input type="radio" class="form-check-input field-default"
                               name="default_variant" value="0">
                        <label class="form-check-label small">Default Color</label>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <?php // ══ Submit ═══════════════════════════════════════════════ ?>
    <div class="mt-4 d-flex gap-2 flex-wrap">
        <button type="submit"
                name="save_product"
                id="saveProductBtn"
                class="btn btn-success">
            💾 Save Changes
        </button>
        <a href="<?= URLROOT ?>/admin/products"
           class="btn btn-outline-secondary">
            Cancel
        </a>
    </div>

</form>
</div>

<?php // بيانات الكاتوجريز الحالية للمنتج — تُقرأ من category-picker.js ?>
<?= pageData([
    '_currentCategoryIds' => array_map('intval', $p['category_ids'] ?? []),
]) ?>

<?php // ── Category Picker Modal ─────────────────────────────── ?>
<?php include __DIR__ . '/_category-picker-modal.php'; ?>
