// ══════════════════════════════════════════════════════════════
// js/core/ui.js — the general user interface components
// ══════════════════════════════════════════════════════════════

/**
 * showToast — SweetAlert2 toast notifications
 */
function showToast(message, icon = 'success') {
    const isDark = document.body.classList.contains("dark-mode");
    Swal.fire({
        text: message,
        icon: icon,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        background: isDark ? '#1e2530' : '#ffffff',
        color:      isDark ? '#e6edf3' : '#1a1a2e',
        iconColor: icon === 'success' ? '#198754' : icon === 'error' ? '#dc3545' : '#0dcaf0',
        showClass: { popup: 'swal2-toast-show' },
        hideClass: { popup: 'swal2-toast-hide' }
    });
}
window.showToast = showToast;

/**
 * showLoading — the global loading spinner
 */
function showLoading() {
    let overlay = document.getElementById('loadingOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'loadingOverlay';
        overlay.style.position = 'fixed';
        overlay.style.top = '0';
        overlay.style.left = '0';
        overlay.style.width = '100vw';
        overlay.style.height = '100vh';
        overlay.style.background = 'rgba(0, 0, 0, 0.4)';
        overlay.style.backdropFilter = 'blur(3px)';
        overlay.style.zIndex = '9999';
        overlay.style.display = 'flex';
        overlay.style.justifyContent = 'center';
        overlay.style.alignItems = 'center';
        overlay.innerHTML = '<div class="spinner-border text-light u-spinner-lg" role="status"><span class="visually-hidden">Loading...</span></div>';
        document.body.appendChild(overlay);
    }
    overlay.style.display = 'flex';
}
window.showLoading = showLoading;

/**
 * updateButtonState — adding and removing a button's dimming and disabled state
 */
function updateButtonState(buttonEl, isValid) {
    if (!buttonEl) return;
    if (isValid) {
        buttonEl.classList.remove('btn-disabled-faded');
        buttonEl.removeAttribute('disabled');
    } else {
        buttonEl.classList.add('btn-disabled-faded');
        buttonEl.setAttribute('disabled', 'true');
    }
}
window.updateButtonState = updateButtonState;

/**
 * updateCounters — refreshing the counters on the navbar icons
 */
function updateCounters() {
    const wishlistCount = document.getElementById("wishlist-count");
    const cartCount     = document.getElementById("cart-count");

    const wishlist = JSON.parse(localStorage.getItem("wishlist")) || [];

    // ⚠️ The cart comes from the mirror, not from localStorage.
    //
    // It used to be read from `localStorage.getItem("cart")` — correct back when the cart
    // was local, and a **silent fault** the moment it moved to the server: the key stopped
    // being written at all, so the badge kept showing whatever had frozen in it and did not
    // move however much was added or removed.
    //
    // The effect, as the user described it: five products in the cart and the badge saying 1.
    //
    // The wishlist stays in localStorage — it did not move, and the read above is correct
    // for it.
    const cart = (typeof window.getCartData === 'function') ? window.getCartData() : [];

    if (wishlistCount) {
        wishlistCount.textContent = wishlist.length;
    }

    if (cartCount) {
        // The sum of quantities, not the count of lines: the badge says "how many items",
        // not "how many products". That matches CartModel::countItems on the server.
        const total = cart.reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);
        cartCount.textContent = total;
    }

    highlightNavIcons();
}
window.updateCounters = updateCounters;

/**
 * highlightNavIcons — marking the active page's icon in the navbar
 */
function highlightNavIcons() {
    const path = window.location.pathname;
    const wishlistBtn = document.querySelector('a[href*="wishlist.php"]');
    const cartBtn     = document.querySelector('[data-bs-target="#cartSidebar"]');

    // As above: the cart from the mirror, the wishlist from localStorage.
    const cart     = (typeof window.getCartData === 'function') ? window.getCartData() : [];
    const wishlist = JSON.parse(localStorage.getItem("wishlist")) || [];

    if (wishlistBtn) {
        wishlistBtn.classList.toggle("navbar-icon-active", path.includes("wishlist") || wishlist.length > 0);
    }
    if (cartBtn) {
        cartBtn.classList.toggle("navbar-icon-active", cart.length > 0);
    }
}
window.highlightNavIcons = highlightNavIcons;

/**
 * initBackToTop — the back-to-top button
 */
function initBackToTop() {
    if (document.getElementById("back-to-top")) return;
    const btn = document.createElement("button");
    btn.id = "back-to-top";
    btn.title = "Back to top";
    btn.innerHTML = "↑";
    document.body.appendChild(btn);

    let scrollTicking = false;
    window.addEventListener("scroll", () => {
        if (!scrollTicking) {
            requestAnimationFrame(() => {
                btn.classList.toggle("visible", window.scrollY > 350);
                scrollTicking = false;
            });
            scrollTicking = true;
        }
    });

    btn.addEventListener("click", () => {
        window.scrollTo({ top: 0, behavior: "smooth" });
    });
}
window.initBackToTop = initBackToTop;

/**
 * initScrollReveal — the gradual reveal effect on elements
 */
function initScrollReveal() {
    let delay = 0;
    let lastTime = 0;
    const observer = new IntersectionObserver((entries) => {
        const sortedEntries = [...entries].sort((a, b) => {
            const rectA = a.target.getBoundingClientRect();
            const rectB = b.target.getBoundingClientRect();
            return (rectA.top - rectB.top) || (rectA.left - rectB.left);
        });

        sortedEntries.forEach(entry => {
            if (entry.isIntersecting) {
                const el = entry.target;
                const now = performance.now();
                if (now - lastTime > 150) {
                    delay = 0;
                }
                lastTime = now;

                el.style.transitionDelay = `${delay}ms`;
                el.classList.add("visible");
                delay += 60;
                observer.unobserve(el);
            }
        });
    }, { threshold: 0.08 });

    document.querySelectorAll(".reveal:not(.visible)").forEach(el => {
        observer.observe(el);
    });
}
window.initScrollReveal = initScrollReveal;

/**
 * initPageTransitions — the page transition animation
 */
function initPageTransitions() {
    if (document.getElementById("page-overlay")) return;
    const overlay = document.createElement("div");
    overlay.id = "page-overlay";
    document.body.appendChild(overlay);

    const bar = document.createElement("div");
    bar.id = "page-progress-bar";
    document.body.appendChild(bar);

    requestAnimationFrame(() => {
        overlay.classList.remove("active");
        bar.classList.remove("loading");
    });

    document.addEventListener("click", (e) => {
        const link = e.target.closest("a[href]");
        if (!link) return;

        const href = link.getAttribute("href");

        if (!href) return;
        if (href.startsWith("#")) return;
        if (href.startsWith("http")) return;
        if (href.startsWith("mailto")) return;
        if (href.startsWith("tel")) return;
        if (href.startsWith("javascript")) return;
        if (link.hasAttribute("data-bs-toggle")) return;
        if (link.hasAttribute("data-bs-target")) return;
        if (link.hasAttribute("data-bs-dismiss")) return;
        if (link.target === "_blank") return;
        if (document.querySelector(".modal.show")) return;
        if (document.querySelector(".offcanvas.show")) return;

        e.preventDefault();

        bar.classList.add("loading");
        setTimeout(() => overlay.classList.add("active"), 80);

        setTimeout(() => {
            window.location.href = href;
        }, 280);
    });

    window.addEventListener("pageshow", () => {
        overlay.classList.remove("active");
        bar.classList.remove("loading");
    });

    document.addEventListener("show.bs.modal",    () => overlay.classList.remove("active"));
    document.addEventListener("show.bs.offcanvas", () => overlay.classList.remove("active"));
}
window.initPageTransitions = initPageTransitions;

/**
 * initImageFallbacks — a placeholder image when an image is broken
 */
function initImageFallbacks() {
    const fallbackSrc = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Crect width='200' height='200' fill='%23e5e7eb'/%3E%3Ctext x='50%25' y='50%25' font-size='14' fill='%239ca3af' text-anchor='middle' dy='.3em'%3EImage not found%3C/text%3E%3C/svg%3E";

    document.querySelectorAll("img").forEach(img => addFallback(img));

    const observer = new MutationObserver((mutations) => {
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1) {
                    if (node.tagName === "IMG") addFallback(node);
                    node.querySelectorAll?.("img").forEach(img => addFallback(img));
                }
            });
        });
    });

    observer.observe(document.body, { childList: true, subtree: true });

    function addFallback(img) {
        if (img.dataset.fallbackSet) return;
        img.dataset.fallbackSet = "1";
        img.addEventListener("error", () => {
            img.src = fallbackSrc;
            img.classList.add("img-error");
            img.alt = img.alt || "Image not available";
        });
    }
}
window.initImageFallbacks = initImageFallbacks;

/**
 * filterStatus — status filtering on the admin pages
 */
function filterStatus(value) {
    const p = new URLSearchParams(window.location.search);
    if (value) p.set('status', value);
    else       p.delete('status');
    p.delete('page');
    window.location.href = '?' + p.toString();
}
window.filterStatus = filterStatus;

/**
 * startRetryCountdown — a countdown on a locked-out button
 */
function startRetryCountdown(messageEl, buttonEl, seconds, baseMessage, onFinish) {
    let remaining = seconds;
    if (buttonEl) buttonEl.disabled = true;

    const timer = setInterval(() => {
        if (messageEl) {
            messageEl.textContent = `${baseMessage}${remaining} second${remaining === 1 ? '' : 's'}.`;
        }
        if (remaining <= 0) {
            clearInterval(timer);
            if (buttonEl) buttonEl.disabled = false;
            if (typeof onFinish === 'function') onFinish();
            return;
        }
        remaining--;
    }, 1000);

    return timer;
}
window.startRetryCountdown = startRetryCountdown;

// Fixing the page's edges and scroll space when a modal closes
const fixBodyPadding = () => {
    document.body.style.paddingRight = '0';
    document.body.style.overflow = '';
};

document.addEventListener('hidden.bs.modal', () => {
    setTimeout(() => {
        if (!document.querySelector('.modal.show') && !document.querySelector('.modal.fade.show')) {
            fixBodyPadding();
            document.body.classList.remove('modal-open');
        }
    }, 400);
});

document.addEventListener('hidden.bs.offcanvas', () => {
    fixBodyPadding();
});

// Showing the loader automatically when any non-exempt form is submitted
document.addEventListener('submit', (e) => {
    if (!e.defaultPrevented && !e.target.classList.contains('search-form') && !e.target.classList.contains('no-spinner')) {
        showLoading();
    }
});

// ══════════════════════════════════════════════════════════════
// A fallback for SweetAlert2 when it does not load
// ══════════════════════════════════════════════════════════════
//
// More than forty places in the project call `Swal.fire` directly, and many of them
// **await its answer** before doing anything:
//
//     const result = await Swal.fire({ ... });
//     if (!result.isConfirmed) return;
//
// So if `Swal` is missing — a wrong SRI hash, a blocked CDN, or a network outage — a
// ReferenceError is thrown inside an async function, becoming a rejected promise nobody
// catches. The result is a button that looks **broken for no reason**: no dialog, no
// message, no visible error, and the user's click goes nowhere.
//
// And this is not hypothetical: the "Take It" button in the orders panel looked broken
// for exactly this reason, and the diagnosis went off toward a race condition in the
// database.
//
// The fallback here uses the native `confirm` and `alert` — ugly, but they always work —
// and returns an object shaped like a SweetAlert result
// ({ isConfirmed, isDismissed, value }) so the awaiting code passes through unchanged. The
// goal is that nothing ever goes silent, not that it looks good.
(function installSwalFallback() {
    function ensure() {
        if (typeof window.Swal !== 'undefined') return;

        console.error(
            '[ui] SweetAlert2 did not load — check the SRI hash in assets_helper.php '
            + 'and the CSP in public/.htaccess. A fallback is running instead.'
        );

        const text = (o) => [o && o.title, o && o.text].filter(Boolean).join('\n\n')
            || 'Are you sure?';

        window.Swal = {
            fire(options) {
                const opts = typeof options === 'string'
                    ? { title: options, text: arguments[1] }
                    : (options || {});

                // A toast with no confirm button is a notification, not a question — it does
                // not stop the user with an alert, and it does not log a line per appearance:
                // the setup message above was said once and that is enough.
                if (opts.toast || opts.showConfirmButton === false) {
                    return Promise.resolve({ isConfirmed: true, isDismissed: false, value: true });
                }

                const confirmed = opts.showCancelButton
                    ? window.confirm(text(opts))
                    : (window.alert(text(opts)), true);

                return Promise.resolve({
                    isConfirmed: confirmed,
                    isDismissed: !confirmed,
                    value: confirmed,
                });
            },
            showValidationMessage(msg) { window.alert(msg); },
            close() {},
        };
    }

    // After the full load rather than at DOMContentLoaded: the SweetAlert tag carries
    // `defer` in the store footer, so it may not have executed yet.
    if (document.readyState === 'complete') {
        ensure();
    } else {
        window.addEventListener('load', ensure);
    }
})();
