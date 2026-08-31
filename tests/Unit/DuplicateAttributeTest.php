<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * No tag carries the same attribute twice.
 *
 * ══════════════════════════════════════════════════════════════
 * Why this test exists
 * ══════════════════════════════════════════════════════════════
 *
 * Because HTML does not treat a duplicated attribute as an error. The parser takes **the
 * first** and ignores the second silently — no message in the console, and nothing in the
 * developer tools saying an attribute was dropped. The page looks sound and the style is
 * missing.
 *
 * And that happened at twenty-nine sites in one go, all of them from a single migration:
 * moving 234 `style="…"` attributes into `u-*` classes in base/utilities.css. The new class
 * was added in a **second** `class` attribute rather than merged into the existing one, so
 * all of it went to waste.
 *
 * And the cost of that went beyond appearance. The winner follows the writing order rather
 * than the intent:
 *
 *   · admin/login.php — `class="form-group" … class="d-none"`,
 *     so the two-factor code field stayed **permanently visible**, a field that should be
 *     seen only when the server asks for it.
 *   · product/add.php and edit.php — the same shape on the red error message, so
 *     "Please select at least one category" was on display from the moment the page opened,
 *     before anybody had made a mistake.
 *   · branding/_templates.php — `u-thumb-preview` was dropped from the slider images, so
 *     they had no dimensions at all and started stretching their container.
 *   · product_dit.php — here `d-none` won and `mt-2 p-3 rounded border` was dropped, so the
 *     panel is hidden as it should be but with no padding and no border.
 *
 * The check covers every attribute rather than `class` alone: a duplicated `id`, `href` or
 * `data-*` is dropped with the same silence.
 */
final class DuplicateAttributeTest extends TestCase
{
    // Note: there is deliberately no exception list here. No attribute in HTML may validly
    // be repeated in one tag, so an empty list waiting for its first exception is not a
    // design but an open door — and PHPStan rejects it anyway as a condition that never
    // holds. The first real need for an exception adds it along with its written reason.

    /**
     * @param  string       $root      the directory to search
     * @param  string       $extension the extension wanted
     * @param  list<string> $skip      path fragments to exclude
     * @return list<string>
     */
    private function filesIn(string $root, string $extension, array $skip = []): array
    {
        $base  = dirname(__DIR__, 2) . '/' . $root;
        $files = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== $extension) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            foreach ($skip as $fragment) {
                if (str_contains($path, $fragment)) {
                    continue 2;
                }
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function viewFiles(): array
    {
        return $this->filesIn('app/views', 'php');
    }

    /**
     * The source files alone — `public/js/dist` is build output, and any fault in it is a
     * reflection of a fault in the source. Reporting it twice only adds confusion.
     *
     * @return list<string>
     */
    private function scriptFiles(): array
    {
        return $this->filesIn('public/js', 'js', ['/dist/']);
    }

    /**
     * PHP blocks are replaced with spaces of the same length before parsing.
     *
     * Two reasons: `<?= $x ? 'a' : 'b' ?>` may contain a `>` that cuts the tag short for a
     * simple textual parser, and `class="<?= … ?>"` is a value rather than a shape, so its
     * content does not concern this test. And the replacement preserves the lines, so their
     * numbers stay correct in the failure message.
     */
    private function maskPhp(string $src): string
    {
        return (string) preg_replace_callback(
            '/<\?(?:php|=).*?\?>/s',
            static function (array $m): string {
                $newlines = substr_count($m[0], "\n");

                return str_repeat(' ', strlen($m[0]) - $newlines) . str_repeat("\n", $newlines);
            },
            $src
        );
    }

    /**
     * Examines one piece of text and returns the duplicated attribute sites in it.
     *
     * @return list<string>
     */
    private function offendersIn(string $path, string $src): array
    {
        $found = [];

        if (preg_match_all('/<[a-zA-Z][^<>]*>/s', $src, $tags, PREG_OFFSET_CAPTURE)) {
            foreach ($tags[0] as [$tag, $offset]) {
                // The attribute's name: preceded by whitespace and followed by `=`. The
                // lookbehind prevents capturing part of a compound name such as data-class.
                if (!preg_match_all('/(?<=\s)([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=/', $tag, $attrs)) {
                    continue;
                }

                $seen = [];
                foreach ($attrs[1] as $name) {
                    $name = strtolower($name);

                    if (isset($seen[$name])) {
                        $line = substr_count(substr($src, 0, $offset), "\n") + 1;

                        // ⚠️ Normalise before trimming, not after. On Windows,
                        // RecursiveDirectoryIterator mixes the two separators — the root as
                        // it was passed in (`/`) and the children with the system separator
                        // (`\`) — so trimming a root written with one separator fails
                        // silently, and the failure message comes out with a long absolute
                        // path.
                        $normalised = str_replace('\\', '/', $path);
                        $root       = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';

                        $found[] = str_replace($root, '', $normalised)
                            . ':' . $line . "  (attribute: {$name})";
                        break;
                    }

                    $seen[$name] = true;
                }
            }
        }

        return $found;
    }

    public function testNoTagRepeatsAnAttribute(): void
    {
        $offenders = [];

        foreach ($this->viewFiles() as $path) {
            $src = $this->maskPhp((string) file_get_contents($path));
            $offenders = [...$offenders, ...$this->offendersIn($path, $src)];
        }

        $this->assertSame(
            [],
            $offenders,
            "A tag carrying the same attribute twice:\n  - " . implode("\n  - ", $offenders)
            . "\n\nThe browser takes the first and drops the second silently. Merge the two values into one attribute."
        );
    }

    /**
     * And markup generated from JavaScript is subject to the same rule.
     *
     * ══════════════════════════════════════════════════════════
     * Why a second check rather than widening the first
     * ══════════════════════════════════════════════════════════
     *
     * Because half of this project's interface is built in the browser: the wishlist cards,
     * the notification list, the product picker in Manage Slider, the category picker. They
     * are all `innerHTML` from string templates — so the browser parses them exactly as it
     * parses a view's markup, and drops a duplicated attribute with the same silence.
     *
     * And duplicates really were found there after the views were cleaned:
     * `product-picker-row` in js/admin/branding.js and `cat-delete-icon` in
     * js/admin/category-picker.js, both losing their second `u-*` class. Which is to say
     * confining the check to app/views left half the surface unguarded.
     *
     * And the string templates are examined as they are, uninterpreted: `${...}` stays
     * inside the attribute's value, and that does not concern the checker — the attribute's
     * name and its repetition do.
     */
    public function testNoScriptBuildsMarkupWithARepeatedAttribute(): void
    {
        $offenders = [];

        foreach ($this->scriptFiles() as $path) {
            $src = (string) file_get_contents($path);

            // The comments are stripped first: this project explains its faults in its
            // comments and names the broken shape itself in them — so without stripping, the
            // test catches the documentation instead of the code.
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            $offenders = [...$offenders, ...$this->offendersIn($path, $src)];
        }

        $this->assertSame(
            [],
            $offenders,
            "A JavaScript template building a tag with a duplicated attribute:\n  - "
            . implode("\n  - ", $offenders)
            . "\n\nThe browser takes the first and drops the second silently. Merge the two values into one attribute."
        );
    }
}
