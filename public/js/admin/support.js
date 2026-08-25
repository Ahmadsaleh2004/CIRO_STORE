// ══════════════════════════════════════════════════════════════
// js/admin/support.js — منطق صفحة Support Messages بلوحة الأدمن
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {

    // Guard — لا تشتغل إلا بصفحة Support فعلاً (نفس مبدأ site-settings.js)
    if (!document.getElementById('supportMessagesList')) return;

    // ── Reply ─────────────────────────────────────────────────
    document.querySelectorAll('.btn-reply-support').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation(); // منع تفعيل حدث الكارد

            var userId   = btn.dataset.userId;
            var userName = btn.dataset.userName;

            Swal.fire({
                title: 'Reply to Message',
                text:  '"' + userName + '"',
                input: 'textarea',
                inputLabel: 'Your reply message',
                inputPlaceholder: 'Type your reply here...',
                inputAttributes: { 'aria-label': 'Reply message', rows: 5 },
                showCancelButton: true,
                confirmButtonText: 'Send Reply',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#198754',
                inputValidator: function (value) {
                    if (!value || !value.trim()) {
                        return 'Reply message cannot be empty.';
                    }
                }
            }).then(function (result) {
                if (!result.isConfirmed) return;

                var replyText = result.value.trim();
                var csrfToken = document.getElementById('csrfTokenSupport').value;

                var fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('user_id',    userId);
                fd.append('reply_text', replyText);

                // TODO: Rate limiting على عملية الرد — تذكرة منفصلة (غير موجود بالمشروع الجديد حالياً)

                fetchWithCsrfRetry(window.URLROOT + '/admin/support/reply', {
                    method: 'POST',
                    body: fd
                }).then(function (data) {
                    if (data.success) {
                        showToast('Reply sent successfully.', 'success');
                    } else {
                        showToast(data.message || 'Failed to send reply.', 'error');
                    }
                }).catch(function () {
                    showToast('Network error. Please try again.', 'error');
                });
            });
        });
    });

    // ── Delete ────────────────────────────────────────────────
    document.querySelectorAll('.btn-delete-support').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation(); // منع تفعيل حدث الكارد

            var msgId = btn.dataset.msgId;

            Swal.fire({
                title: 'Delete Message?',
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc3545',
            }).then(function (result) {
                if (!result.isConfirmed) return;

                var csrfToken = document.getElementById('csrfTokenSupport').value;

                var fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('message_id', msgId);

                fetchWithCsrfRetry(window.URLROOT + '/admin/support/delete', {
                    method: 'POST',
                    body: fd
                }).then(function (data) {
                    if (data.success) {
                        var card = document.getElementById('msg-card-' + msgId);
                        if (card) {
                            card.style.transition = 'opacity .35s ease, transform .35s ease';
                            card.style.opacity    = '0';
                            card.style.transform  = 'scale(.96)';
                            setTimeout(function () { card.remove(); }, 370);
                        }
                        showToast('Message deleted.', 'success');
                    } else {
                        showToast(data.message || 'Could not delete message.', 'error');
                    }
                }).catch(function () {
                    showToast('Network error. Please try again.', 'error');
                });
            });
        });
    });

    // ── Card click → navigate to user details ─────────────────
    document.querySelectorAll('.support-msg-card').forEach(function (card) {
        card.addEventListener('click', function () {
            var uid = card.dataset.userId;
            if (!uid || uid === '0') return; // Guest — لا ملف مستخدم

            // TODO: صفحة /admin/users/{id} لسا مو منشأة — راوت مستقبلي، تذكرة منفصلة
            window.location.href = window.URLROOT + '/admin/users/' + uid;
        });
    });

});
