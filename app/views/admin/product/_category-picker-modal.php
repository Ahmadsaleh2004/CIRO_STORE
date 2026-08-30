<?php
/**
 * app/views/admin/product/_category-picker-modal.php
 * A modal shared between product/add.php and product/edit.php.
 * It has a single mode: select (add / delete / choose).
 *
 * The injected data:
 *   $categories — from CategoryModel::getAllOrdered()
 *   $csrf       — injected automatically by adminView()
 */
?>

<?php // The categories' data, handed to category-picker.js ?>
<?= pageData([
    '_categoriesData' => array_map(fn($c) => [
        'id'            => (int) $c['id'],
        'name'          => $c['name'],
        'is_core'       => (bool) $c['is_core'],
        'product_count' => (int) $c['product_count'],
    ], $categories ?? []),
]) ?>

<?php /*
═══════════════════════════════════════════════════════
     The main modal: choosing, adding and deleting categories
     ═══════════════════════════════════════════════════════
*/ ?>
<div class="modal fade"
     id="categoryPickerModal"
     tabindex="-1"
     aria-labelledby="categoryPickerModalLabel"
     aria-modal="true"
     role="dialog">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header u-border-section">
                <h5 class="modal-title" id="categoryPickerModalLabel">🏷️ Choose Categories</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <?php // The category list in the .perm-grid/.perm-item style — the same as manage-admins ?>
                <div class="perm-grid mb-4" id="categoryPickerList">
                    <?php // Built dynamically by category-picker.js ?>
                    <div class="text-center py-2 u-muted">
                        <span class="spinner-border spinner-border-sm"></span> Loading...
                    </div>
                </div>

                <hr class="u-border-section">

                <?php // Add a new category ?>
                <div class="mb-1">
                    <label class="fw-bold small d-block mb-2">Add New Category</label>
                    <div class="input-group">
                        <input type="text"
                               id="newCategoryInput"
                               class="form-control"
                               placeholder="Type category name..."
                               maxlength="50"
                               autocomplete="off">
                        <button type="button"
                                class="btn btn-success"
                                id="addCategoryBtn">+ Add</button>
                    </div>
                    <div id="categorySuggestions"
                         class="small mt-1 u-picker-hint"></div>
                </div>

            </div>

            <div class="modal-footer u-border-section">
                <button type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">Close</button>
                <button type="button"
                        class="btn btn-primary"
                        id="confirmCategorySelectionBtn">
                    ✅ Confirm Selection
                </button>
            </div>

        </div>
    </div>
</div>

<?php /*
═══════════════════════════════════════════════════════
     A secondary modal: confirming a category deletion and choosing the destination
     ═══════════════════════════════════════════════════════
*/ ?>
<div class="modal fade"
     id="categoryDeleteModal"
     tabindex="-1"
     aria-labelledby="categoryDeleteModalLabel"
     aria-modal="true"
     role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header u-border-section">
                <h5 class="modal-title text-danger" id="categoryDeleteModalLabel">🗑 Delete Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="mb-3">
                    Move all products from
                    <strong id="delCatName" class="u-accent"></strong>
                    to:
                </p>
                <div class="float-group">
                    <select id="delCatDestination" class="form-select">
                        <?php // Filled dynamically ?>
                    </select>
                    <label>Destination Category <span class="text-danger">*</span></label>
                </div>
                <p class="small mt-3 text-muted">
                    ⚠️ This action cannot be undone.
                    Core categories (Phone, Computer, Accessories, Gaming) cannot be deleted.
                </p>
            </div>

            <div class="modal-footer u-border-section">
                <button type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="button"
                        class="btn btn-danger"
                        id="confirmCategoryDeleteBtn">
                    🗑 Delete &amp; Move Products
                </button>
            </div>

        </div>
    </div>
</div>

<input type="hidden" id="deletingCategoryId" value="">
