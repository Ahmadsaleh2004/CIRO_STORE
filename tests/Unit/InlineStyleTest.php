<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Zero inline styles in the views — which is what makes the CSP strict.
 *
 * `style-src` carried 'unsafe-inline' from the first day because the views carried 234
 * `style="…"` attributes. And a policy that permits inline styles cannot prevent an injected
 * one — so the attribute had to disappear before the directive could be tightened.
 *
 * The test here is what keeps the directive honest. One attribute returning to a view makes
 * the page render without that style — a silent visual fault that appears only on the
 * deployment, because the CSP is not usually enforced on a local development server.
 *
 * ⚠️ The check covers the views alone. CSS files are the natural home for styles, and a
 * style attribute in an email's text (Mailer::template) is not governed by a CSP at all — a
 * mail client is not the browser.
 */
final class InlineStyleTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function viewFiles(): array
    {
        $root  = dirname(__DIR__, 2) . '/app/views';
        $files = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function testNoViewCarriesAnInlineStyleAttribute(): void
    {
        $offenders = [];

        foreach (self::viewFiles() as $path) {
            $lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) ?: [];

            foreach ($lines as $n => $line) {
                if (preg_match('/\sstyle\s*=\s*["\']/i', $line)) {
                    $offenders[] = sprintf(
                        '%s:%d  %s',
                        basename(dirname($path)) . '/' . basename($path),
                        $n + 1,
                        trim(mb_substr($line, 0, 90))
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "An inline style in a view — it breaks the strict style-src:\n  " . implode("\n  ", $offenders)
        );
    }

    public function testNoViewCarriesAnInlineStyleBlock(): void
    {
        $offenders = [];

        foreach (self::viewFiles() as $path) {
            // The comments are blanked first — **both kinds**. A tag *mentioned* inside a
            // comment is not a working block; it is the explanation that prevents it coming
            // back. Counting it as a violation punishes the documentation.
            // (scripts/audit.php learned the same rule when its counter jumped from 55 to
            // 337 because of one comment.)
            //
            // ⚠️ PHP comments were added to the blanking after the views' comments moved
            // from HTML form to PHP form, so they are not shipped to the visitor. And the
            // test failed immediately on checkout.php — which carries the line explaining
            // that a <style> block **was moved** into a file.
            //
            // And the failure was literally correct: the rule knew one kind of comment. So
            // the fix belonged in the rule rather than in the view — editing the template to
            // avoid mentioning the tag would have deleted documentation to satisfy a test.
            $src = preg_replace(
                ['/<!--.*?-->/s', '#/\*.*?\*/#s', '#//[^\n?]*#'],
                '',
                (string) file_get_contents($path)
            ) ?? '';

            if (preg_match('/<style[\s>]/i', $src)) {
                $offenders[] = basename(dirname($path)) . '/' . basename($path);
            }
        }

        $this->assertSame([], $offenders, "A <style> block in a view:\n  " . implode("\n  ", $offenders));
    }

    /**
     * And no JS file builds markup carrying an inline style.
     *
     * ⚠️ This check was added by the CSP rather than by review. After the views were cleaned
     * the suite was green while the browser reported 27 blocked styles on every page: eight
     * JS files were building HTML as strings containing `style="…"` and then injecting it.
     * And injected markup is subject to style-src exactly as server-rendered markup is.
     *
     * The lesson encoded here: "zero inline styles" is a question about **the final page**
     * rather than about the view files. A test that examines the first source alone declares
     * victory while the browser blocks thirty styles.
     */
    public function testNoScriptBuildsMarkupWithAnInlineStyle(): void
    {
        $offenders = [];
        $jsRoot    = dirname(__DIR__, 2) . '/public/js';

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($jsRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }

            // dist/ is build output — its source is checked rather than it.
            if (str_contains(str_replace('\\', '/', $file->getPathname()), '/dist/')) {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            // The comments are blanked: variant-swatches.js explains the style it replaced,
            // and that explanation is what prevents it coming back.
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? '';
            $src = preg_replace('#^\s*//.*$#m', '', $src) ?? '';

            if (preg_match('/style\s*=\s*\\\\?["\']/', $src)) {
                $offenders[] = basename($file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A JS file injecting markup with an inline style — style-src blocks it:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * And the directive itself really is strict.
     *
     * Without this check the two tests above keep guarding the views while the policy has
     * been loosened in .htaccess — the door guarded and the window left open.
     */
    public function testTheCspItselfForbidsInlineStyles(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');

        $this->assertMatchesRegularExpression(
            '/Header always set Content-Security-Policy/',
            $htaccess,
            'The CSP is not enforced at all.'
        );

        if (!preg_match('/style-src([^;"]*)/', $htaccess, $m)) {
            $this->fail('There is no style-src directive in the CSP.');
        }

        $this->assertStringNotContainsString(
            'unsafe-inline',
            $m[1],
            "style-src permits inline styles — the directive guards nothing:\n  style-src{$m[1]}"
        );
    }

    /**
     * And script-src likewise — a neighbouring check guarding what an earlier phase achieved.
     */
    public function testTheCspForbidsInlineScriptsToo(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');

        if (!preg_match('/script-src([^;"]*)/', $htaccess, $m)) {
            $this->fail('There is no script-src directive in the CSP.');
        }

        $this->assertStringNotContainsString('unsafe-inline', $m[1]);
        $this->assertStringNotContainsString('unsafe-eval', $m[1]);
    }
}
