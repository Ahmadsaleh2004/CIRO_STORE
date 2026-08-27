<?php

/**
 * app/config/openapi/responses.php
 * استجابات مشتركة قابلة لإعادة الاستعمال.
 *
 * قبل هذا الملف كانت المواصفة تحمل 122 استجابة موزّعة هكذا:
 *     200 → 95 مرّة
 *     302 → 18
 *     403 →  8
 *     401 →  1
 * و**صفر** من 400 و404 و422 و500. أي أن كل نقطة توثّق مسار النجاح
 * وحده، ومن يقرأ المواصفة لا يعرف كيف تفشل النقطة ولا كيف يتصرّف.
 *
 * تُشار إليها من العمليات بـ`new OA\Response(ref: '#/components/responses/…')`
 * فتُكتب مرّة وتُستعمل مئة مرّة، ولا تتفرّق صياغاتها.
 */

namespace App\Config\OpenApi;

use OpenApi\Attributes as OA;

// ══════════════════════════════════════════════════════════════
// نجاح
// ══════════════════════════════════════════════════════════════

#[OA\Response(
    response: 'JsonSuccess',
    description: 'نجاح — success=true.',
    content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
)]

#[OA\Response(
    response: 'HtmlPage',
    description: 'صفحة HTML كاملة.',
    content: new OA\MediaType(mediaType: 'text/html')
)]

#[OA\Response(
    response: 'CsvDownload',
    description: 'ملف CSV للتحميل (Content-Disposition: attachment).',
    content: new OA\MediaType(mediaType: 'text/csv')
)]

// ══════════════════════════════════════════════════════════════
// فشل
// ══════════════════════════════════════════════════════════════

#[OA\Response(
    response: 'CsrfFailure',
    description: <<<'TXT'
    فشل التحقق من توكن CSRF.

    كود HTTP يبقى 200 — الفشل يُقرأ من success=false ومن error_code.
    العميل (js/core/csrf.js) يكتشف الرمز، يجلب توكناً جديداً، ويعيد
    المحاولة مرّة واحدة تلقائياً. لذلك لا يرى المستخدم هذا الخطأ عادةً.
    TXT,
    content: new OA\JsonContent(
        ref: '#/components/schemas/ApiError',
        example: [
            'success'    => false,
            'message'    => 'Invalid CSRF token, please refresh and try again.',
            'error_code' => 'csrf_invalid',
        ]
    )
)]

#[OA\Response(
    response: 'ValidationFailure',
    description: <<<'TXT'
    مدخلات غير صالحة (حقل ناقص، بريد غير صحيح، رسالة أقصر من الحدّ…).

    كود HTTP يبقى 200 كبقية نقاط JSON؛ التمييز من success=false.
    TXT,
    content: new OA\JsonContent(
        ref: '#/components/schemas/ApiError',
        example: ['success' => false, 'message' => 'Message is too short (at least 10 characters).']
    )
)]

#[OA\Response(
    response: 'MethodNotAllowed',
    description: 'الطلب لم يصل بـPOST. يُرفض في Controller::beginJsonPost قبل أي منطق.',
    content: new OA\JsonContent(
        ref: '#/components/schemas/ApiError',
        example: ['success' => false, 'message' => 'Method not allowed.']
    )
)]

#[OA\Response(
    response: 'SessionExpired',
    description: <<<'TXT'
    لا جلسة صالحة. يُرجعها Middleware::requireAdmin لطلبات AJAX/POST
    بكود 401؛ أما طلبات الصفحات الكاملة فتُحوَّل بـ302 إلى صفحة الدخول.
    TXT,
    content: new OA\JsonContent(
        ref: '#/components/schemas/ApiError',
        example: ['success' => false, 'message' => 'Session expired. Please log in again.']
    )
)]

#[OA\Response(
    response: 'PermissionDenied',
    description: <<<'TXT'
    الجلسة صالحة لكن الصلاحية ناقصة (Middleware::requirePermission).

    رتبة A تتجاوز كل الصلاحيات، فلا تصل إلى هنا أبداً. والرسالة عامة
    عمداً: كشف اسم الصلاحية الناقصة للزائر يرسم له خريطة النظام.
    TXT,
    content: new OA\JsonContent(
        ref: '#/components/schemas/ApiError',
        example: ['success' => false, 'message' => 'Access denied. You do not have permission for this action.']
    )
)]

#[OA\Response(
    response: 'NotFoundPage',
    description: 'راوت غير مسجَّل أو مورد غير موجود. صفحة HTML من ErrorPage::notFound.',
    content: new OA\MediaType(mediaType: 'text/html')
)]

#[OA\Response(
    response: 'ServiceUnavailable',
    description: <<<'TXT'
    عطل تقني يمنع إكمال الطلب — أبرزه فشل الاتصال بقاعدة البيانات.

    503 لا 500 عمداً: الخدمة غير متاحة مؤقتاً لا «خطأ في الخادم»،
    والفرق يهمّ محرّكات البحث وأدوات المراقبة. التفاصيل تذهب إلى سجلّ
    الأخطاء ولا تُطبع للزائر أبداً — رسالة PDO تحمل اسم المضيف واسم
    القاعدة واسم المستخدم.
    TXT,
    content: new OA\MediaType(mediaType: 'text/html')
)]

#[OA\Response(
    response: 'RedirectToLogin',
    description: 'تحويل 302 إلى صفحة الدخول مع حفظ الوجهة الأصلية في الجلسة.',
    headers: [
        new OA\Header(
            header: 'Location',
            description: 'وجهة التحويل.',
            schema: new OA\Schema(type: 'string')
        ),
    ]
)]

#[OA\Response(
    response: 'RedirectWithFlash',
    description: 'تحويل 302 مع رسالة flash في الجلسة تُعرَض في الصفحة التالية.',
    headers: [
        new OA\Header(
            header: 'Location',
            description: 'وجهة التحويل.',
            schema: new OA\Schema(type: 'string')
        ),
    ]
)]

final class Responses
{
}
