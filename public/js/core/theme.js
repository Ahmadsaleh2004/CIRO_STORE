// ══════════════════════════════════════════════════════════════
// js/core/theme.js — إدارة الوضع المظلم والفاتح (Dark/Light Mode)
// ══════════════════════════════════════════════════════════════
//
// الثيم يُكتب في مكانين، وكلاهما مطلوب:
//
//   1) body.dark-mode      — يقرأه كل CSS المشروع.
//   2) data-bs-theme على <html> — يقرأه Bootstrap 5.3 نفسه.
//
// قبل هذا التعديل كانت (1) وحدها موجودة، فبقيت كل مكوّنات Bootstrap
// (الـ pagination، القوائم المنسدلة، .text-muted، سهم الـ select،
// الجداول، أزرار outline) على ألوان الوضع الفاتح فوق خلفية داكنة —
// وهو مصدر معظم "الكلام اللي ما بيبان" في الوضع الليلي.
//
// السمة تُضبط أيضاً في <head> قبل أول رسم عبر themeBootScript()
// في app/helpers/assets_helper.php، لمنع الومضة البيضاء عند التنقل.

function setTheme(isDark) {
    document.body.classList.toggle("dark-mode", isDark);
    document.documentElement.setAttribute("data-bs-theme", isDark ? "dark" : "light");
}

function applySavedTheme() {
    const isDark = localStorage.getItem("theme") === "dark";
    const themeToggle = document.getElementById("theme-toggle");

    setTheme(isDark);
    if (themeToggle) themeToggle.innerHTML = isDark ? "☀️" : "🌙";
}
window.applySavedTheme = applySavedTheme;

function initializeTheme() {
    const themeToggle = document.getElementById("theme-toggle");

    if (!themeToggle) return;

    themeToggle.innerHTML = document.body.classList.contains("dark-mode") ? "☀️" : "🌙";

    themeToggle.onclick = () => {
        const goingDark = !document.body.classList.contains("dark-mode");

        setTheme(goingDark);
        localStorage.setItem("theme", goingDark ? "dark" : "light");
        themeToggle.innerHTML = goingDark ? "☀️" : "🌙";
    };
}
window.initializeTheme = initializeTheme;
