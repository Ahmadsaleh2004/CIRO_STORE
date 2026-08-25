// ══════════════════════════════════════════════════════════════
// js/admin/products.js — السكريبتات المخصصة لإدارة المنتجات بالكلية
// ══════════════════════════════════════════════════════════════

// ── 1. إدارة الألوان في نموذجي إضافة وتعديل المنتج ──────────────
document.addEventListener('DOMContentLoaded', () => {
    const editForm = document.getElementById('editProductForm');
    const addForm  = document.getElementById('addProductForm');

    if (editForm) {
        initEditProductForm(editForm);
    } else if (addForm) {
        initAddProductForm(addForm);
    }

    initProductsListInteractions();
});

function initEditProductForm(form) {
    const btn       = document.getElementById('saveProductBtn');
    const container = document.getElementById('variantsContainer');
    const template  = document.getElementById('variantRowTemplate');
    const addBtn    = document.getElementById('addVariantBtn');
    if (!form || !btn || !container) return;

    function renumberAndNameFields() {
        container.querySelectorAll('.variant-row').forEach((row, i) => {
            row.querySelector('.variant-number').textContent = i + 1;
            const idField = row.querySelector('.field-id');
            row.querySelector('.field-color-name').name = `variants[${i}][color_name]`;
            row.querySelector('.field-color-hex').name  = `variants[${i}][color_hex]`;
            row.querySelector('.field-price').name      = `variants[${i}][price]`;
            row.querySelector('.field-discount').name   = `variants[${i}][discount]`;
            row.querySelector('.field-stock').name      = `variants[${i}][stock]`;
            row.querySelector('.field-gender').name     = `variants[${i}][gender]`;
            row.querySelector('.field-image').name      = `variants[${i}][image]`;
            row.querySelector('.field-default').value   = i;
            if (idField) idField.name = `variants[${i}][id]`;
        });
        updateRemoveButtonsState();
    }

    function updateRemoveButtonsState() {
        const rows = container.querySelectorAll('.variant-row');
        rows.forEach(row => {
            const rbtn = row.querySelector('.remove-variant-btn');
            if (rbtn) {
                rbtn.disabled = rows.length <= 1;
                rbtn.title = rows.length <= 1 ? 'Product must have at least one color' : '';
            }
        });
    }

    function attachRemoveHandler(row) {
        const rbtn = row.querySelector('.remove-variant-btn');
        if (rbtn) {
            rbtn.addEventListener('click', () => {
                if (container.querySelectorAll('.variant-row').length <= 1) return;
                row.remove();
                renumberAndNameFields();
                checkFormChanged();
            });
        }
    }

    container.querySelectorAll('.variant-row').forEach(attachRemoveHandler);
    renumberAndNameFields();

    if (addBtn && template) {
        addBtn.addEventListener('click', () => {
            const clone = template.content.cloneNode(true);
            const row = clone.querySelector('.variant-row');
            container.appendChild(row);
            attachRemoveHandler(row);
            renumberAndNameFields();
            checkFormChanged();
        });
    }

    function buildSignature() {
        const top = ['name','description','country','manufacturer'].map(n => {
            const el = form.querySelector(`[name="${n}"]`);
            return el ? el.value : '';
        });
        const cats = Array.from(form.querySelectorAll('input[name="categories[]"]:checked')).map(el => el.value).sort();
        const ages = Array.from(form.querySelectorAll('input[name="age_groups[]"]:checked')).map(el => el.value).sort();

        const rows = Array.from(container.querySelectorAll('.variant-row')).map(row => {
            const idField = row.querySelector('.field-id');
            const fileInput = row.querySelector('.field-image');
            const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
            return [
                idField ? idField.value : 'NEW',
                row.querySelector('.field-color-name')?.value || '',
                row.querySelector('.field-color-hex')?.value || '',
                row.querySelector('.field-price')?.value || '',
                row.querySelector('.field-discount')?.value || '',
                row.querySelector('.field-stock')?.value || '',
                row.querySelector('.field-gender')?.value || '',
                row.querySelector('.field-default')?.checked ? '1' : '0',
                hasFile ? 'FILE' : 'NOFILE',
            ].join('|');
        });

        return JSON.stringify({ top, cats, ages, rows });
    }

    const originalSignature = buildSignature();

    function checkFormChanged() {
        const nameInput = form.querySelector('[name="name"]');
        const nameOk = nameInput && nameInput.value.trim() !== '';
        let variantsOk = true;
        container.querySelectorAll('.variant-row').forEach(row => {
            const cn = row.querySelector('.field-color-name')?.value.trim() || '';
            const pr = parseFloat(row.querySelector('.field-price')?.value || '0');
            if (!cn || pr <= 0) variantsOk = false;
        });

        const changed = buildSignature() !== originalSignature;
        const ok = nameOk && variantsOk && changed;
        if (typeof updateButtonState === 'function') {
            updateButtonState(btn, ok);
        } else {
            btn.disabled = !ok;
        }
    }

    form.addEventListener('input', checkFormChanged);
    form.addEventListener('change', checkFormChanged);
    checkFormChanged();

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const rows = container.querySelectorAll('.variant-row');
        let valid = rows.length > 0;
        rows.forEach(row => {
            const name  = row.querySelector('.field-color-name')?.value.trim() || '';
            const price = parseFloat(row.querySelector('.field-price')?.value || '0');
            if (!name || price <= 0) valid = false;
        });
        if (!valid) {
            if (typeof showToast === 'function') {
                showToast('Every color must have a name and a price greater than zero, and at least one color is required.', 'error');
            } else {
                alert('Every color must have a name and a price greater than zero, and at least one color is required.');
            }
            return;
        }

        btn.disabled = true;

        try {
            const fd  = new FormData(form);
            const res = await fetch(form.action, { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                if (typeof showToast === 'function') showToast(data.message || 'Product updated successfully.', 'success');
                // Reload to reset the "unsaved changes" baseline cleanly
                setTimeout(() => window.location.reload(), 900);
            } else {
                if (typeof showToast === 'function') showToast(data.message || 'Could not update product.', 'error');
                btn.disabled = false;
            }
        } catch (err) {
            if (typeof showToast === 'function') showToast('Network error. Please try again.', 'error');
            btn.disabled = false;
        }
    });
}

function initAddProductForm(form) {
    const container = document.getElementById('variantsContainer');
    const template  = document.getElementById('variantRowTemplate');
    const addBtn    = document.getElementById('addVariantBtn');
    if (!container || !template) return;

    function renumber() {
        container.querySelectorAll('.variant-row').forEach((row, i) => {
            row.querySelector('.variant-number').textContent = i + 1;
            row.querySelector('.field-color-name').name = `variants[${i}][color_name]`;
            row.querySelector('.field-color-hex').name  = `variants[${i}][color_hex]`;
            row.querySelector('.field-price').name      = `variants[${i}][price]`;
            row.querySelector('.field-discount').name   = `variants[${i}][discount]`;
            row.querySelector('.field-stock').name      = `variants[${i}][stock]`;
            row.querySelector('.field-gender').name     = `variants[${i}][gender]`;
            row.querySelector('.field-image').name      = `variants[${i}][image]`;
            row.querySelector('.field-default').value   = i;
        });
        updateRemoveButtonsState();
    }

    function updateRemoveButtonsState() {
        const rows = container.querySelectorAll('.variant-row');
        rows.forEach(row => {
            const btn = row.querySelector('.remove-variant-btn');
            if (btn) {
                btn.disabled = rows.length <= 1;
                btn.title = rows.length <= 1 ? 'Product must have at least one color' : '';
            }
        });
    }

    function addVariantRow(makeDefault) {
        const clone = template.content.cloneNode(true);
        const row   = clone.querySelector('.variant-row');
        container.appendChild(row);
        if (makeDefault) {
            const defRadio = row.querySelector('.field-default');
            if (defRadio) defRadio.checked = true;
        }
        const rbtn = row.querySelector('.remove-variant-btn');
        if (rbtn) {
            rbtn.addEventListener('click', () => {
                if (container.querySelectorAll('.variant-row').length <= 1) return;
                row.remove();
                renumber();
            });
        }
        renumber();
    }

    if (addBtn) addBtn.addEventListener('click', () => addVariantRow(false));
    addVariantRow(true);

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const rows = container.querySelectorAll('.variant-row');
        let valid = rows.length > 0;
        rows.forEach(row => {
            const name  = row.querySelector('.field-color-name')?.value.trim() || '';
            const price = parseFloat(row.querySelector('.field-price')?.value || '0');
            if (!name || price <= 0) valid = false;
        });
        if (!valid) {
            if (typeof showToast === 'function') {
                showToast('Every color must have a name and a price greater than zero, and at least one color is required.', 'error');
            } else {
                alert('Every color must have a name and a price greater than zero, and at least one color is required.');
            }
            return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        try {
            const fd  = new FormData(form);
            const res = await fetch(form.action, { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                if (typeof showToast === 'function') showToast(data.message || 'Product added successfully.', 'success');
                setTimeout(() => {
                    window.location.href = data.redirect || (window.URLROOT + '/admin/products');
                }, 1000);
            } else {
                if (typeof showToast === 'function') showToast(data.message || 'Could not add product.', 'error');
                if (submitBtn) submitBtn.disabled = false;
            }
        } catch (err) {
            if (typeof showToast === 'function') showToast('Network error. Please try again.', 'error');
            if (submitBtn) submitBtn.disabled = false;
        }
    });
}

// ── 2. إخفاء/إظهار وحذف المنتجات في قائمة المنتجات ──────────────
function initProductsListInteractions() {
    // ⚠️ الـ handlers القديمة (window.BASE_URL + "/handlers/product_handler.php") أُزيلت.
    // الـ AJAX الآن يذهب مباشرة لـ /admin/products/delete و /admin/products/toggle-visibility
    // عبر window.URLROOT (مش BASE_URL — صفحات الأدمن تستخدم URLROOT فقط)
    function getCsrf() { return window._csrfToken || document.getElementById('productsCsrf')?.value || ''; }

    // ── Toggle Visibility (مصدر واحد للحقيقة — product-hidden-row) ──
    document.querySelectorAll('.toggle-vis-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            if (btn.disabled) return;
            btn.disabled = true;

            const fd = new FormData();
            fd.append('product_id', btn.dataset.id);
            fd.append('csrf_token', getCsrf());

            try {
                const res  = await fetch(window.URLROOT + '/admin/products/toggle-visibility', {
                    method: 'POST', body: fd,
                });
                const data = await res.json();

                if (!data.success) {
                    if (typeof showToast === 'function') showToast(data.message || 'Error', 'error');
                    return;
                }

                const visible = data.is_visible === 1;
                const row     = document.getElementById('product-row-' + btn.dataset.id);

                if (row) {
                    row.classList.toggle('product-hidden-row', !visible);
                    const hiddenBadge = row.querySelector('.hidden-badge');
                    if (hiddenBadge) hiddenBadge.style.display = visible ? 'none' : 'inline-block';
                }

                btn.textContent = visible ? '👁️' : '🚫';
                btn.title       = visible ? 'Hide from store' : 'Show in store';
                btn.className   = 'btn btn-sm toggle-vis-btn '
                                + (visible ? 'btn-outline-secondary' : 'btn-outline-warning');

                if (typeof showToast === 'function') {
                    showToast(visible ? 'Product is now visible.' : 'Product hidden from store.', 'success');
                }
            } catch (err) {
                if (typeof showToast === 'function') showToast('Network error. Please try again.', 'error');
            } finally {
                btn.disabled = false;
            }
        });
    });

    // ── Delete Product (SweetAlert2 + AJAX) ──────────────────────
    document.querySelectorAll('.del-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const pid  = btn.dataset.id;
            const name = btn.dataset.name;

            Swal.fire({
                title: 'Delete Product?',
                text:  '"' + name + '" will be permanently deleted.',
                icon:  'warning',
                showCancelButton:   true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor:  '#6c757d',
                confirmButtonText:  'Yes, Delete',
                cancelButtonText:   'Cancel',
            }).then(async function (result) {
                if (!result.isConfirmed) return;

                const fd = new FormData();
                fd.append('product_id', pid);
                fd.append('csrf_token', getCsrf());

                try {
                    const res  = await fetch(window.URLROOT + '/admin/products/delete', {
                        method: 'POST', body: fd,
                    });
                    const data = await res.json();

                    if (data.success) {
                        const row = document.getElementById('product-row-' + pid);
                        if (row) {
                            row.style.transition = 'opacity .35s';
                            row.style.opacity    = '0';
                            setTimeout(() => row.remove(), 380);
                        }
                        if (typeof showToast === 'function') showToast('Product deleted.', 'success');
                    } else {
                        if (typeof showToast === 'function') showToast(data.message || 'Error deleting product.', 'error');
                    }
                } catch (err) {
                    if (typeof showToast === 'function') showToast('Network error.', 'error');
                }
            });
        });
    });
}
