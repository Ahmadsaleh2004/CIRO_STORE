<?php
/**
 * app/config/openapi_info.php
 * تعريف معلومات OpenAPI العامة — يُفحص بواسطة zircote/swagger-php
 * لتوليد public/docs/openapi.yaml
 *
 * لماذا apiKey/cookie وليس http/bearer؟
 * لأن مصادقة الأدمن تعتمد على PHP session (admin_session cookie)
 * يُنشأ في AdminAuthController::login() عبر session_regenerate_id() + $_SESSION.
 * لا يوجد JWT أو Bearer token — الكوكي يُرسل تلقائياً مع كل طلب محمي.
 */

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.1.0',
    title: 'Cairo Store API',
    description: <<<'TXT'
    كل نقاط المتجر ولوحة التحكم.

    ملاحظة عامة على الأخطاء: نقاط JSON في هذا المشروع تُرجع 200 حتى عند
    فشل التحقق، والنتيجة تُقرأ من الحقل success في الجسم لا من كود HTTP.
    الاستثناءات الوحيدة هي 302 (تحويل لتسجيل الدخول) و403 (صلاحية ناقصة)
    و404 (راوت غير موجود). هذا سلوك قائم يعتمد عليه الـfrontend، ووُثّق
    هنا كما هو لا كما ينبغي أن يكون.
    TXT
)]
#[OA\Server(
    url: 'http://localhost/STORE/public',
    description: 'Local / Development Server'
)]
#[OA\SecurityScheme(
    securityScheme: 'adminSessionAuth',
    type: 'apiKey',
    in: 'cookie',
    name: 'admin_session',
    description: 'PHP session cookie — يُنشأ تلقائياً عند تسجيل الدخول ويُرسل مع كل طلب محمي'
)]
#[OA\SecurityScheme(
    securityScheme: 'userSessionAuth',
    type: 'apiKey',
    in: 'cookie',
    name: 'PHPSESSID',
    description: <<<'TXT'
    جلسة المستخدم العادي — منفصلة تماماً عن admin_session بالاسم
    والمحتوى، فلا يمكن الوصول لبيانات الأدمن من جلسة مستخدم ولا العكس
    (راجع isUser/isAdmin في auth_helper.php).
    TXT
)]
class OpenApiInfo {}
