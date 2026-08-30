<?php

namespace App\Controllers;

// Note: every future admin-panel controller must use the same
// session_name('admin_session') before session_start() — recorded here so the
// point is not forgotten while the remaining admin pages are built.

use App\Core\AdminController;
use OpenApi\Attributes as OA;

/**
 * AdminHomeController — the admin panel's landing page.
 * Extends AdminController, which verifies the admin login automatically.
 */
class AdminHomeController extends AdminController
{
    #[OA\Get(
        path: '/admin/home',
        summary: 'Admin panel home page',
        tags: ['Admin - Home'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Admin panel HTML page — requires a valid admin_session'),
            new OA\Response(response: 302, description: 'Redirect to /admin/login when the session is not valid'),
        ]
    )]
    public function index(): void
    {
        startAdminSession();

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $this->adminView('home', [
            // TODO: pass dashboard statistics (orders, users, products...) once the full dashboard is built
        ]);
    }
}
