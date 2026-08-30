<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use OpenApi\Attributes as OA;
use Throwable;

/**
 * HealthController — is this instance actually fit to serve requests?
 *
 * ── Why a real query and not just "200" ────────────────────
 *
 * A check that returns 200 because Apache answered tells you exactly one
 * thing: Apache answered. A container that has lost its database connection
 * passes that check, stays in the load balancer, accepts traffic, and fails
 * every request it takes.
 *
 * So a real query runs. `SELECT 1` rather than a query against a table: it
 * proves the connection is live and authentication works without depending on
 * any data existing or on a particular schema — so the check stays correct
 * mid-migration.
 *
 * ── What it deliberately does not return ───────────────────
 *
 * **No versions, no names, no paths.** The endpoint is public and
 * unauthenticated of necessity (a health prober has no session), so anything
 * it reveals is revealed to everyone. The exception message goes to the log
 * and nowhere else.
 */
class HealthController extends Controller
{
    // ⚠️ The suppression belongs here, not at the header() call: the local rule
    // matches **the whole method** starting at the OA attribute, and semgrep
    // binds a nosemgrep comment to the start of the match, not to the line that
    // triggered it. Placing it at the "logical" line suppresses nothing — the
    // same mistake the unlink comments in AdminBrandingController used to make.
    //
    // And why suppress at all: the rule reports a JSON header emitted without
    // beginJsonPost() or verifyCsrfToken, and it is usually right, because that
    // shape means a state-changing endpoint with no protection. But this is the
    // exception the rule itself names: a GET that only reads. The `SELECT 1`
    // below touches no row and no table, and the endpoint is public by
    // necessity — a health prober holds neither session nor token.
    // nosemgrep: cairo-json-endpoint-without-csrf
    #[OA\Get(
        path: '/health',
        summary: 'Application health check',
        description: <<<'TXT'
        Returns 200 when the application can actually serve requests, and 503
        when it cannot. The check runs a real database query rather than a
        canned response — a container that answers 200 while its database is
        down is not healthy.

        Public and unauthenticated of necessity: a health prober has no
        session. For that reason it exposes no versions, no names and no
        paths — details go to the log alone.
        TXT,
        tags: ['Store - Pages'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The application is healthy.',
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
                description: 'The application cannot serve requests.',
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
            // SELECT 1 rather than a query against a table: it proves the
            // connection and the credentials without depending on a schema that
            // may be halfway through a migration.
            Database::connect()->query('SELECT 1')->fetchColumn();
        } catch (Throwable $e) {
            $databaseOk = false;
            error_log('[Cairo Store] health: database check failed — ' . $e->getMessage());
        }

        $healthy = $databaseOk;

        if (!headers_sent()) {
            http_response_code($healthy ? 200 : 503);
            header('Content-Type: application/json; charset=utf-8');
            // No caching whatsoever: a stored result keeps a dead container
            // looking alive until the entry expires.
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
