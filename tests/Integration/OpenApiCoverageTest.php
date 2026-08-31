<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The specification gate — it turns a momentary measurement into a standing guarantee.
 *
 * Coverage stood at 103 of 104 endpoints. The number is good, but it is a snapshot: one
 * endpoint added to public/index.php without an OA attribute is enough to make it 103 of
 * 105, and nothing tells anybody. This test makes the gap fail the build rather than pass
 * silently.
 *
 * It reads both sides from their real sources — the router from index.php and the
 * specification from openapi.yaml — so no hand-written list goes stale.
 */
final class OpenApiCoverageTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The router's routes: ['get /products', 'post /checkout', ...]
     *
     * @return list<string>
     */
    private static function routerOperations(): array
    {
        $index = (string) file_get_contents(self::root() . '/public/index.php');
        preg_match_all("/->(get|post)\(\s*'([^']+)'/", $index, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $hit) {
            $out[] = strtolower($hit[1]) . ' ' . $hit[2];
        }

        return array_values(array_unique($out));
    }

    /**
     * The specification's operations in the same shape.
     *
     * A line-by-line read rather than a YAML parser: the project carries neither ext-yaml nor
     * a parsing library, and adding a dependency for the sake of one test costs more than it
     * is worth. The structure being read is stable because swagger-php is what generates it.
     *
     * @return list<string>
     */
    private static function specOperations(): array
    {
        $lines = file(self::root() . '/public/docs/openapi.yaml', FILE_IGNORE_NEW_LINES);
        $out = [];
        $path = null;
        $inPaths = false;

        foreach ($lines as $line) {
            if (preg_match('/^paths:/', $line)) {
                $inPaths = true;
                continue;
            }
            if (!$inPaths) {
                continue;
            }
            // The start of a top-level section (components, tags…) ends paths.
            if (preg_match('/^[a-z]/i', $line)) {
                break;
            }
            if (preg_match("/^  '?(\/[^:']*)'?:\s*$/", $line, $m)) {
                $path = $m[1];
                continue;
            }
            if ($path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m)) {
                $out[] = $m[1] . ' ' . $path;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Every route in the router is documented.
     *
     * Parameterised routes ({id}) are excluded: swagger-php writes them in a form that may
     * differ from the router's, and a textual comparison between the two produces noise
     * rather than information.
     */
    public function testEveryRouterOperationIsDocumented(): void
    {
        $router = array_filter(
            self::routerOperations(),
            static fn (string $op): bool => !str_contains($op, '{')
        );

        $missing = array_values(array_diff($router, self::specOperations()));

        $this->assertGreaterThan(90, count($router), 'The router reader did not find enough routes.');
        $this->assertSame(
            [],
            $missing,
            "Endpoints registered in public/index.php and absent from openapi.yaml.\n"
            . "Add an #[OA\\Get] or #[OA\\Post] attribute, then run `composer docs:generate`:\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * And the reverse: no documented operation without a route.
     *
     * Documenting an endpoint that does not exist is worse than not documenting it — whoever
     * reads the specification builds on it and then discovers a 404 at run time.
     */
    public function testNoDocumentedOperationLacksARoute(): void
    {
        $spec = array_filter(
            self::specOperations(),
            static fn (string $op): bool => !str_contains($op, '{')
        );

        $orphans = array_values(array_diff($spec, self::routerOperations()));

        $this->assertSame(
            [],
            $orphans,
            "Documented operations with no matching route — they describe an API that does not exist:\n  " . implode("\n  ", $orphans)
        );
    }

    /**
     * The specification is current against the code.
     *
     * openapi.yaml is both generated and tracked in git, which means it can fall behind:
     * somebody edits an OA attribute and forgets `composer docs:generate`, so the committed
     * file keeps describing an older version. Comparing the operation counts catches the
     * clearest form of that lag — an endpoint added or removed without regeneration.
     */
    public function testSpecOperationCountMatchesTheRouter(): void
    {
        $router = array_filter(
            self::routerOperations(),
            static fn (string $op): bool => !str_contains($op, '{')
        );

        $this->assertCount(
            count($router),
            self::specOperations(),
            'The specification\'s operation count does not match the router — run `composer docs:generate`.'
        );
    }

    /**
     * The shared components exist and really are referenced.
     *
     * The specification used to carry zero schemas and zero $refs: every operation described
     * its body with inline lines of its own, so the wordings diverged every time one of them
     * was edited. This test prevents sliding back into that state.
     */
    public function testSharedComponentsExistAndAreReferenced(): void
    {
        $yaml = (string) file_get_contents(self::root() . '/public/docs/openapi.yaml');

        foreach (['ApiResponse', 'ApiError', 'Product', 'Order', 'Admin', 'CsrfToken'] as $schema) {
            $this->assertStringContainsString(
                "    {$schema}:",
                $yaml,
                "The shared schema {$schema} is absent from components/schemas."
            );
        }

        foreach (['CsrfFailure', 'SessionExpired', 'PermissionDenied', 'ServiceUnavailable'] as $response) {
            $this->assertStringContainsString(
                "    {$response}:",
                $yaml,
                "The shared response {$response} is absent from components/responses."
            );
        }

        $refCount = substr_count($yaml, '$ref:');
        $this->assertGreaterThan(
            100,
            $refCount,
            "The \$ref count has fallen to {$refCount} — the operations are going back to describing their bodies inline."
        );
    }

    /**
     * The CSRF error code is documented in the specification.
     *
     * The contract between the server and js/core/csrf.js has to be readable to somebody
     * reading the specification alone. Its absence means a new consumer of the API will
     * rediscover the same mistake that cost this project three rounds.
     */
    public function testCsrfErrorCodeIsDocumented(): void
    {
        $yaml = (string) file_get_contents(self::root() . '/public/docs/openapi.yaml');

        $this->assertStringContainsString('csrf_invalid', $yaml, 'The CSRF failure code is not documented in the specification.');
    }
}
