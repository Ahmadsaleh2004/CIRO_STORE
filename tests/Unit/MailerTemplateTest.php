<?php

namespace Tests\Unit;

use App\Core\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Mailer::template — the variable values are escaped, and no caller injects them.
 *
 * The email's body used to be built by direct interpolation, and among what was interpolated
 * was `$_SERVER['HTTP_USER_AGENT']` in the sign-in alert email — a header entirely under the
 * sender's control. Which is to say an attacker writes HTML into their browser's name and it
 * arrives in the admin's inbox as it is.
 *
 * The tests here work at two levels: that the function escapes what is passed to it, and
 * that **no caller** works around it by interpolating. The first alone is not enough — the
 * interpolation stays possible in the next line somebody writes.
 */
final class MailerTemplateTest extends TestCase
{
    public function testPlaceholderValuesAreEscaped(): void
    {
        $html = Mailer::template('A title', 'Browser: {ua}', [
            'ua' => '<img src=x onerror="alert(1)">',
        ]);

        // The check is on the tag rather than the string: the text "onerror=" is still
        // present inside the escaped value and is entirely inert there. The danger is a real
        // tag — that is, an unescaped `<` opening one.
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onerror="alert(1)">', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('onerror=&quot;alert(1)&quot;', $html);
    }

    public function testTheTitleIsEscapedToo(): void
    {
        $html = Mailer::template('<script>alert(1)</script>', 'Some text');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * The static template stays HTML — and that is the intent.
     *
     * Without it, no link could be put in a password reset email. The template is written in
     * the code and does not come from the network, and the danger was in the values rather
     * than in it.
     */
    public function testTheStaticTemplateKeepsItsMarkup(): void
    {
        $html = Mailer::template('A title', '<a href="{link}">Click</a>', [
            'link' => 'https://example.test/reset?token=abc',
        ]);

        $this->assertStringContainsString('<a href="https://example.test/reset?token=abc">', $html);
    }

    /**
     * A value carrying a quote mark does not break the attribute it is placed in.
     *
     * ENT_QUOTES rather than the default: without it a `"` passes through intact, so the
     * attacker closes the href attribute and opens another — an injection inside the tag
     * rather than around it.
     */
    public function testAQuoteInAValueCannotBreakOutOfAnAttribute(): void
    {
        $html = Mailer::template('A title', '<a href="{link}">x</a>', [
            'link' => '" onmouseover="alert(1)',
        ]);

        $this->assertStringNotContainsString('onmouseover="alert', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    public function testAnUnknownPlaceholderIsLeftAloneNotFilledWithSomethingElse(): void
    {
        $html = Mailer::template('A title', 'a {one} b {two}', ['one' => 'X']);

        $this->assertStringContainsString('a X b {two}', $html);
    }

    /**
     * No caller interpolates a variable into an email's body.
     *
     * This is the test that prevents the fault returning. The function's escaping protects
     * whoever passes through it; this prevents working around it — that is, a
     * `"... {$var} ..."` in the body argument, which is exactly what was written at seven
     * sites.
     */
    public function testNoCallerInterpolatesVariablesIntoAnEmailBody(): void
    {
        $offenders = [];

        foreach (glob(dirname(__DIR__, 2) . '/app/Controllers/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);

            // Capturing the body argument: what lies between `Mailer::template(` and the
            // start of the array or the end of the call.
            if (!preg_match_all('/Mailer::template\((.*?)\n\s*\)/s', $src, $matches)) {
                continue;
            }

            foreach ($matches[1] as $args) {
                if (preg_match('/\{\$\w+/', $args)) {
                    $offenders[] = basename($file) . ' — it interpolates a variable into the email body instead of using a placeholder.';
                }
            }
        }

        $this->assertSame(
            [],
            array_unique($offenders),
            "Direct interpolation into an email body:\n  " . implode("\n  ", array_unique($offenders))
        );
    }
}
