<?php

namespace App\Controllers;

// ملاحظة: كل Controller خاص بلوحة التحكم لاحقاً لازم يستخدم نفس
// session_name('admin_session') قبل session_start() — حتى لا تُنسى
// هذه النقطة عند بناء باقي صفحات الأدمن.

use App\Core\AdminController;
use OpenApi\Attributes as OA;

/**
 * AdminHomeController — صفحة لوحة التحكم الرئيسية.
 * يرث من AdminController الذي يتحقق من تسجيل دخول الأدمن تلقائياً.
 */
class AdminHomeController extends AdminController
{
    #[OA\Get(
        path: '/admin/home',
        summary: 'صفحة لوحة التحكم الرئيسية',
        tags: ['Admin Home'],
        security: [['adminSessionAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'صفحة HTML للوحة التحكم — يتطلب جلسة admin_session صالحة'),
            new OA\Response(response: 302, description: 'إعادة توجيه لـ /admin/login إذا لم تكن الجلسة صالحة'),
        ]
    )]
    public function index(): void
    {
        startAdminSession();

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $this->adminView('home', [
            // TODO: تمرير إحصائيات Dashboard (طلبات، مستخدمون، منتجات...) عند بناء الـ Dashboard الكامل
        ]);
    }
}
