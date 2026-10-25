<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every icon the store asks for exists in the sprite.
 *
 * The store's icons are `<svg><use href="icons.svg#i-name"></use></svg>` — icon() in
 * assets_helper.php and window.icon in js/core/utils.js — and the sprite carries only the
 * symbols listed in ICONS in build/build-icons.mjs. A name missing from that list fails
 * silently: the browser renders an empty box where the icon should be, with no error on the
 * page and none in the console. So the check is made here, against the built file itself,
 * over every name the views and the scripts pass.
 */
final class IconSpriteTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return list<string> the symbol ids in public/images/icons.svg, without the "i-" prefix */
    private function spriteSymbols(): array
    {
        $sprite = (string) file_get_contents(self::ROOT . '/public/images/icons.svg');
        preg_match_all('/<symbol id="i-([a-z0-9-]+)"/', $sprite, $m);

        return $m[1];
    }

    /**
     * Each name passed as the first argument of icon(…) or window.icon(…), including both
     * branches of a ternary such as icon($isAdmin ? 'shield-lock' : 'key').
     *
     * @return array<string, list<string>> name => files that use it
     */
    private function requestedNames(): array
    {
        $names = [];
        $root  = str_replace('\\', '/', (string) realpath(self::ROOT));

        foreach (['app/views', 'public/js'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($it as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (!$file->isFile() || str_contains($path, '/dist/') || !preg_match('/\.(php|js)$/', $path)) {
                    continue;
                }

                $src = (string) file_get_contents($path);
                $rel = substr($path, strlen($root) + 1);

                // The first argument only — the second is a class list ('icon-love') — and of
                // a ternary only its branches, not the condition: in
                // window.icon(input.type === 'password' ? 'eye' : 'eye-slash') the names are
                // 'eye' and 'eye-slash'.
                preg_match_all('/\bicon\(\s*([^,)]*)/', $src, $calls);
                foreach ($calls[1] as $arg) {
                    $branches = str_contains($arg, '?') ? substr($arg, strpos($arg, '?') + 1) : $arg;
                    preg_match_all("/'([a-z0-9-]+)'/", $branches, $literal);
                    foreach ($literal[1] as $name) {
                        $names[$name][] = $rel;
                    }
                }

                // The home page tags carry their icon as data: { icon: 'star-fill', label: … }.
                // The label is required by the pattern: SweetAlert's own option is also called
                // `icon` ('success', 'warning') and names no symbol of ours.
                preg_match_all("/\bicon:\s*'([a-z0-9-]+)',\s*label:/", $src, $fields);
                foreach ($fields[1] as $name) {
                    $names[$name][] = $rel;
                }
            }
        }

        foreach (['phone', 'computer', 'accessories', 'gaming', 'a-category-added-later'] as $category) {
            $names[categoryIcon($category)][] = 'categoryIcon()';
        }

        return $names;
    }

    public function testEveryRequestedIconIsInTheSprite(): void
    {
        $symbols   = $this->spriteSymbols();
        $requested = $this->requestedNames();

        $this->assertNotEmpty($symbols, 'public/images/icons.svg has no symbols — run npm run build.');
        $this->assertGreaterThan(20, count($requested), 'The scan found almost no icon calls — has the call shape changed?');

        $missing = array_diff_key($requested, array_flip($symbols));

        $this->assertSame(
            [],
            array_map(static fn (array $files): string => implode(', ', array_unique($files)), $missing),
            "Icons requested but absent from the sprite — they render as empty boxes.\n"
            . 'Add the name to ICONS in build/build-icons.mjs and run npm run build.'
        );
    }

    public function testTheMarkupIsDecorativeAndPointsAtTheSprite(): void
    {
        $svg = icon('cart', 'icon-love');

        $this->assertStringContainsString('class="icon icon-love"', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertMatchesRegularExpression('#<use href="[^"]*/images/icons\.svg(\?v=[0-9a-f]{10})?\#i-cart">#', $svg);
    }

    public function testAMalformedNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        icon('cart"><script>');
    }
}
