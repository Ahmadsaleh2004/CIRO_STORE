// ══════════════════════════════════════════════════════════════
// js/admin/admin-layout/admin-navbar.js — شريط لوحة التحكم العلوي
// ══════════════════════════════════════════════════════════════
//
// نُقلت logoutAdmin() من كتلة <script> مضمّنة في
// app/views/admin/inc/navbar.php. بقي في الـview سطر واحد يمرّر
// window._csrfToken — بيانات لا منطق.
//
// الدالة تبقى **عامة** عن قصد: الماركب يستدعيها من onclick مباشرة
// (<a onclick="logoutAdmin()">)، فتغليفها في IIFE كان سيكسر الزر.
//
// منفصلة عن logoutUser() الخاصة بالمتجر: الجلستان مختلفتان اسماً
// ومحتوى (admin_session مقابل PHPSESSID) ونقطتا الخروج مختلفتان.

// fetch عارٍ عن قصد — لا fetchWithCsrfRetry: AdminAuthController::logout
// **لا تتحقق من توكن CSRF** (تُدمّر الجلسة وتحوّل بـ302، ولا تُرجع
// JSON أصلاً). والغلاف يستدعي response.json() فكان سيرمي على تحويل.
function logoutAdmin() {
    fetch(window.URLROOT + '/admin/logout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(window._csrfToken)
    }).then(() => { window.location.href = window.URLROOT + '/admin/login'; });
}
