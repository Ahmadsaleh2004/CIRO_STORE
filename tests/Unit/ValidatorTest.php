<?php

namespace Tests\Unit;

use App\Core\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Validator — طبقة التحقّق التي حلّت محلّ 152 موضع استخراج يدوي
 * (88 بـ`$_POST[…] ??` و38 بـtrim و26 بـ(int)).
 *
 * تُختبَر بلا خادم ولا جلسة ولا شبكة، وهذا هو سبب فصلها عن الكنترولر
 * أصلاً: منطق التحقّق حين كان مبعثراً في أجسام الأفعال كان يحتاج طلب
 * HTTP كاملاً ليُفحص — أي لم يكن يُفحص.
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
     * الحالة التي تُنسى دائماً: نصّ من مسافات وحدها.
     *
     * `!empty('   ')` تساوي false في PHP، فالفحص الساذج يمرّرها —
     * ويُحفظ في القاعدة اسمٌ فارغ المعنى.
     */
    public function testRequiredRejectsWhitespaceOnly(): void
    {
        $v = $this->validate(['name' => "   \t "], ['name' => 'required|string']);

        $this->assertTrue($v->fails(), 'نصّ من مسافات وحدها مرّ كقيمة صالحة.');
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
        $v = $this->validate(['name' => '  أحمد  '], ['name' => 'required|string']);

        $this->assertTrue($v->passes());
        $this->assertSame('أحمد', $v->validated()['name']);
    }

    public function testStringRejectsAnArray(): void
    {
        $this->assertTrue($this->validate(['name' => ['a']], ['name' => 'string'])->fails());
    }

    // ── int ──────────────────────────────────────────────────

    /**
     * أخطر اختبار في الملف.
     *
     * `(int)'abc'` تساوي **صفراً** في PHP — بلا تحذير ولا خطأ. فكل
     * موضع يكتب `(int)$_POST['id']` يحوّل مدخلاً تالفاً إلى معرّف صالح
     * الشكل، ثم يُستعلم به. والنتيجة «لم يُعثر على العنصر» بدل «مدخل
     * غير صالح» — تشخيص خاطئ يقود إلى المكان الخطأ.
     */
    public function testIntRejectsNonNumericInsteadOfSilentlyBecomingZero(): void
    {
        $v = $this->validate(['id' => 'abc'], ['id' => 'required|int']);

        $this->assertTrue($v->fails(), "'abc' تحوّلت إلى رقم بصمت — عودة فخّ (int).");
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
                "قُبل بريد غير صالح: [{$bad}]"
            );
        }
    }

    // ── min / max ────────────────────────────────────────────

    /**
     * الحدّ يُقاس بالمحارف لا بالبايتات.
     *
     * «أحمد» خمسة محارف وثمانية بايتات. فحص بـstrlen يقبل اسماً من
     * ثلاثة محارف عربية على أنه ستّة — أو يرفض اسماً صالحاً حسب اتجاه
     * الحدّ. والنتيجة قاعدة تتصرّف تصرّفين حسب لغة المستخدم.
     */
    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $v = $this->validate(['name' => 'أحمد'], ['name' => 'required|string|min:4|max:4']);

        $this->assertTrue($v->passes(), 'الطول قيس بالبايتات لا بالمحارف.');
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

    /** على الأرقام يقارن الحدّ **القيمة** لا الطول — وهذا ما يتوقّعه القارئ. */
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
        // حسّاسة لحالة الأحرف: العمود enum('A','B','C','D').
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
     * كل مدخلات HTTP نصوص، و`(bool)'false'` تساوي **true** في PHP.
     * نفس الفخّ الذي أوقع APP_DEBUG في env_loader.
     */
    public function testBoolReadsTheStringFalseAsFalse(): void
    {
        foreach (['false', '0', 'no', 'off', ''] as $falsy) {
            $v = $this->validate(['flag' => $falsy], ['flag' => 'bool']);
            $this->assertNotTrue(
                $v->validated()['flag'] ?? null,
                "القيمة [{$falsy}] قُرئت true."
            );
        }

        foreach (['1', 'true', 'on', 'yes'] as $truthy) {
            $this->assertTrue($this->validate(['flag' => $truthy], ['flag' => 'bool'])->validated()['flag']);
        }
    }

    // ── validated() ──────────────────────────────────────────

    /**
     * ما لم يُفحص لا يخرج.
     *
     * تمرير كل المدخلات إلى المودل هو الباب الذي يدخل منه ما لم
     * يُتوقّع — حقل `is_admin` مدسوس في طلب تحديث ملف شخصي مثلاً.
     */
    public function testValidatedReturnsOnlyCheckedFields(): void
    {
        $v = $this->validate(
            ['name' => 'أحمد', 'is_admin' => '1', 'role' => 'A'],
            ['name' => 'required|string']
        );

        $this->assertSame(['name' => 'أحمد'], $v->validated());
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

        // 'full_address' لا يظهر خاماً في رسالة يقرأها مستخدم.
        $this->assertStringContainsString('Full address', (string) $v->firstError());
    }
}
