// ══════════════════════════════════════════════════════════════
// public/js/admin/dashboard-chart.js — the last 30 days' sales chart
// ══════════════════════════════════════════════════════════════
//
// This logic used to be an inline <script> block that AdminDashboardController assembled
// by string concatenation. And the CSP in public/.htaccess forbids
// script-src 'unsafe-inline', so the block was **blocked on every load**: an empty canvas
// and a `Refused to execute inline script` line in the console, and no other trace.
//
// And permitting it by hash was no solution: a CSP hash is computed over the content
// character for character, and that block's content carries the day's sales figures — so
// it changes daily and the hash expires every night.
//
// So the logic lives here in a file `'self'` permits, and the figures travel as data in
// a JSON island that app/views/admin/dashboard.php prints and that is picked up by
// js/core/page-data.js.

(function () {
    'use strict';

    function draw() {
        const canvas = document.getElementById('salesChart');
        if (!canvas) return;

        // The file is only loaded on the dashboard page, but the guard stays: a missing
        // library (a wrong SRI hash, or a blocked CDN) must be stated outright rather than
        // appearing as an empty canvas with no explanation.
        if (typeof window.Chart === 'undefined') {
            console.error(
                '[dashboard-chart] Chart.js did not load — check the '
                + "'chartjs' integrity hash in app/helpers/assets_helper.php."
            );
            return;
        }

        const ctx = window.ADMIN_SALES_CHART;
        if (!ctx || !Array.isArray(ctx.labels) || !Array.isArray(ctx.values)) {
            console.error(
                '[dashboard-chart] The ADMIN_SALES_CHART island is missing or malformed — '
                + 'check pageData in app/views/admin/dashboard.php.'
            );
            return;
        }

        const dark = document.body.classList.contains('dark-mode');
        const grid = dark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)';
        const tc   = dark ? '#e6edf3' : '#1a1a2e';

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
