// ══════════════════════════════════════════════════════════════
// js/features/cart.js — محرك سلة التسوق وتزامن المخزون
// ══════════════════════════════════════════════════════════════

function getCartData() {
    return JSON.parse(localStorage.getItem("cart")) || [];
}
window.getCartData = getCartData;

function saveCart(updatedCart) {
    localStorage.setItem("cart", JSON.stringify(updatedCart));
    refreshCartUI();
}
window.saveCart = saveCart;

function refreshCartUI() {
    if (typeof updateCounters === 'function') updateCounters();
    renderCart();
}
window.refreshCartUI = refreshCartUI;

function renderCart() {
    const cartContainer = document.getElementById("cart-items-list");
    const cartTotal     = document.getElementById("cart-total");
    if (!cartContainer) return;

    let cart = getCartData();
    if (cart.length === 0) {
        cartContainer.innerHTML = `<li class="text-center py-5 u-placeholder">Your cart is empty.</li>`;
        if (cartTotal) cartTotal.innerText = "$0.00";
    } else {
        let total = 0;
        cartContainer.innerHTML = cart.map(item => {
            total += item.price * item.quantity;
            const vId = item.variant_id ?? '';
            const colorLabel = item.color_name ? ` <span class="small u-o-75">(${escHtml(item.color_name)})</span>` : '';
            return `
                <li class="mb-3 p-2 border-bottom border-secondary">
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="${window.BASE_URL}/product?id=${item.id}" class="text-white text-decoration-none fw-bold">${escHtml(item.name)}${colorLabel}</a>
                        <button class="btn btn-sm btn-outline-danger remove-item" data-id="${item.id}" data-variant-id="${vId}" aria-label="Remove ${escHtml(item.name)} from cart">✖</button>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small>$${item.price} each</small>
                        <div class="d-flex align-items-center gap-2">
                            <button class="btn btn-sm btn-outline-light minus" data-id="${item.id}" data-variant-id="${vId}" aria-label="Decrease quantity">-</button>
                            <span class="px-2 fw-bold" aria-label="Quantity: ${item.quantity}">${item.quantity}</span>
                            <button class="btn btn-sm btn-outline-light plus" data-id="${item.id}" data-variant-id="${vId}" aria-label="Increase quantity">+</button>
                        </div>
                    </div>
                </li>`;
        }).join('');
        if (cartTotal) cartTotal.innerText = `$${total.toFixed(2)}`;
    }
}
window.renderCart = renderCart;

function syncCartWithStock() {
    const cart = getCartData();
    if (cart.length === 0) return;

    const variantIds = cart.map(item => item.variant_id).filter(v => v !== undefined && v !== null);
    if (variantIds.length === 0) return;

    // fetch عارٍ عن قصد — لا fetchWithCsrfRetry: CartController::checkStock
    // **لا تتحقق من توكن CSRF** (استعلام مخزون للقراءة)، والطلب لا يرسل
    // توكناً أصلاً. فلا يمكن أن تُرجع «Invalid CSRF token».
    // nosemgrep: cairo-bare-fetch-post
    fetch(window.BASE_URL + '/cart/check-stock', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: variantIds.map(id => 'variant_ids[]=' + encodeURIComponent(id)).join('&')
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) return;

        // تحويل data.items (array) إلى object مفهرس بـ variant_id
        // بنفس البنية التي يتوقعها باقي الكود: variants[variantId] = {stock, visible, ...}
        const variants = {};
        (data.items || []).forEach(item => {
            variants[String(item.variant_id)] = {
                stock:   item.stock_quantity,
                visible: true,                       // is_visible=1 مشروط بالـ SQL
                price:   item.price_after_discount || item.price,
            };
        });

        let removedNames = [];
        let adjustedNames = [];
        let stockRefreshed = false;

        const updatedCart = cart.reduce((acc, item) => {
            if (item.variant_id === undefined || item.variant_id === null) {
                acc.push(item);
                return acc;
            }

            const info = variants[String(item.variant_id)];

            if (!info || !info.visible || info.stock <= 0) {
                removedNames.push(item.name + (item.color_name ? ` (${item.color_name})` : ''));
                return acc;
            }

            if (info.stock < item.quantity) {
                item.quantity = info.stock;
                adjustedNames.push(`${item.name} (${info.stock})`);
            }

            if (item.stock !== info.stock) {
                item.stock = info.stock;
                stockRefreshed = true;
            }

            acc.push(item);
            return acc;
        }, []);

        if (removedNames.length > 0 || adjustedNames.length > 0 || stockRefreshed) {
            localStorage.setItem('cart', JSON.stringify(updatedCart));
            refreshCartUI();

            if (removedNames.length > 0) {
                if (typeof showToast === 'function') showToast(`Removed (out of stock): ${removedNames.join(', ')}`, 'error');
            }
            if (adjustedNames.length > 0) {
                if (typeof showToast === 'function') showToast(`Quantity adjusted to available stock: ${adjustedNames.join(', ')}`, 'info');
            }
        }
    })
    .catch(err => console.error('syncCartWithStock error:', err));
}
window.syncCartWithStock = syncCartWithStock;

// تهيئة تفاعلات السلة بالـ DOM
document.addEventListener("DOMContentLoaded", () => {
    document.addEventListener("click", (e) => {
        if (e.target.closest("#cart-items-list")) {
            const btn = e.target;
            const id  = parseInt(btn.dataset.id);
            const variantIdRaw = btn.dataset.variantId;
            const variantId    = variantIdRaw ? parseInt(variantIdRaw) : null;
            if (!id) return;

            let cart = getCartData();
            let item = cart.find(p => p.id === id && (p.variant_id ?? null) == (variantId ?? null));
            if (!item) return;

            if (btn.classList.contains("plus")) {
                const maxStock = typeof item.stock === 'number' ? item.stock : Infinity;
                if (item.quantity < maxStock) {
                    item.quantity++;
                } else {
                    if (typeof showToast === 'function') showToast('No more stock available for this item.', 'error');
                }
            } else if (btn.classList.contains("minus")) {
                if (item.quantity > 1) item.quantity--;
                else cart = cart.filter(p => !(p.id === id && (p.variant_id ?? null) == (variantId ?? null)));
            } else if (btn.classList.contains("remove-item")) {
                cart = cart.filter(p => !(p.id === id && (p.variant_id ?? null) == (variantId ?? null)));
                if (typeof showToast === 'function') showToast('Product removed from cart', 'info');
            }
            saveCart(cart);
        }
    });

    refreshCartUI();

    const offcanvasEl = document.getElementById('cartSidebar');
    if (offcanvasEl) {
        offcanvasEl.addEventListener('show.bs.offcanvas', () => {
            renderCart();
            syncCartWithStock();
        });
    }

    if (getCartData().length > 0) {
        syncCartWithStock();
    }
});
