<?php

namespace Tests\Unit;

use App\Core\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Validator — the validation layer that replaced 152 hand-written extraction sites
 * (88 with `$_POST[…] ??`, 38 with trim and 26 with (int)).
 *
 * It is tested with no server, no session and no network, and that is the reason it was
 * separated from the controller in the first place: while the validation logic was scattered
 * through the action bodies, checking it needed a complete HTTP request — that is, it was
 * never checked.
 */
final class ValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     */
    private function validate(array $data, array $rules): Validator
    {
        return (new Validator($data))->check($rules);
    }

    // ── required ─────────────────────────────────────────────

    public function testRequiredRejectsAMissingField(): void
    {
        $v = $this->validate([], ['name' => 'required|string']);

        $this->assertTrue($v->fails());
        $this->assertStringContainsString('required', (string) $v->firstError());
    }

    public function testRequiredRejectsAnEmptyString(): void
    {
        $this->assertTrue($this->validate(['name' => ''], ['name' => 'required|string'])->fails());
    }

    /**
     * The case that is always forgotten: a string of nothing but spaces.
     *
     * `!empty('   ')` is false in PHP, so the naive check lets it through — and a name with
     * no meaning is saved to the database.
     */
    public function testRequiredRejectsWhitespaceOnly(): void
    {
        $v = $this->validate(['name' => "   \t "], ['name' => 'required|string']);

        $this->assertTrue($v->fails(), 'A string of nothing but spaces passed as a valid value.');
    }

    public function testAnOptionalFieldMayBeAbsent(): void
    {
        $v = $this->validate([], ['nickname' => 'string']);

        $this->assertTrue($v->passes());
        $this->assertNull($v->validated()['nickname']);
    }

    // ── string ───────────────────────────────────────────────

    public function testStringTrimsBothEnds(): void
    {
        $v = $this->validate(['name' => '  Ahmad  '], ['name' => 'required|string']);

        $this->assertTrue($v->passes());
        $this->assertSame('Ahmad', $v->validated()['name']);
    }

    public function testStringRejectsAnArray(): void
    {
        $this->assertTrue($this->validate(['name' => ['a']], ['name' => 'string'])->fails());
    }

    // ── int ──────────────────────────────────────────────────

    /**
     * The most important test in the file.
     *
     * `(int)'abc'` is **zero** in PHP — with no warning and no error. So every site writing
     * `(int)$_POST['id']` turns corrupt input into a well-formed id and then queries with it.
     * The result is "item not found" instead of "invalid input" — a wrong diagnosis that
     * leads to the wrong place.
     */
    public function testIntRejectsNonNumericInsteadOfSilentlyBecomingZero(): void
    {
        $v = $this->validate(['id' => 'abc'], ['id' => 'required|int']);

        $this->assertTrue($v->fails(), "'abc' was turned into a number silently — the (int) trap has returned.");
        $this->assertArrayNotHasKey('id', $v->validated());
    }

    public function testIntAcceptsNumericStringsAndNegatives(): void
    {
        $this->assertSame(42, $this->validate(['id' => '42'], ['id' => 'int'])->validated()['id']);
        $this->assertSame(-5, $this->validate(['id' => '-5'], ['id' => 'int'])->validated()['id']);
    }

    public function testIntRejectsADecimal(): void
    {
        $this->assertTrue($this->validate(['id' => '4.5'], ['id' => 'int'])->fails());
    }

    // ── email ────────────────────────────────────────────────

    public function testEmailAcceptsAValidAddress(): void
    {
        $v = $this->validate(['email' => ' user@example.com '], ['email' => 'required|email']);

        $this->assertTrue($v->passes());
        $this->assertSame('user@example.com', $v->validated()['email']);
    }

    public function testEmailRejectsMalformedAddresses(): void
    {
        foreach (['notanemail', 'a@', '@b.com', 'a b@c.com'] as $bad) {
            $this->assertTrue(
                $this->validate(['email' => $bad], ['email' => 'email'])->fails(),
                "An invalid email address was accepted: [{$bad}]"
            );
        }
    }

    // ── min / max ────────────────────────────────────────────

    /**
     * The limit is measured in characters rather than bytes.
     *
     * The name below is four characters and eight bytes. A check with strlen would read a
     * three-character name as six — or reject a valid one, depending on which way the limit
     * points. The result is a rule that behaves in two different ways depending on the
     * user's language.
     */
    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $v = $this->validate(['name' => 'أحمد'], ['name' => 'required|string|min:4|max:4']);

        $this->assertTrue($v->passes(), 'The length was measured in bytes rather than characters.');
    }

    public function testMinRejectsTooShortText(): void
    {
        $v = $this->validate(['name' => 'ab'], ['name' => 'required|string|min:3']);

        $this->assertTrue($v->fails());
        $this->assertStringContainsString('at least 3', (string) $v->firstError());
    }

    public function testMaxRejectsTooLongText(): void
    {
        $this->assertTrue(
            $this->validate(['name' => str_repeat('x', 60)], ['name' => 'string|max:50'])->fails()
        );
    }

    /** On numbers the limit compares **the value** rather than the length — which is what a
     *  reader expects. */
    public function testMinAndMaxCompareValueForNumbers(): void
    {
        $this->assertTrue($this->validate(['qty' => '0'], ['qty' => 'int|min:1'])->fails());
        $this->assertTrue($this->validate(['qty' => '5'], ['qty' => 'int|min:1'])->passes());
        $this->assertTrue($this->validate(['qty' => '999'], ['qty' => 'int|max:100'])->fails());
    }

    // ── in ───────────────────────────────────────────────────

    public function testInAcceptsOnlyListedValues(): void
    {
        $rules = ['role' => 'required|string|in:A,B,C,D'];

        $this->assertTrue($this->validate(['role' => 'B'], $rules)->passes());
        $this->assertTrue($this->validate(['role' => 'Z'], $rules)->fails());
        // Case sensitive: the column is enum('A','B','C','D').
        $this->assertTrue($this->validate(['role' => 'b'], $rules)->fails());
    }

    // ── default ──────────────────────────────────────────────

    public function testDefaultFillsAMissingField(): void
    {
        $v = $this->validate([], ['label' => 'string|default:Home']);

        $this->assertTrue($v->passes());
        $this->assertSame('Home', $v->validated()['label']);
    }

    public function testDefaultDoesNotOverrideAProvidedValue(): void
    {
        $v = $this->validate(['label' => 'Work'], ['label' => 'string|default:Home']);

        $this->assertSame('Work', $v->validated()['label']);
    }

    // ── bool ─────────────────────────────────────────────────

    /**
     * Every HTTP input is a string, and `(bool)'false'` is **true** in PHP.
     * The same trap that caught APP_DEBUG in env_loader.
     */
    public function testBoolReadsTheStringFalseAsFalse(): void
    {
        foreach (['false', '0', 'no', 'off', ''] as $falsy) {
            $v = $this->validate(['flag' => $falsy], ['flag' => 'bool']);
            $this->assertNotTrue(
                $v->validated()['flag'] ?? null,
                "The value [{$falsy}] was read as true."
            );
        }

        foreach (['1', 'true', 'on', 'yes'] as $truthy) {
            $this->assertTrue($this->validate(['flag' => $truthy], ['flag' => 'bool'])->validated()['flag']);
        }
    }

    // ── validated() ──────────────────────────────────────────

    /**
     * What was not validated does not come out.
     *
     * Passing all of the input to the model is the door the unexpected comes in through — an
     * `is_admin` field slipped into a profile update request, for instance.
     */
    public function testValidatedReturnsOnlyCheckedFields(): void
    {
        $v = $this->validate(
            ['name' => 'Ahmad', 'is_admin' => '1', 'role' => 'A'],
            ['name' => 'required|string']
        );

        $this->assertSame(['name' => 'Ahmad'], $v->validated());
    }

    public function testFirstErrorIsNullWhenEverythingPasses(): void
    {
        $this->assertNull($this->validate(['name' => 'x'], ['name' => 'required|string'])->firstError());
    }

    public function testAllErrorsAreCollectedPerField(): void
    {
        $v = $this->validate([], ['name' => 'required|string', 'email' => 'required|email']);

        $this->assertCount(2, $v->errors());
        $this->assertArrayHasKey('name', $v->errors());
        $this->assertArrayHasKey('email', $v->errors());
    }

    public function testFieldLabelsAreReadable(): void
    {
        $v = $this->validate([], ['full_address' => 'required|string']);

        // 'full_address' does not appear raw in a message a user reads.
        $this->assertStringContainsString('Full address', (string) $v->firstError());
    }
}
