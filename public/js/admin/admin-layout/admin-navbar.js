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
// تحوّل بـ302 ولا تُرجع JSON أصلاً، والغلاف يستدعي response.json() فكان
// سيرمي على التحويل.
//
// التوكن يُرسَل ويُتحقَّق منه على الخادم منذ إصلاح CSRF الخروج. توكن
// منتهٍ يعني بقاء الأدمن داخلاً وتحويله إلى /admin/home — وهو فشل مرئي
// لا صامت، فلا حاجة لإعادة محاولة تلقائية هنا.
function logoutAdmin() {
    // nosemgrep: cairo-bare-fetch-post
    fetch(window.URLROOT + '/admin/logout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(window._csrfToken)
    }).then(() => { window.location.href = window.URLROOT + '/admin/login'; });
}
