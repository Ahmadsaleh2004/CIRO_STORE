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
    version: '1.0.0',
    title: 'Cairo Store Admin API',
    description: 'Admin panel endpoints for Cairo Store — authentication, session management, and dashboard access.'
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
class OpenApiInfo {}
