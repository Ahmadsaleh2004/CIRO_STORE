<?php

namespace App\Core;

/**
 * AdminController — the shared parent class for every admin-panel controller.
 *
 * It verifies the admin login in the constructor — so any controller extending it
 * is protected automatically, with no need to call requireAdminLogin() by hand.
 *
 * It provides adminView() for rendering admin pages with the shared layout.
 * (head.php + navbar.php + [view] + footer.php).
 */
abstract class AdminController extends Controller
{
    public function __construct()
    {
        startAdminSession();
        if (!isAdmin()) {
            header('Location: ' . URLROOT . '/admin/login');
            exit;
        }
    }

    /**
     * Render an admin view with the shared layout.
     *
     * Assembling the layout itself now lives in Controller::view() — what remains
     * here is what belongs to the admin panel alone: injecting the admin variables,
     * and the admin/ path prefix.
     *
     * Injected automatically: $adminName, $adminRole, $adminId, $csrf,
     * $newOrders, $newMessages — alongside anything passed through $data.
     *
     * @param string $view  The view name without path or extension
     *                      (looked up as app/views/admin/<view>.php)
     * @param array<string, mixed> $data Extra variables passed to the view
     */
    protected function adminView(string $view, array $data = []): void
    {
        // Unread counters (orders / support messages) — injected automatically into
        // every admin page, so the navbar badge appears without each controller having
        // to ask for it
        $counters = getAdminUnreadCounters();

        // Written over $data rather than after extract: this is the old behaviour to
        // the letter — extract($data) used to precede these assignments, so they win
        // over any key of the same name coming from the controller.
        $data['adminName']   = $_SESSION['admin_name'] ?? 'Admin';
        $data['adminRole']   = getAdminRole();
        $data['adminId']     = getCurrentAdminId();
        $data['csrf']        = generateCsrfToken();
        $data['newOrders']   = $counters['orders'];
        $data['newMessages'] = $counters['messages'];

        $this->view('admin/' . $view, $data, 'admin');
    }

    /**
     * Send a CSV file for download — shared across every admin controller
     * (Admins/Users/Orders/Products…) to avoid repeating the same code.
     *
     * @param string $filename The download file name (including .csv)
     * @param list<string> $headers Column names (the first row of the file)
     * @param array<array-key, array<array-key, mixed>> $rows Each data row as a flat array in the same order as $headers
     */
    protected function sendCsv(string $filename, array $headers, array $rows): void
    {
        if (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 correctly
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}
