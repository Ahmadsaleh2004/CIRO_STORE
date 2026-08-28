<div class="modal fade" id="productPickerModal" tabindex="-1"
     aria-labelledby="productPickerModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">

            <div class="modal-header u-border-section">
                <h5 class="modal-title" id="productPickerModalLabel">🔍 Choose a Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <input type="text" id="productPickerSearchInput"
                       class="form-control mb-3"
                       placeholder="Search products by name..." autocomplete="off">

                <div id="productPickerResults" class="d-flex flex-column gap-2">
                    <div class="text-center py-3 text-muted" id="productPickerEmptyHint">
                        Start typing to search for a product…
                    </div>
                </div>
            </div>

            <div class="modal-footer u-border-section">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>