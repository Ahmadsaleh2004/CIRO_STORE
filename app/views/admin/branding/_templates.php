<?php
/**
 * app/views/admin/branding/_templates.php — قالبا <template> للاستنساخ بـ JS
 * لا توجد أسماء name الحقيقية بداخل القوالب — تُبنى ديناميكياً عند الإرسال
 * (renameAllFieldsBeforeSubmit بـ branding.js) لأن index كل شريحة/عنصر يتغيّر.
 */
?>

<?php // ══ قالب الشريحة ═══════════════════════════════════════════════════════ ?>
<template id="slideTemplate">
    <div class="slide-card card p-3 mb-4 u-border-section-2">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Slide <span class="slide-number"></span></h6>
            <button type="button"
                    class="btn btn-sm btn-outline-danger remove-slide-btn"
                    title="Delete this slide">🗑️ Delete</button>
        </div>

        <div class="items-container d-flex flex-wrap gap-3 mb-3"></div>

        <button type="button" class="btn btn-outline-success btn-sm add-item-btn">
            ➕ Add Image to this Slide
        </button>
    </div>
</template>

<?php // ══ قالب العنصر/الصورة ═════════════════════════════════════════════════ ?>
<template id="itemTemplate">
    <div class="slide-item-card card p-2 u-w-260 u-border-section-all">

        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="small fw-semibold text-muted">Image <span class="item-number"></span></span>
            <button type="button" class="btn btn-sm btn-outline-danger remove-item-btn" aria-label="Remove item">✕</button>
        </div>

        <?php // أزرار التبديل بين التبويبين — تتحكم فقط بالعرض/الإخفاء البصري ?>
        <div class="btn-group w-100 mb-2" role="group">
            <button type="button" class="btn btn-outline-primary btn-sm mode-toggle-btn active"
                    data-mode="product">🛍️ Product</button>
            <button type="button" class="btn btn-outline-primary btn-sm mode-toggle-btn"
                    data-mode="manual">🖼️ Manual</button>
        </div>

        <?php // ══ تبويب Product ══════════════════════════════════ ?>
        <div class="mode-panel product-panel">
            <button type="button" class="btn btn-outline-secondary btn-sm w-100 mb-2 open-product-picker-btn">
                🔍 Choose Product
            </button>

            <div class="product-preview d-none mb-2 text-center">
                <img class="product-preview-img" src="" alt=""
                     class="u-thumb-preview">
                <div class="small fw-semibold product-preview-name mt-1"></div>
            </div>
            <input type="hidden" class="field-product-id" value="">

            <div class="float-group mb-2">
                <input type="text" class="field-product-link" placeholder=" ">
                <label class="small">Link (optional)</label>
            </div>
            <div class="float-group mb-2">
                <textarea class="field-product-description" rows="2" placeholder=" "></textarea>
                <label class="small">Description shown on image</label>
            </div>

            <button type="button" class="btn btn-sm w-100 default-btn" data-panel="product">
                ⭐ Set as Default
            </button>
        </div>

        <?php // ══ تبويب Manual ═══════════════════════════════════ ?>
        <div class="mode-panel manual-panel d-none">
            <div class="manual-preview d-none mb-2 text-center">
                <img class="manual-preview-img" src="" alt=""
                     class="u-thumb-preview">
            </div>
            <input type="file" class="field-manual-image form-control form-control-sm mb-2"
                   accept="image/jpeg,image/png,image/webp,image/gif">
            <input type="hidden" class="field-existing-manual-image" value="">

            <div class="float-group mb-2">
                <input type="text" class="field-manual-link" placeholder=" ">
                <label class="small">Link (optional)</label>
            </div>
            <div class="float-group mb-2">
                <textarea class="field-manual-description" rows="2" placeholder=" "></textarea>
                <label class="small">Description shown on image</label>
            </div>

            <button type="button" class="btn btn-sm w-100 default-btn" data-panel="manual">
                ⭐ Set as Default
            </button>
        </div>

        <div class="small mt-2 active-mode-indicator"></div>

    </div>
</template>