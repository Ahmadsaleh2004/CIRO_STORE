<?php
/**
 * app/views/admin/product/add.php — fragment فقط
 * المتغيرات من AdminProductsController::showAdd():
 *   $categories, $formErr, $csrf
 */
?>

<!-- ── Page Header ────────────────────────────────────────── -->
<div class="admin-page-header">
    <h1>➕ Add New Product</h1>
    <a href="<?= URLROOT ?>/admin/products" class="btn btn-secondary btn-sm">← Back to Products</a>
</div>

<?php if (!empty($formErr)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($formErr) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="card p-4">
<form id="addProductForm"
      method="POST"
      action="<?= URLROOT ?>/admin/products/add"
      enctype="multipart/form-data"
      novalidate>

    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <!-- ══ Shared Info ══════════════════════════════════════════ -->
    <h5 class="mb-3">Shared Info</h5>
    <div class="row g-3 mb-4">

        <div class="col-12 col-md-6">
            <div class="float-group">
                <input type="text" name="name" id="productName" placeholder=" " required maxlength="200">
                <label for="productName">Product Name <span class="text-danger">*</span></label>
            </div>
        </div>

        <div class="col-12">
            <div class="float-group">
                <textarea name="description" id="productDesc" rows="3" placeholder=" "></textarea>
                <label for="productDesc">Description</label>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="float-group">
                <input type="text" name="country" id="productCountry" placeholder=" " maxlength="80">
                <label for="productCountry">Country of Origin</label>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="float-group">
                <input type="text" name="manufacturer" id="productManufacturer" placeholder=" " maxlength="100">
                <label for="productManufacturer">Brand / Manufacturer</label>
            </div>
        </div>

    </div>

    <!-- ══ Categories (الفرق الوحيد عن القديم: popup بدل checkboxes ظاهرة) ════ -->
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
    <div class="form-text text-danger mb-3"
         id="categoryRequiredError"
         class="d-none">
        Please select at least one category.
    </div>

    <!-- ══ Product Image (إجباري بصفحة Add) ════════════════════ -->
    <div class="mb-4">
        <label class="fw-bold d-block mb-1">
            Product Image <span class="text-danger">*</span>
        </label>
        <p class="small text-muted mb-2">
            Upload the main image for the first (default) color variant.
        </p>
        <input type="file"
               name="variants[0][image]"
               id="mainImageInput"
               class="form-control"
               accept="image/jpeg,image/png,image/webp"
               required>
    </div>

    <!-- ══ Colors ══════════════════════════════════════ -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Colors <span class="text-danger">*</span></h5>
        <button type="button"
                class="btn btn-outline-success btn-sm"
                id="addVariantBtn">+ Add Color</button>
    </div>

    <div id="variantsContainer">
        <!-- صفوف الألوان تُضاف تلقائياً عند DOMContentLoaded من products.js -->
    </div>

    <!-- Template: مخفي، يُستنسخ بـ products.js::initAddProductForm -->
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
                <!-- Color Name -->
                <div class="col-12 col-sm-4">
                    <div class="float-group">
                        <input type="text"
                               class="field-color-name"
                               placeholder=" "
                               required
                               maxlength="50">
                        <label>Color Name <span class="text-danger">*</span></label>
                    </div>
                </div>
                <!-- Color Swatch -->
                <div class="col-4 col-sm-2">
                    <label class="small d-block mb-1 u-fs-75">Swatch</label>
                    <input type="color"
                           class="field-color-hex form-control form-control-sm"
                           class="u-color-input"
                           value="#000000">
                </div>
                <!-- Price -->
                <div class="col-4 col-sm-2">
                    <div class="float-group">
                        <input type="number"
                               class="field-price"
                               placeholder=" "
                               min="0.01"
                               step="0.01"
                               required>
                        <label>Price ($) <span class="text-danger">*</span></label>
                    </div>
                </div>
                <!-- Discount -->
                <div class="col-4 col-sm-2">
                    <div class="float-group">
                        <input type="number"
                               class="field-discount"
                               placeholder=" "
                               min="0"
                               max="100"
                               step="0.1"
                               value="0">
                        <label>Discount %</label>
                    </div>
                </div>
                <!-- Stock -->
                <div class="col-4 col-sm-2">
                    <div class="float-group">
                        <input type="number"
                               class="field-stock"
                               placeholder=" "
                               min="0"
                               value="0">
                        <label>Stock</label>
                    </div>
                </div>
                <!-- Gender -->
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
                <!-- Image Upload -->
                <div class="col-12 col-sm-6">
                    <label class="small d-block mb-1">
                        Color Image
                        <span class="text-muted">(optional — overrides main image)</span>
                    </label>
                    <input type="file"
                           class="field-image form-control form-control-sm"
                           accept="image/jpeg,image/png,image/webp">
                </div>
                <!-- Default Radio -->
                <div class="col-6 col-sm-3 d-flex align-items-center">
                    <div class="form-check mb-0">
                        <input type="radio"
                               class="form-check-input field-default"
                               name="default_variant"
                               value="0">
                        <label class="form-check-label small">Default Color</label>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <!-- ══ Submit ═══════════════════════════════════════════════ -->
    <div class="mt-4 d-flex gap-2 flex-wrap">
        <button type="submit"
                name="save_product"
                id="saveProductBtn"
                class="btn btn-success">
            ✅ Add Product
        </button>
        <button type="submit"
                name="add_another"
                class="btn btn-outline-success">
            ➕ Add Another
        </button>
        <a href="<?= URLROOT ?>/admin/products"
           class="btn btn-outline-secondary">
            Cancel
        </a>
    </div>

</form>
</div>

<!-- ── Category Picker Modal ─────────────────────────────── -->
<?php include __DIR__ . '/_category-picker-modal.php'; ?>
