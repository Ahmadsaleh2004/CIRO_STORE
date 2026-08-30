<?php

/**
 * app/config/openapi/responses.php
 * Shared, reusable responses.
 *
 * Before this file the spec carried 122 responses, distributed like this:
 *     200 → 95 times
 *     302 → 18
 *     403 →  8
 *     401 →  1
 * and **zero** of 400, 404, 422 and 500. Which is to say every endpoint
 * documented its happy path alone, and a reader of the spec could not tell how an
 * endpoint fails or what to do about it.
 *
 * Operations reference them with `new OA\Response(ref: '#/components/responses/…')`
 * so each is written once and used a hundred times, and their wording never drifts
 * apart.
 */

namespace App\Config\OpenApi;

use OpenApi\Attributes as OA;

// ══════════════════════════════════════════════════════════════
// Success
// ══════════════════════════════════════════════════════════════

#[OA\Response(
    response: 'JsonSuccess',
    description: 'Success — success=true.',
    content: new OA\JsonContent(ref: '#/components/schemas/ApiResponse')
)]

#[OA\Response(
    response: 'HtmlPage',
    description: 'A complete HTML page.',
    content: new OA\MediaType(mediaType: 'text/html')
)]

#[OA\Response(
    response: 'CsvDownload',
    description: 'A CSV file for download (Content-Disposition: attachment).',
    content: new OA\MediaType(mediaType: 'text/csv')
)]

// ══════════════════════════════════════════════════════════════
// Failure
// ══════════════════════════════════════════════════════════════

#[OA\Response(
    response: 'CsrfFailure',
    description: <<<'TXT'
    CSRF token validation failed.

    The HTTP status stays 200 — the failure is read from success=false and from
    error_code. The client (js/core/csrf.js) detects the code, fetches a fresh
    token, and retries exactly once, automatically. Which is why the user does not
    normally see this error at all.
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
    Invalid input (a missing field, a malformed email, a message below the minimum length…).

    The HTTP status stays 200 as on every other JSON endpoint; the distinction comes from success=false.
    TXT,
    content: new OA\JsonContent(
        ref: '#/components/schemas/ApiError',
        example: ['success' => false, 'message' => 'Message is too short (at least 10 characters).']
    )
)]

#[OA\Response(
    response: 'MethodNotAllowed',
    description: 'The request did not arrive as a POST. Refused in Controller::beginJsonPost before any logic runs.',
    content: new OA\JsonContent(
        ref: '#/components/schemas/ApiError',
        example: ['success' => false, 'message' => 'Method not allowed.']
    )
)]

#[OA\Response(
    response: 'SessionExpired',
    description: <<<'TXT'
    No valid session. Middleware::requireAdmin returns this with a 401 for
    AJAX/POST requests; full page requests are redirected to the sign-in page with
    a 302 instead.
    TXT,
    content: new OA\JsonContent(
        ref: '#/components/schemas/ApiError',
        example: ['success' => false, 'message' => 'Session expired. Please log in again.']
    )
)]

#[OA\Response(
    response: 'PermissionDenied',
    description: <<<'TXT'
    The session is valid but the permission is missing (Middleware::requirePermission).

    Rank A overrides every permission, so it never reaches here. The message is
    deliberately generic: naming the missing permission would draw the caller a map
    of the system.
    TXT,
    content: new OA\JsonContent(
        ref: '#/components/schemas/ApiError',
        example: ['success' => false, 'message' => 'Access denied. You do not have permission for this action.']
    )
)]

#[OA\Response(
    response: 'NotFoundPage',
    description: 'An unregistered route or a missing resource. An HTML page from ErrorPage::notFound.',
    content: new OA\MediaType(mediaType: 'text/html')
)]

#[OA\Response(
    response: 'ServiceUnavailable',
    description: <<<'TXT'
    A technical fault preventing the request from completing — most often a failed database connection.

    503 rather than 500, deliberately: the service is temporarily unavailable, not
    "a server error", and the difference matters to search engines and monitoring
    tools. The details go to the error log and are never printed to the visitor — a
    PDO message carries the host name, the database name and the user name.
    TXT,
    content: new OA\MediaType(mediaType: 'text/html')
)]

#[OA\Response(
    response: 'RedirectToLogin',
    description: 'A 302 redirect to the sign-in page, with the original destination kept in the session.',
    headers: [
        new OA\Header(
            header: 'Location',
            description: 'The redirect destination.',
            schema: new OA\Schema(type: 'string')
        ),
    ]
)]

#[OA\Response(
    response: 'RedirectWithFlash',
    description: 'A 302 redirect with a flash message in the session, shown on the next page.',
    headers: [
        new OA\Header(
            header: 'Location',
            description: 'The redirect destination.',
            schema: new OA\Schema(type: 'string')
        ),
    ]
)]

final class Responses
{
}
