// ══════════════════════════════════════════════════════════════
// js/core/theme.js — managing dark and light mode
// ══════════════════════════════════════════════════════════════
//
// The theme is written in two places, and both are required:
//
//   1) body.dark-mode          — all of the project's CSS reads this.
//   2) data-bs-theme on <html> — Bootstrap 5.3 itself reads this.
//
// Before this change only (1) existed, so every Bootstrap component (the pagination,
// the dropdowns, .text-muted, the select arrow, the tables, the outline buttons) stayed
// on light-mode colours over a dark background — the source of most of the "text you
// cannot read" in dark mode.
//
// The attribute is also set in <head> before the first paint, through themeBootScript()
// in app/helpers/assets_helper.php, to prevent the white flash while navigating.

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
