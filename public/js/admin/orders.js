// ══════════════════════════════════════════════════════════════
// js/admin/orders.js — السكريبتات المخصصة لإدارة الطلبات والعداد والبلاغات
// (المسارات تشير لراوتات الأدمن الجديدة: /admin/orders/* — يشمل Take/Release/Delete/Cancel/Report)
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {

    function goToOrderDetails(id) {
        window.location.href = window.URLROOT + "/admin/orders/details?id=" + id;
    }
    window.goToOrderDetails = goToOrderDetails;

    // ── فلترة القائمة حسب الحالة (orders/index.php) ─────────────
    window.filterStatus = function(value) {
        const url = new URL(window.location.href);
        if (value) { url.searchParams.set('status', value); }
        else       { url.searchParams.delete('status'); }
        url.searchParams.delete('page'); // إعادة الترقيم للصفحة الأولى عند تغيير الفلتر
        window.location.href = url.toString();
    };

    // ── Delete Order (orders/index.php only — completed/cancelled rows) ──
    document.querySelectorAll('.delete-order-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const oid = btn.dataset.oid;

            Swal.fire({
                title: 'Delete Order #' + oid + '?',
                text: 'This order will be permanently deleted. This cannot be undone.',
                icon: 'warning',
                input: 'textarea',
                inputPlaceholder: 'Reason for deleting this order (required)...',
                inputValidator: (value) => (!value || !value.trim()) ? 'A reason is required.' : undefined,
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel',
            }).then(async function (result) {
                if (!result.isConfirmed) return;
                const fd = new FormData();
                fd.append('order_id', oid);
                fd.append('reason', (result.value || '').trim());
                fd.append('csrf_token', window._csrfToken || '');

                const data = await fetchWithCsrfRetry(window.URLROOT + '/admin/orders/delete', { method: 'POST', body: fd });
                if (data.csrf_token && typeof updateCsrfToken === 'function') updateCsrfToken(data.csrf_token);
                if (data.success) {
                    if (typeof showToast === 'function') showToast(data.message, 'success');
                    const row = btn.closest('tr');
                    if (row) { row.style.transition = 'opacity .3s'; row.style.opacity = '0'; setTimeout(() => row.remove(), 300); }
                } else {
                    if (typeof showToast === 'function') showToast(data.message || 'Error', 'error');
                }
            });
        });
    });

    // ── Countdown Timer & Details Logic (في صفحة orders/details.php) ──
    if (typeof window.ADMIN_ORDER_DETAILS !== 'undefined') {
        initOrderDetails(window.ADMIN_ORDER_DETAILS);
    }

});

function initOrderDetails(ctx) {
    const ORDER_ID      = ctx.orderId;
    const PRODUCT_NAMES = ctx.productNames;
    const ORDER_DATE    = ctx.orderDate;
    let   remSeconds    = ctx.remSeconds;
    let   timerInterval = null;

    if (remSeconds > 0) {
        const el = document.getElementById("countdown");
        timerInterval = setInterval(function() {
            if (remSeconds <= 0) {
                clearInterval(timerInterval);
                if (el) el.textContent = "00:00:00";
                location.reload();
                return;
            }
            remSeconds--;
            const h = Math.floor(remSeconds / 3600);
            const m = Math.floor((remSeconds % 3600) / 60);
            const s = remSeconds % 60;
            if (el) el.textContent =
                String(h).padStart(2,"0") + ":" +
                String(m).padStart(2,"0") + ":" +
                String(s).padStart(2,"0");
        }, 1000);
    }

    window.handleTakeIt = async function() {
        const btn = document.getElementById("takeBtn");
        const isTaken = btn && btn.textContent.includes("Taken");
        if (isTaken) return;

        const result = await Swal.fire({
            title: "Take this order?",
            text: "You will have 4 hours to deliver it.",
            icon: "question",
            showCancelButton: true,
            confirmButtonText: "Yes, Take It",
            confirmButtonColor: "#16a34a",
            cancelButtonText: "Cancel"
        });
        if (!result.isConfirmed) return;

        const fd = new FormData();
        fd.append("action",    "taken");
        fd.append("order_id",  ORDER_ID);
        fd.append("csrf_token", window._csrfToken || "");

        const data = await fetchWithCsrfRetry(window.URLROOT + "/admin/orders/take", { method: "POST", body: fd });
        if (data.csrf_token && typeof updateCsrfToken === 'function') updateCsrfToken(data.csrf_token);
        if (data.success) {
            location.reload();
        } else {
            if (typeof showToast === 'function') showToast(data.message || "Error", "error");
        }
    };

    window.updateDelivery = async function(action) {
        const isDelivered = action === "mark_delivered";
        const confirmText = isDelivered
            ? "Confirm that the order has been delivered successfully?"
            : "Cancel the delivery of this order?";
        const notifMsg = isDelivered
            ? "Your order (" + PRODUCT_NAMES + ") has been delivered on " + ORDER_DATE + "."
            : "Your order (" + PRODUCT_NAMES + ") delivery was cancelled on " + ORDER_DATE + ".";

        const swalOptions = {
            title: isDelivered ? "Mark as Delivered?" : "Cancel Delivery?",
            text: confirmText,
            icon: "question",
            showCancelButton: true,
            confirmButtonText: "Yes, Confirm",
            confirmButtonColor: isDelivered ? "#16a34a" : "#dc2626",
            cancelButtonText: "Cancel"
        };
        if (!isDelivered) {
            swalOptions.input = "textarea";
            swalOptions.inputPlaceholder = "Reason for cancelling this order (required)...";
            swalOptions.inputValidator = function(value) {
                if (!value || !value.trim()) return "A reason is required before cancelling.";
            };
        }
        const result = await Swal.fire(swalOptions);
        if (!result.isConfirmed) return;
        const cancelReason = (!isDelivered && result.value) ? result.value.trim() : "";

        if (timerInterval) clearInterval(timerInterval);

        const fd = new FormData();
        fd.append("action",    action);
        fd.append("order_id",  ORDER_ID);
        fd.append("notif_msg", notifMsg);
        if (!isDelivered) fd.append("reason", cancelReason);
        fd.append("csrf_token", window._csrfToken || "");

        // كل عملية لها راوت منفصل — الـ URL نفسه يحدد العملية
        const endpoint = isDelivered
            ? window.URLROOT + "/admin/orders/mark-delivered"
            : window.URLROOT + "/admin/orders/cancel-delivery";

        const data = await fetchWithCsrfRetry(endpoint, { method: "POST", body: fd });
        if (data.csrf_token && typeof updateCsrfToken === 'function') updateCsrfToken(data.csrf_token);
        if (data.success) {
            Swal.fire("Done!", data.message, "success").then(() => location.reload());
        } else {
            if (typeof showToast === 'function') showToast(data.message || "Error", "error");
        }
    };

    window.handleReleaseOrder = async function() {
        const result = await Swal.fire({
            title: "Release this order?",
            text: "It will go back to Not Taken and any other eligible admin can pick it up.",
            icon: "warning",
            input: "textarea",
            inputPlaceholder: "Reason for releasing this order (required)...",
            inputValidator: function(value) {
                if (!value || !value.trim()) return "A reason is required before releasing.";
            },
            showCancelButton: true,
            confirmButtonColor: "#dc2626",
            confirmButtonText: "Yes, Release",
            cancelButtonText: "Cancel"
        });
        if (!result.isConfirmed) return;

        if (timerInterval) clearInterval(timerInterval);

        const fd = new FormData();
        fd.append("order_id", ORDER_ID);
        fd.append("reason", (result.value || "").trim());
        fd.append("csrf_token", window._csrfToken || "");

        const data = await fetchWithCsrfRetry(window.URLROOT + "/admin/orders/release", { method: "POST", body: fd });
        if (data.csrf_token && typeof updateCsrfToken === 'function') updateCsrfToken(data.csrf_token);
        if (data.success) {
            Swal.fire("Done!", data.message, "success").then(() => location.reload());
        } else {
            if (typeof showToast === 'function') showToast(data.message || "Error", "error");
        }
    };

    const reportTxt = document.getElementById("reportReason");
    const reportBtn = document.getElementById("reportBtn");
    if (reportTxt && reportBtn) {
        reportTxt.addEventListener("input", function() {
            if (typeof updateButtonState === 'function') {
                updateButtonState(reportBtn, reportTxt.value.trim().length > 0);
            }
        });
    }

    window.submitReport = async function() {
        const reason = reportTxt ? reportTxt.value.trim() : "";
        if (!reason) return;

        const result = await Swal.fire({
            title: "Report this issue?",
            text: "A note will be added to the user profile for admin review.",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#dc2626",
            confirmButtonText: "Yes, Report",
            cancelButtonText: "Cancel"
        });
        if (!result.isConfirmed) return;

        const fd = new FormData();
        fd.append("action",   "report_issue");
        fd.append("order_id", ORDER_ID);
        fd.append("reason",   reason);
        fd.append("csrf_token", window._csrfToken || "");

        const data = await fetchWithCsrfRetry(window.URLROOT + "/admin/orders/report-issue", { method: "POST", body: fd });
        if (data.csrf_token && typeof updateCsrfToken === 'function') updateCsrfToken(data.csrf_token);
        if (data.success) {
            if (typeof showToast === 'function') showToast("Issue reported and saved to user profile.", "success");
            if (reportTxt) reportTxt.value = "";
            if (typeof updateButtonState === 'function') updateButtonState(reportBtn, false);
        } else {
            if (typeof showToast === 'function') showToast(data.message || "Error", "error");
        }
    };
}