<?php

/**
 * app/config/openapi/schemas.php
 * المخططات المشتركة لمواصفة OpenAPI.
 *
 * لماذا ملف مستقل؟ لأن المواصفة قبله كانت تحوي **صفر `$ref` وصفر
 * schema**: كل عملية من الـ103 تصف جسمها بأسطر مضمّنة تخصّها وحدها.
 * النتيجة أن شكل «الطلب» أو «المنتج» مكتوب عشرات المرّات بصياغات
 * تتفرّق كلما عُدِّلت واحدة — وهو بالضبط ما تمنعه المخططات.
 *
 * كل شيء هنا يوصف **كما هو فعلاً** لا كما ينبغي أن يكون. الأنواع
 * والقابلية للإفراغ مأخوذة من مخطّط قاعدة البيانات الحقيقي
 * (tests/fixtures/schema.sql).
 */

namespace App\Config\OpenApi;

use OpenApi\Attributes as OA;

// ══════════════════════════════════════════════════════════════
// 1. غلاف الاستجابة الموحّد
// ══════════════════════════════════════════════════════════════
//
// كل نقاط JSON تُرجع {success, message, ...} — الشكل مفروض في
// Controller::respond() ويعتمد عليه js/core/utils.js. توثيقه مرّة واحدة
// يجعل أي انحراف عنه ظاهراً.

#[OA\Schema(
    schema: 'ApiResponse',
    title: 'غلاف استجابة JSON الموحّد',
    description: <<<'TXT'
    الشكل الذي تُرجعه كل نقطة JSON في المشروع، من Controller::respond().

    كود HTTP يبقى 200 حتى عند الفشل. النتيجة تُقرأ من الحقل success لا
    من كود الحالة. هذا سلوك قائم يعتمد عليه الـfrontend في 34 ملف JS.
    TXT,
    required: ['success', 'message'],
    properties: [
        new OA\Property(
            property: 'success',
            type: 'boolean',
            description: 'مصدر الحقيقة الوحيد لنجاح العملية أو فشلها.',
            example: true
        ),
        new OA\Property(
            property: 'message',
            type: 'string',
            description: 'نصّ للعرض على المستخدم. لا تعتمد عليه برمجياً — راجع error_code.',
            example: 'تمت العملية بنجاح.'
        ),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'ApiError',
    title: 'استجابة فشل تحمل رمزاً صريحاً',
    description: <<<'TXT'
    مثل ApiResponse لكن success=false ومعها error_code اختياري.

    الرمز عقد بين الخادم والمتصفح، والنصّ للعرض وحده. الفصل بينهما ليس
    تفضيلاً أسلوبياً: كان js/core/csrf.js يكتشف فشل CSRF بمطابقة بداية
    نصّ الرسالة، فأي نقطة تصوغ رسالتها بشكل آخر تفقد إعادة المحاولة
    التلقائية بصمت. حدث ذلك ثلاث مرّات قبل أن يُستبدل النصّ برمز.
    TXT,
    required: ['success', 'message'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(
            property: 'message',
            type: 'string',
            example: 'Invalid CSRF token, please refresh and try again.'
        ),
        new OA\Property(
            property: 'error_code',
            type: 'string',
            description: 'رمز ثابت تقرأه الآلة. يُضاف حين يحتاج العميل التصرّف لا العرض فقط.',
            enum: ['csrf_invalid'],
            example: 'csrf_invalid'
        ),
    ],
    type: 'object'
)]

// ══════════════════════════════════════════════════════════════
// 2. كيانات المتجر
// ══════════════════════════════════════════════════════════════

#[OA\Schema(
    schema: 'Product',
    title: 'منتج',
    description: 'صفّ من جدول products. الأسعار decimal(10,2) وتصل كنصوص من PDO.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 42),
        new OA\Property(property: 'name', type: 'string', example: 'iPhone 16 Pro'),
        new OA\Property(property: 'name_ar', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'description_ar', type: 'string', nullable: true),
        new OA\Property(property: 'country_of_origin', type: 'string', nullable: true, example: 'USA'),
        new OA\Property(property: 'manufacturer', type: 'string', nullable: true, example: 'Apple'),
        new OA\Property(property: 'price', type: 'string', format: 'decimal', example: '54999.00'),
        new OA\Property(property: 'discount_percentage', type: 'string', format: 'decimal', example: '10.00'),
        new OA\Property(
            property: 'price_after_discount',
            type: 'string',
            format: 'decimal',
            nullable: true,
            example: '49499.10'
        ),
        new OA\Property(property: 'gender_category', type: 'string', enum: ['male', 'female', 'both']),
        new OA\Property(property: 'image_path', type: 'string', nullable: true),
        new OA\Property(
            property: 'stock_quantity',
            type: 'integer',
            description: 'العمود unsigned فلا قيمة سالبة ممكنة. العتبة 50 تحوّل الشارة إلى «محدود».',
            example: 12
        ),
        new OA\Property(property: 'sales_count', type: 'integer', example: 130),
        new OA\Property(property: 'is_visible', type: 'boolean', example: true),
        new OA\Property(property: 'date_added', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'ProductVariant',
    title: 'نسخة لونية من منتج',
    description: 'صفّ من product_variants. لكل منتج variant افتراضي واحد (is_default).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 7),
        new OA\Property(property: 'product_id', type: 'integer', example: 42),
        new OA\Property(property: 'color_name', type: 'string', example: 'Black Titanium'),
        new OA\Property(property: 'color_hex', type: 'string', nullable: true, example: '#2f2f31'),
        new OA\Property(property: 'price', type: 'string', format: 'decimal', example: '54999.00'),
        new OA\Property(property: 'discount_percentage', type: 'string', format: 'decimal', example: '0.00'),
        new OA\Property(property: 'price_after_discount', type: 'string', format: 'decimal', nullable: true),
        new OA\Property(property: 'stock_quantity', type: 'integer', example: 5),
        new OA\Property(property: 'gender_category', type: 'string', enum: ['male', 'female', 'both']),
        new OA\Property(property: 'image_path', type: 'string', nullable: true),
        new OA\Property(property: 'is_default', type: 'boolean', example: true),
        new OA\Property(property: 'sort_order', type: 'integer', example: 0),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'Category',
    title: 'تصنيف',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 3),
        new OA\Property(property: 'name', type: 'string', example: 'Smartphones'),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'StockBadge',
    title: 'شارة المخزون',
    description: <<<'TXT'
    ناتج getStockBadge() في app/helpers/stock_badge_helper.php.

    للدالة مرآة في JS (stockBadge في js/core/utils.js) تخدم البطاقات التي
    يبنيها المتصفح. العتبة 50 والنصوص مكرّرة بين اللغتين عمداً في مشروع
    بلا خطوة بناء. القيمة null تعني «لا شارة» — مخزون وفير في سياق لا
    يطلب شارة خضراء.
    TXT,
    properties: [
        new OA\Property(property: 'label', type: 'string', example: 'Limited (7 left)'),
        new OA\Property(property: 'class', type: 'string', example: 'bg-warning text-dark'),
    ],
    type: 'object',
    nullable: true
)]

// ══════════════════════════════════════════════════════════════
// 3. الطلبات
// ══════════════════════════════════════════════════════════════

#[OA\Schema(
    schema: 'OrderItem',
    title: 'سطر في طلب',
    description: 'السعر مخزَّن وقت الشراء لا مقروءاً من المنتج — تغيير سعر المنتج لاحقاً لا يمسّ طلباً منجزاً.',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'order_id', type: 'integer'),
        new OA\Property(property: 'product_id', type: 'integer', nullable: true),
        new OA\Property(property: 'variant_id', type: 'integer', nullable: true),
        new OA\Property(property: 'quantity', type: 'integer', example: 2),
        new OA\Property(property: 'unit_price', type: 'string', format: 'decimal', example: '54999.00'),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'Order',
    title: 'طلب',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1024),
        new OA\Property(property: 'user_id', type: 'integer', nullable: true),
        new OA\Property(
            property: 'status',
            type: 'string',
            description: 'حالة الطلب. الانتقالات محكومة في OrderModel داخل معاملات.',
            example: 'pending'
        ),
        new OA\Property(property: 'total_price', type: 'string', format: 'decimal', example: '109998.00'),
        new OA\Property(
            property: 'taken_by_admin_id',
            type: 'integer',
            nullable: true,
            description: 'الأدمن المستلم. يُفرَج عنه تلقائياً بعد المهلة (order_expiry_log).'
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'Address',
    title: 'عنوان مستخدم',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'user_id', type: 'integer'),
        new OA\Property(property: 'address_line', type: 'string'),
        new OA\Property(property: 'city', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+201234567890'),
    ],
    type: 'object'
)]

// ══════════════════════════════════════════════════════════════
// 4. المستخدمون والأدمنية
// ══════════════════════════════════════════════════════════════

#[OA\Schema(
    schema: 'User',
    title: 'مستخدم متجر',
    description: 'حقل password لا يظهر في أي استجابة إطلاقاً.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 88),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
        new OA\Property(property: 'phone_number', type: 'string', nullable: true),
        new OA\Property(property: 'is_blocked', type: 'boolean', example: false),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'Admin',
    title: 'حساب أدمن',
    description: <<<'TXT'
    الرتب مرتّبة تماماً: A=4 أعلى من B=3 أعلى من C=2 أعلى من D=1.

    القاعدة الحاكمة في AdminModel::canManageTarget هي «أكبر تماماً» لا
    «أكبر أو يساوي» — فلا يدير أدمنٌ رتبتَه. لو كانت الثانية لاستطاع كل
    أدمن حذف أقرانه، ومنهم من أضاف حسابه. ورتبة A تتجاوز كل الصلاحيات.
    TXT,
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'full_name', type: 'string', example: 'Root Admin'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'role', type: 'string', enum: ['A', 'B', 'C', 'D'], example: 'B'),
        new OA\Property(property: 'totp_enabled', type: 'boolean', example: true),
        new OA\Property(property: 'phone_number', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'AdminPermissions',
    title: 'صلاحيات أدمن',
    description: 'صفّ من admin_permissions. رتبة A تتجاوزها كلها في hasPermission().',
    properties: [
        new OA\Property(property: 'can_manage_admins', type: 'boolean'),
        new OA\Property(property: 'can_manage_products', type: 'boolean'),
        new OA\Property(property: 'can_manage_users', type: 'boolean'),
        new OA\Property(property: 'can_view_dashboard', type: 'boolean'),
        new OA\Property(property: 'can_manage_support', type: 'boolean'),
        new OA\Property(property: 'can_edit_site_content', type: 'boolean'),
        new OA\Property(property: 'can_manage_checkout_settings', type: 'boolean'),
        new OA\Property(property: 'can_manage_orders', type: 'boolean'),
        new OA\Property(property: 'can_manage_branding', type: 'boolean'),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'Notification',
    title: 'إشعار',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'title', type: 'string', example: 'Order Taken'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'is_read', type: 'boolean', example: false),
        new OA\Property(property: 'related_type', type: 'string', nullable: true, example: 'order'),
        new OA\Property(property: 'related_id', type: 'integer', nullable: true, example: 1024),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'SupportMessage',
    title: 'رسالة دعم',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'user_id', type: 'integer', nullable: true),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'SliderItem',
    title: 'شريحة في السلايدر',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'slider_id', type: 'integer'),
        new OA\Property(property: 'product_id', type: 'integer', nullable: true),
        new OA\Property(property: 'image_path', type: 'string', nullable: true),
        new OA\Property(property: 'sort_order', type: 'integer'),
    ],
    type: 'object'
)]

// ══════════════════════════════════════════════════════════════
// 5. حقل CSRF — يتكرّر في كل نموذج POST
// ══════════════════════════════════════════════════════════════

#[OA\Schema(
    schema: 'CsrfToken',
    title: 'توكن CSRF',
    description: <<<'TXT'
    مطلوب في كل نقطة POST عدا ثلاث موثّقة الاستثناء.

    يُقرأ من $_POST أو من جسم JSON معاً (Controller::requestData)، فيصحّ
    إرساله بأيّ من الشكلين. عند الفشل تُرجع النقطة error_code بقيمة
    csrf_invalid، فيجلب js/core/csrf.js توكناً جديداً ويعيد المحاولة
    مرّة واحدة تلقائياً.
    TXT,
    type: 'string',
    example: '3f2a9c1e8b7d6f5a4c3b2a190e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a291807'
)]

final class Schemas
{
}
