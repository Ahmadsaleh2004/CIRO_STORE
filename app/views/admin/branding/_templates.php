<?php
/**
 * app/views/admin/branding/_templates.php — two <template> blocks cloned from JavaScript.
 * The real name attributes are absent from the templates — they are built dynamically at
 * submission time (renameAllFieldsBeforeSubmit in branding.js), because each slide's and
 * item's index changes.
 */
?>

<?php // ══ The slide template ═════════════════════════════════════════════════ ?>
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

<?php // ══ The item / image template ══════════════════════════════════════════ ?>
<template id="itemTemplate">
    <div class="slide-item-card card p-2 u-w-260 u-border-section-all">

        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="small fw-semibold text-muted">Image <span class="item-number"></span></span>
            <button type="button" class="btn btn-sm btn-outline-danger remove-item-btn" aria-label="Remove item">✕</button>
        </div>

        <?php // The buttons switching between the two tabs — they control visibility alone ?>
        <div class="btn-group w-100 mb-2" role="group">
            <button type="button" class="btn btn-outline-primary btn-sm mode-toggle-btn active"
                    data-mode="product">🛍️ Product</button>
            <button type="button" class="btn btn-outline-primary btn-sm mode-toggle-btn"
                    data-mode="manual">🖼️ Manual</button>
        </div>

        <?php // ══ The Product tab ════════════════════════════════ ?>
        <div class="mode-panel product-panel">
            <button type="button" class="btn btn-outline-secondary btn-sm w-100 mb-2 open-product-picker-btn">
                🔍 Choose Product
            </button>

            <div class="product-preview d-none mb-2 text-center">
                <img class="product-preview-img u-thumb-preview" src="" alt="">
                <div class="small fw-semibold product-preview-name mt-1"></div>
            </div>
            <input type="hidden" class="field-product-id" value="">

            <?php /*
The image shows two lines: the title above the description. Both fields
                 are filled automatically when a product is chosen (the name and
                 description from the database) and stay editable — see selectProduct in
                 js/admin/branding.js.

                 Leaving the title empty is not an omission: the read falls back to
                 products.name, so the slide always shows its product's name. The field
                 exists to shorten a long name, not to repeat it.
*/ ?>
            <div class="float-group mb-2">
                <input type="text" class="field-product-title" placeholder=" " maxlength="200">
                <label class="small">Title on image (defaults to product name)</label>
            </div>
            <div class="float-group mb-2">
                <textarea class="field-product-description" rows="2" placeholder=" " maxlength="500"></textarea>
                <label class="small">Description — second line, smaller</label>
            </div>
            <div class="float-group mb-2">
                <input type="text" class="field-product-link" placeholder=" ">
                <label class="small">Link (optional)</label>
            </div>

            <button type="button" class="btn btn-sm w-100 default-btn" data-panel="product">
                ⭐ Set as Default
            </button>
        </div>

        <?php // ══ The Manual tab ═════════════════════════════════ ?>
        <div class="mode-panel manual-panel d-none">
            <div class="manual-preview d-none mb-2 text-center">
                <img class="manual-preview-img u-thumb-preview" src="" alt="">
            </div>
            <input type="file" class="field-manual-image form-control form-control-sm mb-2"
                   accept="image/jpeg,image/png,image/webp,image/gif">
            <input type="hidden" class="field-existing-manual-image" value="">

            <?php /*
⚠️ No automatic filling here — unlike the Product panel.

                 A manual image is a file the admin uploads, with no data source to
                 derive a title or description from. So both fields stay empty until they
                 are typed, and an empty title means no title line at all — there is no
                 fallback for it, as there is in product mode.
*/ ?>
            <div class="float-group mb-2">
                <input type="text" class="field-manual-title" placeholder=" " maxlength="200">
                <label class="small">Title on image</label>
            </div>
            <div class="float-group mb-2">
                <textarea class="field-manual-description" rows="2" placeholder=" " maxlength="500"></textarea>
                <label class="small">Description — second line, smaller</label>
            </div>
            <div class="float-group mb-2">
                <input type="text" class="field-manual-link" placeholder=" ">
                <label class="small">Link (optional)</label>
            </div>

            <button type="button" class="btn btn-sm w-100 default-btn" data-panel="manual">
                ⭐ Set as Default
            </button>
        </div>

        <div class="small mt-2 active-mode-indicator"></div>

    </div>
</template>