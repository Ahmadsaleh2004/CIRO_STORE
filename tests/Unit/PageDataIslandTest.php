<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The page data islands are printed before the footer, not inside it.
 *
 * ══════════════════════════════════════════════════════════════
 * The fault this test was written for
 * ══════════════════════════════════════════════════════════════
 *
 * `js/core/page-data.js` is loaded **synchronously** at the top of the footer, deliberately:
 * every file after it reads `window`, so it has to come before all of them. And the price of
 * that is that it runs the moment the HTML parser reaches it — so it sees nothing of the
 * document beyond what was parsed before it.
 *
 * And `app/views/admin/orders/details.php` used to write:
 *
 *     $extraScripts = pageData([ 'ADMIN_ORDER_DETAILS' => [...] ]);
 *
 * And the footer prints `$extraScripts` **after** the page-data.js tag. So the island is
 * born after the file has already scanned the document, and it never reaches `window`.
 *
 * And because `orders.js` guards itself with
 * `if (typeof window.ADMIN_ORDER_DETAILS !== 'undefined')`, no error was thrown: the
 * condition failed quietly, so `window.handleTakeIt` was never defined, so the admin clicked
 * "Take It" and nothing happened at all. A fault with no trace on the screen and none in the
 * console beyond a single delegation line — and it was misdiagnosed as a race condition in
 * the database.
 *
 * The guard here is textual because the fault is textual: the position in the document is
 * the whole difference, and it shows up in no unit or integration test.
 */
final class PageDataIslandTest extends TestCase
{
    /** @return list<string> every view file */
    private function viewFiles(): array
    {
        $root  = dirname(__DIR__, 2) . '/app/views';
        $files = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * `$extraScripts = pageData(...)` is the broken shape exactly: assigning an island to a
     * variable the footer prints after its reader.
     *
     * The island is printed in the view's body (`<?= pageData([...]) ?>` or
     * `echo pageData([...])`), so it precedes the footer by ordering alone.
     */
    public function testNoViewPutsAPageDataIslandIntoExtraScripts(): void
    {
        $offenders = [];

        foreach ($this->viewFiles() as $path) {
            $src = (string) file_get_contents($path);

            // The project's comments explain the fault and name the broken shape itself, so
            // they are stripped first, or the test catches the documentation instead of the
            // code.
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (preg_match('/\$extraScripts\s*(\.?=)\s*pageData\s*\(/', $src)) {
                $offenders[] = str_replace(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A pageData island assigned to \$extraScripts in:\n  - "
            . implode("\n  - ", $offenders)
            . "\n\nThe footer prints \$extraScripts after the synchronous js/core/page-data.js, "
            . "so the data never reaches window and the feature fails silently.\n"
            . 'Print the island in the view\'s body instead.'
        );
    }

    /**
     * The page-data.js tag must stay synchronous and at the top of the script list in both
     * footers. A `defer` on it makes its precedence hostage to tag ordering rather than an
     * inviolable property.
     */
    public function testPageDataScriptIsLoadedFirstAndNotDeferred(): void
    {
        $footers = [
            'app/views/inc/footer.php',
            'app/views/admin/inc/footer.php',
        ];

        foreach ($footers as $relative) {
            $path = dirname(__DIR__, 2) . '/' . $relative;
            $src  = (string) file_get_contents($path);

            $this->assertMatchesRegularExpression(
                "/jsTag\(\s*'js\/core\/page-data\.js'\s*,\s*false\s*\)/",
                $src,
                "{$relative}: page-data.js must be loaded with jsTag(..., false) — synchronously."
            );

            $pageDataPos = strpos($src, 'js/core/page-data.js');
            $this->assertNotFalse($pageDataPos, "{$relative}: page-data.js is not included.");

            foreach (['vendorJs(', 'jsBundle('] as $later) {
                $laterPos = strpos($src, $later);
                if ($laterPos === false) {
                    continue;
                }

                $this->assertLessThan(
                    $laterPos,
                    $pageDataPos,
                    "{$relative}: {$later} comes before page-data.js — everything after it reads window."
                );
            }
        }
    }
}
