<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use OpenApi\Attributes as OA;
use Throwable;

/**
 * HealthController — هل التطبيق صالح لاستقبال الطلبات؟
 *
 * ── لماذا استعلام حقيقي لا «200 وحسب» ──────────────────────
 *
 * فحص يردّ 200 لمجرّد أن Apache يستجيب يقول شيئاً واحداً: أن Apache
 * يستجيب. والحاوية التي فقدت اتصالها بالقاعدة تجتاز فحصاً كهذا وتبقى
 * في دوران الحمل، فتستقبل الطلبات وتفشل فيها كلها.
 *
 * ولهذا يُنفَّذ استعلام فعلي. `SELECT 1` لا استعلام على جدول: يثبت أن
 * الاتصال حيّ والمصادقة سليمة، بلا أن يعتمد على وجود بيانات ولا على
 * مخطّط بعينه — فيبقى الفحص صحيحاً أثناء الهجرات.
 *
 * ── ما لا تُرجعه ───────────────────────────────────────────
 *
 * **لا نسخ ولا أسماء ولا مسارات.** النقطة عامة بلا مصادقة (وهي كذلك
 * بالضرورة: فاحص الصحّة لا يملك جلسة)، فكل ما تكشفه مكشوف للجميع.
 * رسالة الاستثناء تذهب إلى السجلّ وحده.
 */
class HealthController extends Controller
{
    // ⚠️ الكتم هنا لا عند header(): القاعدة المحلية تطابق **الفعل كاملاً**
    // ابتداءً من سمة OA، وsemgrep يربط nosemgrep ببداية المطابقة لا
    // بالسطر الذي أثارها. وضعه عند السطر «المنطقي» لا يكتم شيئاً — وهو
    // نفس الخطأ الذي كان في تعليقات unlink بـAdminBrandingController.
    //
    // ولماذا الاستثناء أصلاً: القاعدة تبلّغ عن رأس JSON بلا
    // beginJsonPost() ولا verifyCsrfToken، وهي محقّة عادةً لأن النمط
    // يعني نقطة تُعدّل الحالة بلا حماية. لكن هذه هي الحالة التي تسمّيها
    // القاعدة نفسها استثناءً: GET للقراءة المحضة. `SELECT 1` أدناه لا
    // يمسّ صفّاً ولا جدولاً، والنقطة عامة بالضرورة — فاحص الصحّة لا
    // يملك جلسة ولا توكناً.
    // nosemgrep: cairo-json-endpoint-without-csrf
    #[OA\Get(
        path: '/health',
        summary: 'فحص صحّة التطبيق',
        description: <<<'TXT'
        تُرجع 200 حين يكون التطبيق قادراً على خدمة الطلبات فعلاً، و503
        حين لا يكون. الفحص ينفّذ استعلاماً حقيقياً على قاعدة البيانات
        لا مجرّد ردّ ثابت — فحاوية تردّ 200 وقاعدتها ساقطة ليست سليمة.

        عامة بلا مصادقة بالضرورة: فاحص الصحّة لا يملك جلسة. ولذلك لا
        تكشف نسخاً ولا أسماء ولا مسارات — التفاصيل في السجلّ وحده.
        TXT,
        tags: ['Store - Pages'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'التطبيق سليم.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', enum: ['ok'], example: 'ok'),
                        new OA\Property(
                            property: 'checks',
                            properties: [
                                new OA\Property(property: 'database', type: 'string', example: 'ok'),
                            ],
                            type: 'object'
                        ),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 503,
                description: 'التطبيق غير قادر على خدمة الطلبات.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', enum: ['fail'], example: 'fail'),
                        new OA\Property(
                            property: 'checks',
                            properties: [
                                new OA\Property(property: 'database', type: 'string', example: 'fail'),
                            ],
                            type: 'object'
                        ),
                    ],
                    type: 'object'
                )
            ),
        ]
    )]
    public function index(): void
    {
        $databaseOk = true;

        try {
            // SELECT 1 لا استعلام على جدول: يثبت الاتصال والمصادقة بلا
            // أن يعتمد على مخطّط قد يكون في منتصف هجرة.
            Database::connect()->query('SELECT 1')->fetchColumn();
        } catch (Throwable $e) {
            $databaseOk = false;
            error_log('[Cairo Store] health: فشل فحص قاعدة البيانات — ' . $e->getMessage());
        }

        $healthy = $databaseOk;

        if (!headers_sent()) {
            http_response_code($healthy ? 200 : 503);
            header('Content-Type: application/json; charset=utf-8');
            // لا تخزين مؤقّت إطلاقاً: نتيجة محفوظة تُبقي حاوية ميّتة
            // تبدو حيّة حتى انتهاء صلاحيتها.
            header('Cache-Control: no-store, max-age=0');
        }

        echo json_encode([
            'status' => $healthy ? 'ok' : 'fail',
            'checks' => [
                'database' => $databaseOk ? 'ok' : 'fail',
            ],
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}
