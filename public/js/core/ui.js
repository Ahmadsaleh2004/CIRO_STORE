// ══════════════════════════════════════════════════════════════
// js/core/ui.js — مكوّنات وعناصر واجهة المستخدم العامة
// ══════════════════════════════════════════════════════════════

/**
 * showToast — تنبيهات SweetAlert2 Toast
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
 * showLoading — Spinner التجميعي الشامل
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
        overlay.innerHTML = '<div class="spinner-border text-light" style="width: 3rem; height: 3rem;" role="status"><span class="visually-hidden">Loading...</span></div>';
        document.body.appendChild(overlay);
    }
    overlay.style.display = 'flex';
}
window.showLoading = showLoading;

/**
 * updateButtonState — إضافة/إزالة تمويه وتعطيل الأزرار
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
 * updateCounters — تحديث العدادات على أيقونات الـ Navbar
 */
function updateCounters() {
    const wishlistCount = document.getElementById("wishlist-count");
    const cartCount     = document.getElementById("cart-count");

    const wishlist = JSON.parse(localStorage.getItem("wishlist")) || [];
    const cart     = JSON.parse(localStorage.getItem("cart"))     || [];

    if (wishlistCount) {
        wishlistCount.textContent = wishlist.length;
    }

    if (cartCount) {
        const total = cart.reduce((sum, item) => sum + (item.quantity || 1), 0);
        cartCount.textContent = total;
    }

    highlightNavIcons();
}
window.updateCounters = updateCounters;

/**
 * highlightNavIcons — نُميّز أيقونة الصفحة النشطة في الـ Navbar
 */
function highlightNavIcons() {
    const path = window.location.pathname;
    const wishlistBtn = document.querySelector('a[href*="wishlist.php"]');
    const cartBtn     = document.querySelector('[data-bs-target="#cartSidebar"]');

    const cart     = JSON.parse(localStorage.getItem("cart"))     || [];
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
 * initBackToTop — زر العودة إلى الأعلى
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
 * initScrollReveal — تأثير الظهور التدريجي للعناصر
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
 * initPageTransitions — حركة الانتقال بين الصفحات
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
 * initImageFallbacks — صورة بديلة إذا كانت الصورة مكسورة
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
 * filterStatus — فلترة الحالة في صفحات الأدمن
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
 * startRetryCountdown — عداد تنازلي للزرار المسدود
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

// إصلاح حواف ومساحة الشاشة عند إغلاق النوافذ المنبثقة
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

// تشغيل الـ loading تلقائياً عند تقديم أي فورمة غير مستثناة
document.addEventListener('submit', (e) => {
    if (!e.defaultPrevented && !e.target.classList.contains('search-form') && !e.target.classList.contains('no-spinner')) {
        showLoading();
    }
});
