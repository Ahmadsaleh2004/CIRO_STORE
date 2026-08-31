<?php

/**
 * app/config/openapi/schemas.php
 * The shared schemas for the OpenAPI specification.
 *
 * Why a file of its own? Because before it the spec contained **zero `$ref` and
 * zero schemas**: each of the 103 operations described its body with inline lines
 * belonging to it alone. The result was that the shape of "an order" or "a
 * product" was written out dozens of times, in wordings that drifted apart every
 * time one of them was edited — which is precisely what schemas prevent.
 *
 * Everything here describes the system **as it actually is**, not as it ought to
 * be. The types and nullability are taken from the real database schema
 * (tests/fixtures/schema.sql).
 *
 * ⚠️ This file is listed in composer.json under `autoload.files`, and it has to be.
 * Every attribute below attaches to `final class Schemas` at the foot of the file, and
 * swagger-php reads them by reflecting on that class — which first has to be loadable.
 * PSR-4 maps `App\Config\OpenApi\Schemas` to `app/Config/OpenApi/Schemas.php`, and the
 * real path is `app/config/openapi/schemas.php`. Windows resolves the two as the same
 * file and Linux does not.
 *
 * So without the explicit entry the class simply never loads on Linux, swagger-php finds
 * nothing to reflect, and it drops the file **without an error**: the generated
 * specification comes out 15KB shorter with `components.schemas` missing entirely, while
 * regenerating it on Windows looks perfectly correct. That is exactly how it reached CI —
 * green on the machine it was written on, red on the runner, for months.
 *
 * app/config/openapi_info.php is in that list for the same reason. If a third file joins
 * this directory, it belongs there too.
 */

namespace App\Config\OpenApi;

use OpenApi\Attributes as OA;

// ══════════════════════════════════════════════════════════════
// 1. The unified response envelope
// ══════════════════════════════════════════════════════════════
//
// Every JSON endpoint returns {success, message, ...} — the shape is enforced in
// Controller::respond() and js/core/utils.js depends on it. Documenting it once
// makes any deviation from it visible.

#[OA\Schema(
    schema: 'ApiResponse',
    title: 'The unified JSON response envelope',
    description: <<<'TXT'
    The shape every JSON endpoint in the project returns, from Controller::respond().

    The HTTP status stays 200 even on failure. The outcome is read from the
    `success` field, not from the status code. This is existing behaviour that the
    front end depends on across 34 JavaScript files.
    TXT,
    required: ['success', 'message'],
    properties: [
        new OA\Property(
            property: 'success',
            type: 'boolean',
            description: 'The single source of truth for whether the operation succeeded.',
            example: true
        ),
        new OA\Property(
            property: 'message',
            type: 'string',
            description: 'Text to show the user. Do not depend on it programmatically — see error_code.',
            example: 'The operation completed successfully.'
        ),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'ApiError',
    title: 'A failure response carrying an explicit code',
    description: <<<'TXT'
    Like ApiResponse, but with success=false and an optional error_code alongside.

    The code is a contract between server and browser; the text is for display
    alone. Separating them is not a stylistic preference: js/core/csrf.js used to
    detect a CSRF failure by matching the start of the message text, so any
    endpoint that worded its message differently lost the automatic retry, silently.
    That happened three times before the text was replaced by a code.
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
            description: 'A stable machine-readable code. Added when the client needs to act rather than merely display.',
            enum: ['csrf_invalid'],
            example: 'csrf_invalid'
        ),
    ],
    type: 'object'
)]

// ══════════════════════════════════════════════════════════════
// 2. Store entities
// ══════════════════════════════════════════════════════════════

#[OA\Schema(
    schema: 'Product',
    title: 'Product',
    description: 'A row from the products table. Prices are decimal(10,2) and arrive from PDO as strings.',
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
            description: 'The column is unsigned, so no negative value is possible. A threshold of 50 flips the badge to "limited".',
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
    title: 'A colour variant of a product',
    description: 'A row from product_variants. Every product has exactly one default variant (is_default).',
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
    title: 'Category',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 3),
        new OA\Property(property: 'name', type: 'string', example: 'Smartphones'),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'StockBadge',
    title: 'Stock badge',
    description: <<<'TXT'
    The output of getStockBadge() in app/helpers/stock_badge_helper.php.

    The function has a mirror in JavaScript (stockBadge in js/core/utils.js) that
    serves the cards the browser builds. The threshold of 50 and the label strings
    are duplicated across the two languages deliberately, in a project with no
    build step. A null means "no badge" — plentiful stock in a context that does
    not call for a green one.
    TXT,
    properties: [
        new OA\Property(property: 'label', type: 'string', example: 'Limited (7 left)'),
        new OA\Property(property: 'class', type: 'string', example: 'bg-warning text-dark'),
    ],
    type: 'object',
    nullable: true
)]

// ══════════════════════════════════════════════════════════════
// 3. Orders
// ══════════════════════════════════════════════════════════════

#[OA\Schema(
    schema: 'OrderItem',
    title: 'An order line',
    description: 'The price is stored at purchase time rather than read from the product — changing a product price later does not touch a completed order.',
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
    title: 'Order',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1024),
        new OA\Property(property: 'user_id', type: 'integer', nullable: true),
        new OA\Property(
            property: 'status',
            type: 'string',
            description: 'The order status. Transitions are governed in OrderModel, inside transactions.',
            example: 'pending'
        ),
        new OA\Property(property: 'total_price', type: 'string', format: 'decimal', example: '109998.00'),
        new OA\Property(
            property: 'taken_by_admin_id',
            type: 'integer',
            nullable: true,
            description: 'The admin holding it. Released automatically once the deadline passes (order_expiry_log).'
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]

#[OA\Schema(
    schema: 'Address',
    title: 'A user address',
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
// 4. Users and admins
// ══════════════════════════════════════════════════════════════

#[OA\Schema(
    schema: 'User',
    title: 'Store user',
    description: 'The password field never appears in any response.',
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
    title: 'Admin account',
    description: <<<'TXT'
    The ranks are totally ordered: A=4 outranks B=3 outranks C=2 outranks D=1.

    The governing rule in AdminModel::canManageTarget is "strictly greater", not
    "greater than or equal" — so no admin manages their own rank. Were it the
    latter, every admin could delete their peers, among them whoever created their
    account. Rank A overrides every permission.
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
    title: 'Admin permissions',
    description: 'A row from admin_permissions. Rank A overrides all of them in hasPermission().',
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
    title: 'Notification',
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
    title: 'Support message',
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
    title: 'A slider slide',
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
// 5. The CSRF field — repeated in every POST form
// ══════════════════════════════════════════════════════════════

#[OA\Schema(
    schema: 'CsrfToken',
    title: 'CSRF token',
    description: <<<'TXT'
    Required on every POST endpoint except three whose exemption is documented.

    It is read from $_POST and from a JSON body alike (Controller::requestData), so
    sending it in either form is valid. On failure the endpoint returns error_code
    with the value csrf_invalid, and js/core/csrf.js fetches a fresh token and
    retries exactly once, automatically.
    TXT,
    type: 'string',
    example: '3f2a9c1e8b7d6f5a4c3b2a190e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a291807'
)]

final class Schemas
{
}
