// ══════════════════════════════════════════════════════════════
// public/js/admin/category-picker.js
// The logic for the category choose / add / delete modal.
// Used by the Add and Edit Product pages alone (a single mode: select).
//
// The variables injected from PHP:
//   window._categoriesData       — [{id,name,is_core,product_count}]
//   window._currentCategoryIds   — (edit only) [1,2,…]
//
// ⚠️ It uses window.URLROOT — not BASE_URL.
// It uses fetchWithCsrfRetry for the writing endpoints. The category suggestion endpoint
// alone stays a bare fetch — it does not verify CSRF at all (see its own comment).
// ══════════════════════════════════════════════════════════════

let selectedCategoryIds    = new Set();
let categoryDeleteTargetId = null;
let allCategoriesData      = [];

document.addEventListener('DOMContentLoaded', () => {

    // ── Initialising the data ───────────────────────────────────
    allCategoriesData = (window._categoriesData || []).map(c => ({ ...c }));

    // The edit page: start from the product's current categories
    if (Array.isArray(window._currentCategoryIds)) {
        selectedCategoryIds = new Set(window._currentCategoryIds.map(Number));
        renderSelectedChips();
    }

    const modalEl = document.getElementById('categoryPickerModal');
    const openBtn = document.getElementById('openCategoryPickerBtn');
    if (!modalEl) return;

    // ── Opening the modal ───────────────────────────────────────
    openBtn?.addEventListener('click', () => {
        renderCategoryPickerList();
        new bootstrap.Modal(modalEl).show();
    });

    // ── Confirm Selection ────────────────────────────────────────
    document.getElementById('confirmCategorySelectionBtn')?.addEventListener('click', () => {
        if (selectedCategoryIds.size === 0) {
            const errEl = document.getElementById('categoryRequiredError');
            // `d-none` in the markup means display:none !important, so both showing and
            // hiding go through classList rather than style.display.
            if (errEl) errEl.classList.remove('d-none');
            return;
        }
        renderSelectedChips();
        const errEl = document.getElementById('categoryRequiredError');
        if (errEl) errEl.classList.add('d-none');
        bootstrap.Modal.getInstance(modalEl)?.hide();
    });

    // ── Adding a new category ───────────────────────────────────
    document.getElementById('addCategoryBtn')?.addEventListener('click', addNewCategory);
    document.getElementById('newCategoryInput')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); addNewCategory(); }
    });

    // ── Similarity suggestions (debounced by 300ms) ─────────────
    let debounceTimer;
    document.getElementById('newCategoryInput')?.addEventListener('input', e => {
        clearTimeout(debounceTimer);
        const q = e.target.value.trim();
        const suggest = document.getElementById('categorySuggestions');
        if (!suggest) return;
        if (q.length < 2) { suggest.innerHTML = ''; return; }
        debounceTimer = setTimeout(() => fetchSuggestions(q), 300);
    });

    // ── Confirming a deletion ───────────────────────────────────
    document.getElementById('confirmCategoryDeleteBtn')?.addEventListener('click', confirmCategoryDelete);
});

// ════════════════════════════════════════════════════════════
// Building the category list in the .perm-item style (the same as manage-admins)
// ════════════════════════════════════════════════════════════
function renderCategoryPickerList() {
    const list = document.getElementById('categoryPickerList');
    if (!list) return;

    if (!allCategoriesData.length) {
        list.innerHTML = '<p class="text-center py-2 u-placeholder">No categories found.</p>';
        return;
    }

    list.innerHTML = allCategoriesData.map(c => `
        <label class="perm-item" data-cat-id="${c.id}">
            <input type="checkbox"
                   class="cat-select-checkbox"
                   value="${c.id}"
                   ${selectedCategoryIds.has(c.id) ? 'checked' : ''}>
            <span>
                ${escHtml(c.name)}
                ${c.is_core
                    ? '<span class="badge bg-secondary ms-1 u-fs-60">core</span>'
                    : ''}
                <span class="badge bg-light text-dark ms-1 u-fs-60">${c.product_count}</span>
                ${!c.is_core
                    ? `<span class="cat-delete-icon ms-1 u-clickable u-fs-85"
                              data-id="${c.id}"
                              data-name="${escAttr(c.name)}"
                              title="Delete category">🗑️</span>`
                    : ''}
            </span>
        </label>
    `).join('');

    // checkboxes
    list.querySelectorAll('.cat-select-checkbox').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = Number(cb.value);
            cb.checked ? selectedCategoryIds.add(id) : selectedCategoryIds.delete(id);
        });
    });

    // The delete icons — e.preventDefault() stops the checkbox toggling
    list.querySelectorAll('.cat-delete-icon').forEach(icon => {
        icon.addEventListener('click', e => {
            e.preventDefault();
            openDeleteConfirm(Number(icon.dataset.id), icon.dataset.name);
        });
    });
}

// ════════════════════════════════════════════════════════════
// Chips + hidden inputs
// ════════════════════════════════════════════════════════════
function renderSelectedChips() {
    const chipsWrap  = document.getElementById('selectedCategoriesChips');
    const hiddenWrap = document.getElementById('categoryHiddenInputs');
    if (!chipsWrap || !hiddenWrap) return;

    const selected = allCategoriesData.filter(c => selectedCategoryIds.has(c.id));

    chipsWrap.innerHTML = selected.length
        ? selected.map(c =>
            `<span class="badge bg-primary u-chip">${escHtml(c.name)}</span>`
          ).join('')
        : '<span class="u-placeholder u-fs-85">None selected</span>';

    hiddenWrap.innerHTML = selected
        .map(c => `<input type="hidden" name="category_ids[]" value="${c.id}">`)
        .join('');

    const errEl = document.getElementById('categoryRequiredError');
    if (errEl) errEl.classList.toggle('d-none', selected.length > 0);
}

// ════════════════════════════════════════════════════════════
// Adding a new category — over AJAX
// ════════════════════════════════════════════════════════════
async function addNewCategory() {
    const input = document.getElementById('newCategoryInput');
    const name  = (input?.value || '').trim();
    if (!name) return;

    const exists = allCategoriesData.some(c => c.name.toLowerCase() === name.toLowerCase());
    if (exists) {
        if (typeof showToast === 'function') showToast('This category already exists.', 'error');
        return;
    }

    const fd = new FormData();
    fd.append('name',       name);
    fd.append('csrf_token', window._csrfToken || '');

    try {
        const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/products/categories/add', { method: 'POST', body: fd });

        if (data.success) {
            allCategoriesData.push({ id: data.category.id, name: data.category.name, is_core: false, product_count: 0 });
            if (input) input.value = '';
            const suggest = document.getElementById('categorySuggestions');
            if (suggest) suggest.innerHTML = '';
            renderCategoryPickerList();
            if (typeof showToast === 'function') showToast('Category added.', 'success');
        } else {
            if (typeof showToast === 'function') showToast(data.message || 'Error', 'error');
        }
    } catch {
        if (typeof showToast === 'function') showToast('Network error.', 'error');
    }
}

// ════════════════════════════════════════════════════════════
// Similarity suggestions (debounced by 300ms)
// ════════════════════════════════════════════════════════════
async function fetchSuggestions(q) {
    const suggestEl = document.getElementById('categorySuggestions');
    if (!suggestEl) return;

    const fd = new FormData();
    fd.append('q', q);
    fd.append('csrf_token', window._csrfToken || '');

    try {
        const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/products/categories/suggest', { method: 'POST', body: fd });

        if (data.success && data.suggestions?.length) {
            const top = data.suggestions[0];
            suggestEl.innerHTML = top.similarity > 60
                ? `Did you mean: <strong>${escHtml(top.name)}</strong>
                   <span class="u-placeholder">(${top.similarity}% similar)</span>?`
                : '';
        } else {
            suggestEl.innerHTML = '';
        }
    } catch {
        suggestEl.innerHTML = '';
    }
}

// ════════════════════════════════════════════════════════════
// Opening the category deletion confirmation
// ════════════════════════════════════════════════════════════
function openDeleteConfirm(id, name) {
    categoryDeleteTargetId = id;

    const nameEl = document.getElementById('delCatName');
    if (nameEl) nameEl.textContent = name;

    const select = document.getElementById('delCatDestination');
    if (select) {
        select.innerHTML = allCategoriesData
            .filter(c => c.id !== id)
            .map(c => `<option value="${c.id}">${escHtml(c.name)}</option>`)
            .join('');
    }

    new bootstrap.Modal(document.getElementById('categoryDeleteModal')).show();

    // Reorder the select so the closest match by meaning comes first
    //
    // This call now goes through the safety net: suggestCategory was the one endpoint
    // accepting a POST with no CSRF check, and it now passes through beginJsonPost. And
    // because csrf.js no longer detects the failure by the message text but by error_code,
    // any endpoint outside the net loses its recovery from an expired token.
    const fd = new FormData();
    fd.append('q', name);
    fd.append('csrf_token', window._csrfToken || '');
    fetchWithCsrfRetry(window.URLROOT + '/admin/products/categories/suggest', { method: 'POST', body: fd })
        .then(data => {
            if (!data.success || !select) return;
            const order = data.suggestions.map(s => String(s.id));
            const opts  = Array.from(select.options).sort((a, b) => {
                const ai = order.indexOf(a.value), bi = order.indexOf(b.value);
                if (ai === -1 && bi === -1) return 0;
                if (ai === -1) return 1;
                if (bi === -1) return -1;
                return ai - bi;
            });
            select.innerHTML = '';
            opts.forEach(o => select.appendChild(o));
        })
        .catch(() => {});
}

// ════════════════════════════════════════════════════════════
// Confirming a deletion — over AJAX
// ════════════════════════════════════════════════════════════
async function confirmCategoryDelete() {
    const select = document.getElementById('delCatDestination');
    const destId = select?.value;
    if (!destId) {
        if (typeof showToast === 'function') showToast('Please choose a destination category.', 'error');
        return;
    }

    const fd = new FormData();
    fd.append('category_id',    categoryDeleteTargetId);
    fd.append('destination_id', destId);
    fd.append('csrf_token',     window._csrfToken || '');

    try {
        const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/products/categories/delete', { method: 'POST', body: fd });

        if (data.success) {
            allCategoriesData = allCategoriesData.filter(c => c.id !== categoryDeleteTargetId);
            selectedCategoryIds.delete(categoryDeleteTargetId);
            bootstrap.Modal.getInstance(document.getElementById('categoryDeleteModal'))?.hide();
            renderCategoryPickerList();
            renderSelectedChips();
            if (typeof showToast === 'function') showToast('Category deleted, products moved.', 'success');
        } else {
            if (typeof showToast === 'function') showToast(data.message || 'Error', 'error');
        }
    } catch {
        if (typeof showToast === 'function') showToast('Network error.', 'error');
    }
}

// ════════════════════════════════════════════════════════════
// Helpers
// ════════════════════════════════════════════════════════════
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function escAttr(str) {
    return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
