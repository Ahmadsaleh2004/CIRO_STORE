// ══════════════════════════════════════════════════════════════
// public/js/admin/dashboard-chart.js — رسم مبيعات آخر 30 يوماً
// ══════════════════════════════════════════════════════════════
//
// كان هذا المنطق كتلة <script> مضمّنة يبنيها
// AdminDashboardController بضمّ النصوص. وسياسة CSP في
// public/.htaccess تمنع script-src 'unsafe-inline'، فكانت الكتلة
// **محجوبة في كل تحميل**: canvas فارغ وسطر `Refused to execute
// inline script` في الـconsole، بلا أي أثر آخر.
//
// ولم يكن السماح ببصمة حلاًّ: بصمة CSP تُحسب على المحتوى حرفاً
// بحرف، ومحتوى تلك الكتلة يحمل أرقام مبيعات اليوم — فيتغيّر كل يوم
// وتبطل البصمة كل ليلة.
//
// فالمنطق هنا في ملف يسمح به `'self'`، والأرقام تعبر كبيانات في
// جزيرة JSON يطبعها app/views/admin/dashboard.php ويلتقطها
// js/core/page-data.js.

(function () {
    'use strict';

    function draw() {
        var canvas = document.getElementById('salesChart');
        if (!canvas) return;

        // الملف يُحمَّل في صفحة اللوحة وحدها، لكن الحارس يبقى: غياب
        // المكتبة (بصمة SRI خاطئة، أو CDN محجوب) يجب أن يُقال صراحةً
        // لا أن يظهر كـcanvas فارغ بلا سبب.
        if (typeof window.Chart === 'undefined') {
            console.error(
                '[dashboard-chart] Chart.js لم يُحمَّل — راجع بصمة '
                + "'chartjs' في app/helpers/assets_helper.php."
            );
            return;
        }

        var ctx = window.ADMIN_SALES_CHART;
        if (!ctx || !Array.isArray(ctx.labels) || !Array.isArray(ctx.values)) {
            console.error(
                '[dashboard-chart] جزيرة ADMIN_SALES_CHART مفقودة أو معطوبة — '
                + 'راجع pageData في app/views/admin/dashboard.php.'
            );
            return;
        }

        var dark = document.body.classList.contains('dark-mode');
        var grid = dark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)';
        var tc   = dark ? '#e6edf3' : '#1a1a2e';

        new window.Chart(canvas, {
            type: 'line',
            data: {
                labels: ctx.labels,
                datasets: [{
                    label: 'Sales ($)',
                    data: ctx.values,
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99,102,241,.12)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#6366f1',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: tc } } },
                scales: {
                    x: { ticks: { color: tc }, grid: { color: grid } },
                    y: { ticks: { color: tc }, grid: { color: grid } },
                },
            },
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', draw);
    } else {
        draw();
    }
})();
