<?php
/**
 * app/views/admin/product/_category-picker-modal.php
 * Modal مشترك بين product/add.php, product/edit.php
 * يعمل بوضع واحد فقط: select (إضافة/حذف/اختيار)
 *
 * البيانات المحقونة:
 *   $categories — من CategoryModel::getAllOrdered()
 *   $csrf       — من adminView() تلقائياً
 */
?>

<!-- بيانات الكاتوجريز محقونة لـ category-picker.js -->
<?= pageData([
    '_categoriesData' => array_map(fn($c) => [
        'id'            => (int) $c['id'],
        'name'          => $c['name'],
        'is_core'       => (bool) $c['is_core'],
        'product_count' => (int) $c['product_count'],
    ], $categories ?? []),
]) ?>

<!-- ═══════════════════════════════════════════════════════
     Modal الرئيسي: اختيار + إضافة + حذف الكاتوجريز
     ═══════════════════════════════════════════════════════ -->
<div class="modal fade"
     id="categoryPickerModal"
     tabindex="-1"
     aria-labelledby="categoryPickerModalLabel"
     aria-modal="true"
     role="dialog">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header" style="border-color:var(--section-border);">
                <h5 class="modal-title" id="categoryPickerModalLabel">🏷️ Choose Categories</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <!-- قائمة الكاتوجريز بنمط .perm-grid/.perm-item — نفس نمط manage-admins -->
                <div class="perm-grid mb-4" id="categoryPickerList">
                    <!-- تُبنى ديناميكياً من category-picker.js -->
                    <div class="text-center py-2" style="color:var(--muted-text);">
                        <span class="spinner-border spinner-border-sm"></span> Loading...
                    </div>
                </div>

                <hr style="border-color:var(--section-border);">

                <!-- إضافة كاتوجري جديدة -->
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
                         class="small mt-1"
                         style="color:var(--muted-text);min-height:18px;"></div>
                </div>

            </div>

            <div class="modal-footer" style="border-color:var(--section-border);">
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

<!-- ═══════════════════════════════════════════════════════
     Modal فرعي: تأكيد حذف كاتوجري + اختيار الوجهة
     ═══════════════════════════════════════════════════════ -->
<div class="modal fade"
     id="categoryDeleteModal"
     tabindex="-1"
     aria-labelledby="categoryDeleteModalLabel"
     aria-modal="true"
     role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header" style="border-color:var(--section-border);">
                <h5 class="modal-title text-danger" id="categoryDeleteModalLabel">🗑 Delete Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="mb-3">
                    Move all products from
                    <strong id="delCatName" style="color:var(--accent);"></strong>
                    to:
                </p>
                <div class="float-group">
                    <select id="delCatDestination" class="form-select">
                        <!-- تُملأ ديناميكياً -->
                    </select>
                    <label>Destination Category <span class="text-danger">*</span></label>
                </div>
                <p class="small mt-3 text-muted">
                    ⚠️ This action cannot be undone.
                    Core categories (Phone, Computer, Accessories, Gaming) cannot be deleted.
                </p>
            </div>

            <div class="modal-footer" style="border-color:var(--section-border);">
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
