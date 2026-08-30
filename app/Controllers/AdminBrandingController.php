<?php

namespace App\Controllers;

use App\Core\AdminController;
use App\Core\Middleware;
use App\Models\BrandingModel;
use App\Models\AdminModel;
use App\Services\SliderFormParser;
use OpenApi\Attributes as OA;

/**
 * AdminBrandingController — the home page slider.
 *
 * ⚠️ The slide and image limits (MAX_SLIDES / MAX_ITEMS_PER_SLIDE) moved to
 * SliderFormParser, alongside the validation that uses them. Keeping them here
 * as well would have meant the same two numbers in two places — and the worst
 * of that is one of them changing alone, so the advertised limit stops being
 * the enforced limit.
 */
class AdminBrandingController extends AdminController
{
    #[OA\Get(
        path: '/admin/branding',
        summary: 'Show the slider management page',
        tags: ['Admin - Branding'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, ref: '#/components/responses/HtmlPage'),
            new OA\Response(response: 302, ref: '#/components/responses/RedirectToLogin'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
            new OA\Response(response: 503, ref: '#/components/responses/ServiceUnavailable'),
        ]
    )]
    public function index(): void
    {
        Middleware::requirePermission('can_manage_branding');

        $flashMsg = $_SESSION['flash_msg'] ?? '';
        $flashErr = $_SESSION['flash_err'] ?? '';
        unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

        $this->adminView('branding/index', [
            'pageTitle' => 'Manage Slider',
            'sliders'   => BrandingModel::getFullSliderData(),
            'flashMsg'  => $flashMsg,
            'flashErr'  => $flashErr,
            'extraHead' => '<link rel="stylesheet" href="' . URLROOT . '/css/admin/pages/branding.css">',
        ]);
    }

    #[OA\Get(
        path: '/admin/branding/products/search',
        summary: 'Live product search for slider selection (AJAX)',
        tags: ['Admin - Branding'],
        security: [['adminSessionAuth' => []]],
        parameters: [new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operation result. The success field separates success from failure — the HTTP status stays 200 either way. On CSRF failure the body carries error_code=csrf_invalid.',
                content: new OA\JsonContent(oneOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                    new OA\Schema(ref: '#/components/schemas/ApiError'),
                ])
            ),
            new OA\Response(response: 401, ref: '#/components/responses/SessionExpired'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
        ]
    )]
    public function searchProducts(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        Middleware::requirePermission('can_manage_branding');

        $q = trim($_GET['q'] ?? '');
        $products = BrandingModel::searchProducts($q);

        // False positive: the rule chases "request input echoed back", and what is
        // printed here is json_encode over rows from the database, under the
        // application/json header set above. No $_GET reaches the output, and
        // json_encode escapes what it emits.
        // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
        echo json_encode(['success' => true, 'products' => $products], JSON_UNESCAPED_UNICODE);
        exit;
    }

    #[OA\Post(
        path: '/admin/branding/save',
        summary: 'Save the whole slider (full replace) — slides, items and image uploads',
        tags: ['Admin - Branding'],
        security: [['adminSessionAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [new OA\MediaType(mediaType: 'multipart/form-data')]
        ),
        responses: [
            new OA\Response(response: 302, ref: '#/components/responses/RedirectWithFlash'),
            new OA\Response(response: 403, ref: '#/components/responses/PermissionDenied'),
        ]
    )]
    public function save(): void
    {
        Middleware::requirePermission('can_manage_branding');

        // Deliberately not using beginJsonPost: this fails via redirectWithError,
        // not JSON. It is a form page, not an API endpoint — routing it through
        // beginJsonPost would turn the response into JSON.
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->redirectWithError('Invalid CSRF token, please refresh and try again.');
            return;
        }
        $oldImagePaths = BrandingModel::collectAllImagePaths();
        $uploadDir     = ROOTPATH . '/public/images/';

        // All the preparation lives in the service: reading the nested $_FILES,
        // validating, uploading. This used to be 130 lines here mixing that with
        // response formatting, so every error path ended in header() and exit —
        // which is to say, untestable.
        $parsed = SliderFormParser::parse($_POST['slides'] ?? [], $_FILES['slides'] ?? [], $uploadDir);

        // Cleanup in one place for every failure. cleanupNewUploads used to be
        // repeated across five spots, and forgetting it in a sixth meant orphaned
        // images left behind on disk.
        if ($parsed['error'] !== null) {
            $this->cleanupNewUploads($parsed['uploaded'], $uploadDir);
            $this->redirectWithError($parsed['error']);
            return;
        }

        $adminId = getCurrentAdminId();

        if (!BrandingModel::saveAll($parsed['slides'], $adminId)) {
            $this->cleanupNewUploads($parsed['uploaded'], $uploadDir);
            $this->redirectWithError('Failed to save the slider. Please try again.');
            return;
        }

        $this->deleteOrphanedImages($oldImagePaths, $parsed['images'], $uploadDir);

        AdminModel::logAction($adminId, 'update_branding_slider', 'branding', 0, 'Saved home slider content.');
        $this->notifyAboutBrandingChange($adminId);

        $_SESSION['flash_msg'] = '✅ Slider saved successfully.';
        header('Location: ' . URLROOT . '/admin/branding');
        exit;
    }

    /**
     * Deletes images that were in use and are no longer part of the new save.
     *
     * @param list<string> $oldPaths
     * @param list<string> $keptPaths
     */
    private function deleteOrphanedImages(array $oldPaths, array $keptPaths, string $uploadDir): void
    {
        foreach (array_diff($oldPaths, $keptPaths) as $orphanPath) {
            // basename() strips any path from the input, leaving nothing but the
            // file name — no `..`, no slashes, no absolute path. The resulting path
            // is confined to $uploadDir by construction. semgrep sees a data flow
            // from the database into unlink and does not see basename's effect.
            $disk = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . basename($orphanPath);
            if (file_exists($disk)) {
                // ⚠️ The suppression sits on the @unlink line itself. It used to sit
                // above the `if`, and semgrep binds the comment to the line directly
                // after it — so it suppressed the `file_exists` condition and
                // suppressed nothing at all, and the finding was reported exactly as
                // if no comment were there. (Measured: the finding appeared before
                // and after the refactor alike.)
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                @unlink($disk);
            }
        }
    }

    // ══════════════════════════════════════════════════════════
    // Private helpers
    // ══════════════════════════════════════════════════════════

    /**
     * @param list<string> $paths
     */
    private function cleanupNewUploads(array $paths, string $uploadDir): void
    {
        foreach ($paths as $p) {
            // As above: basename confines the name inside $uploadDir by construction.
            $disk = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . basename($p);
            if (file_exists($disk)) {
                // Suppression on the @unlink line itself — see the explanation in
                // deleteOrphanedImages above.
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                @unlink($disk);
            }
        }
    }
    private function redirectWithError(string $msg): void
    {
        $_SESSION['flash_err'] = $msg;
        header('Location: ' . URLROOT . '/admin/branding');
        exit;
    }

    /**
     * Notifications when the slider is edited:
     * — a confirmation notice for the editor themselves (an audit record)
     * — a notice for every admin holding can_manage_branding at a rank above the
     *   editor's, excluding the root admin (getRootAdminId) and the editor.
     */
    private function notifyAboutBrandingChange(int $adminId): void
    {
        AdminModel::notifyHigherRanksOnAction(
            actorAdminId:  $adminId,
            permission:    'can_manage_branding',
            title:         'Slider Updated',
            selfMessage:   'You saved the home slider content.',
            othersMessage: 'An admin updated the home slider content.',
            type:          'branding_updated',
            relatedType:   'branding',
            relatedId:     0
        );
    }
}
