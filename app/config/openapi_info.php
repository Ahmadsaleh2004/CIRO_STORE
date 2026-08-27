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

// النطاق مطلوب بـPSR-4/PSR-12: كل كلاس في نطاق من مستوى واحد على
// الأقل. الكلاس هنا علامة لا أكثر — وجوده كي تُعلَّق عليه سمات
// swagger-php، ولا مستدعٍ له في المشروع (مفحوص). والنطاق لا يؤثّر على
// الفحص: swagger-php يمسح المسارات لا الأسماء.
namespace App\Config;

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

// ══════════════════════════════════════════════════════════════
// الوسوم — تجميع الواجهة إلى قسمين
// ══════════════════════════════════════════════════════════════
//
// كانت الأوصاف تكرّر الأسماء حرفياً ('Admin Auth' وصفها 'Admin Auth')
// — أي أنها لا تضيف شيئاً لمن يقرأ. والأسوأ أن التسمية كانت متناقضة:
// ستّة وسوم بلا شرطة مقابل ستّة عشر بشرطة، **ومنها وسمان لنفس الشيء**
// ('Admin My Info' و'Admin - My Info') فانقسمت نقاط الصفحة الواحدة بين
// قسمين في صفحة التوثيق. وُحّدت كلها على `القسم - الاسم`.
//
// الترتيب هنا هو ترتيب الظهور في Swagger UI: المتجر أولاً لأنه الواجهة
// العامة، ثم لوحة التحكم.

#[OA\Tag(name: 'Store - Pages', description: 'صفحات المتجر الثابتة: الرئيسية، من نحن، اتصل بنا.')]
#[OA\Tag(name: 'Store - Products', description: 'تصفّح المنتجات وتفاصيلها والنسخ اللونية.')]
#[OA\Tag(name: 'Store - Auth', description: 'تسجيل الدخول والحساب الجديد، واستعادة كلمة السر، ودخول Google.')]
#[OA\Tag(name: 'Store - Cart', description: 'السلّة — محفوظة في المتصفح ويُتحقّق من مخزونها عند الخروج.')]
#[OA\Tag(name: 'Store - Checkout', description: 'إتمام الطلب وإلغاؤه، وعناوين الشحن.')]
#[OA\Tag(name: 'Store - Account', description: 'بيانات المستخدم وعناوينه وكلمة سرّه.')]
#[OA\Tag(name: 'Store - Wishlist', description: 'المفضّلة، وتنبيه توفّر المخزون.')]
#[OA\Tag(name: 'Store - Notifications', description: 'إشعارات المستخدم — القائمة والتعليم كمقروء والحذف.')]

#[OA\Tag(
    name: 'Admin - Auth',
    description: <<<'TXT'
    دخول لوحة التحكم: كلمة السر، ثم TOTP إن كان مفعّلاً، وhCaptcha.

    جلسة الأدمن اسمها admin_session ومنفصلة تماماً عن جلسة المتجر.
    و«وضع المتجر» يسمح للأدمن بتصفّح الواجهة العامة، والخروج منه يتطلّب
    إعادة إدخال كلمة السر.
    TXT
)]
#[OA\Tag(name: 'Admin - Home', description: 'الصفحة الرئيسية للوحة — بطاقات الوصول السريع.')]
#[OA\Tag(name: 'Admin - Dashboard', description: 'الإحصاءات: المبيعات والطلبات المعلّقة والمستخدمون الجدد.')]
#[OA\Tag(name: 'Admin - Manage Products', description: 'إضافة المنتجات وتعديلها وحذفها، والنسخ اللونية والتصنيفات.')]
#[OA\Tag(
    name: 'Admin - Manage Orders',
    description: <<<'TXT'
    الطلبات: الاستلام والتسليم والإفراج والإلغاء.

    الطلب المستلَم يُفرَج عنه تلقائياً بعد انتهاء المهلة، ويُسجَّل ذلك في
    order_expiry_log. وكل انتقال حالة يجري داخل معاملة.
    TXT
)]
#[OA\Tag(name: 'Admin - Manage Users', description: 'المستخدمون: العرض والحظر والضربات والحذف.')]
#[OA\Tag(
    name: 'Admin - Manage Admins',
    description: <<<'TXT'
    حسابات الأدمنية وصلاحياتهم.

    محكومة بقاعدة الرتب: A أعلى من B أعلى من C أعلى من D، ولا يدير أدمنٌ
    رتبتَه — المقارنة «أكبر تماماً» لا «أكبر أو يساوي».
    TXT
)]
#[OA\Tag(name: 'Admin - Support', description: 'رسائل الدعم الواردة من نموذج «اتصل بنا».')]
#[OA\Tag(name: 'Admin - Messaging', description: 'إشعار مستخدم بعينه، أو بثّ رسالة لمجموعة.')]
#[OA\Tag(name: 'Admin - Notifications', description: 'إشعارات الأدمن — تصل من أفعال أدمنية أدنى رتبة.')]
#[OA\Tag(name: 'Admin - Branding', description: 'سلايدر الصفحة الرئيسية والهوية البصرية.')]
#[OA\Tag(name: 'Admin - Site Settings', description: 'إعدادات المتجر العامة.')]
#[OA\Tag(name: 'Admin - My Info', description: 'ملف الأدمن الشخصي وكلمة سرّه وتفعيل TOTP.')]
#[OA\Tag(
    name: 'Admin - Backup',
    description: 'النسخ الاحتياطي لقاعدة البيانات — رتبة A وحدها. كلمة السر تُمرَّر عبر ملف خيارات لا سطر أوامر.'
)]

class OpenApiInfo
{
}
