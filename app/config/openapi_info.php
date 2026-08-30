<?php

/**
 * app/config/openapi_info.php
 * The top-level OpenAPI definition — scanned by zircote/swagger-php to generate
 * public/docs/openapi.yaml.
 *
 * Why apiKey/cookie rather than http/bearer?
 * Because admin authentication rests on a PHP session (the admin_session cookie),
 * created in AdminAuthController::login() through session_regenerate_id() plus
 * $_SESSION. There is no JWT and no bearer token — the cookie is sent
 * automatically with every protected request.
 */

// The namespace is required by PSR-4/PSR-12: every class lives in a namespace of
// at least one level. The class here is a marker and nothing more — it exists so
// swagger-php attributes have something to attach to, and it has no caller
// anywhere in the project (verified). The namespace does not affect scanning:
// swagger-php walks paths, not names.
namespace App\Config;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.1.0',
    title: 'Cairo Store API',
    description: <<<'TXT'
    Every store and admin-panel endpoint.

    A general note on errors: the JSON endpoints in this project return 200 even
    when validation fails, and the outcome is read from the `success` field in the
    body rather than from the HTTP status. The only exceptions are 302 (redirect
    to sign-in), 403 (missing permission) and 404 (unregistered route). This is
    existing behaviour the front end depends on, and it is documented here as it
    is rather than as it ought to be.
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
    description: 'PHP session cookie — created automatically at sign-in and sent with every protected request'
)]
#[OA\SecurityScheme(
    securityScheme: 'userSessionAuth',
    type: 'apiKey',
    in: 'cookie',
    name: 'PHPSESSID',
    description: <<<'TXT'
    The regular user session — entirely separate from admin_session in both name
    and contents, so admin data cannot be reached from a user session or the other
    way round (see isUser/isAdmin in auth_helper.php).
    TXT
)]

// ══════════════════════════════════════════════════════════════
// Tags — grouping the surface into two halves
// ══════════════════════════════════════════════════════════════
//
// The descriptions used to repeat the names verbatim ('Admin Auth' described as
// 'Admin Auth') — adding nothing for whoever reads them. Worse, the naming was
// inconsistent: six tags without a dash against sixteen with one, **including two
// tags for the same thing** ('Admin My Info' and 'Admin - My Info'), which split
// one page's endpoints across two sections of the documentation. They are now all
// unified on `Section - Name`.
//
// The order here is the order they appear in Swagger UI: the store first, since
// it is the public surface, then the admin panel.

#[OA\Tag(name: 'Store - Pages', description: 'Static store pages: home, about, contact.')]
#[OA\Tag(name: 'Store - Products', description: 'Browsing products, their details, and their colour variants.')]
#[OA\Tag(name: 'Store - Auth', description: 'Sign-in, registration, password recovery, and Google sign-in.')]
#[OA\Tag(name: 'Store - Cart', description: 'The cart — kept on the server, with its stock verified at checkout.')]
#[OA\Tag(name: 'Store - Checkout', description: 'Placing and cancelling an order, and shipping addresses.')]
#[OA\Tag(name: 'Store - Account', description: "The user's profile, addresses and password.")]
#[OA\Tag(name: 'Store - Wishlist', description: 'The wishlist, and back-in-stock alerts.')]
#[OA\Tag(name: 'Store - Notifications', description: 'User notifications — listing, marking read, and deleting.')]

#[OA\Tag(
    name: 'Admin - Auth',
    description: <<<'TXT'
    Signing in to the admin panel: password, then TOTP if enabled, plus hCaptcha.

    The admin session is named admin_session and is entirely separate from the
    store session. "Store mode" lets an admin browse the public surface, and
    leaving it requires entering the password again.
    TXT
)]
#[OA\Tag(name: 'Admin - Home', description: 'The panel landing page — quick-access cards.')]
#[OA\Tag(name: 'Admin - Dashboard', description: 'Statistics: sales, pending orders, and new users.')]
#[OA\Tag(name: 'Admin - Manage Products', description: 'Adding, editing and deleting products, plus colour variants and categories.')]
#[OA\Tag(
    name: 'Admin - Manage Orders',
    description: <<<'TXT'
    Orders: taking, delivering, releasing and cancelling.

    A taken order is released automatically once its deadline passes, and that is
    recorded in order_expiry_log. Every state transition runs inside a transaction.
    TXT
)]
#[OA\Tag(name: 'Admin - Manage Users', description: 'Users: viewing, blocking, strikes and deletion.')]
#[OA\Tag(
    name: 'Admin - Manage Admins',
    description: <<<'TXT'
    Admin accounts and their permissions.

    Governed by the rank rule: A outranks B outranks C outranks D, and no admin
    manages their own rank — the comparison is "strictly greater", not "greater
    than or equal".
    TXT
)]
#[OA\Tag(name: 'Admin - Support', description: 'Support messages arriving from the contact form.')]
#[OA\Tag(name: 'Admin - Messaging', description: 'Notifying one particular user, or broadcasting to a group.')]
#[OA\Tag(name: 'Admin - Notifications', description: 'Admin notifications — raised by the actions of lower-ranked admins.')]
#[OA\Tag(name: 'Admin - Branding', description: 'The home page slider and the visual identity.')]
#[OA\Tag(name: 'Admin - Site Settings', description: 'General store settings.')]
#[OA\Tag(name: 'Admin - My Info', description: "The admin's own profile, password, and TOTP setup.")]
#[OA\Tag(
    name: 'Admin - Backup',
    description: 'Database backups — rank A only. The password is passed through an options file, never on the command line.'
)]

class OpenApiInfo
{
}
