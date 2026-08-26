<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * env() و envBool() — تحرسان القرار الذي يقرّر هل تُعرض أخطاء PHP
 * للزائر أم لا. عطل هنا يعني تسريب مسارات خادم وأسماء قواعد.
 *
 * الدالتان كانتا موضع عطلين حقيقيين أُصلحا في المرحلة 1، وهذه
 * الاختبارات هي ما يمنع عودتهما.
 */
final class EnvLoaderTest extends TestCase
{
    private array $backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->backup;
        parent::tearDown();
    }

    public function testEnvReturnsTheValueWhenSet(): void
    {
        $_ENV['SOME_KEY'] = 'some-value';
        $this->assertSame('some-value', env('SOME_KEY'));
    }

    public function testEnvReturnsTheDefaultWhenKeyIsMissing(): void
    {
        unset($_ENV['MISSING_KEY']);
        $this->assertSame('fallback', env('MISSING_KEY', 'fallback'));
    }

    /**
     * العطل الأصلي: `$_ENV[$k] ?? getenv($k) ?: $default` تُقرأ
     * `$_ENV[$k] ?? (getenv($k) ?: $default)` — فمفتاح موجود بقيمة
     * فارغة يُرجع "" ويتخطّى الافتراضي تماماً.
     *
     * الأثر العملي: سطر `APP_ENV=` في .env (نسخة من .env.example لم
     * تُملأ) كان يعطي "" لا 'production'، فيُفتح وضع التنقيح على خادم
     * إنتاج بصمت.
     */
    public function testEnvTreatsAnEmptyValueAsAbsent(): void
    {
        $_ENV['BLANK_KEY'] = '';

        $this->assertSame(
            'production',
            env('BLANK_KEY', 'production'),
            'مفتاح فارغ تخطّى القيمة الافتراضية — عودة عطل الأسبقية.'
        );
    }

    public function testEnvReturnsNullWhenMissingAndNoDefaultGiven(): void
    {
        unset($_ENV['NOTHING_HERE']);
        $this->assertNull(env('NOTHING_HERE'));
    }

    /**
     * العطل الثاني: كل قيم .env نصوص، و(bool)"false" تساوي **true**
     * في PHP. فـ`APP_DEBUG=false` كان سيفتح وضع التنقيح لا يغلقه —
     * أخطر نوع من الأعطال لأنه يعمل عكس ما يقرأه القارئ.
     */
    public function testEnvBoolReadsTheStringFalseAsFalse(): void
    {
        foreach (['false', 'FALSE', 'False', '0', 'no', 'off', 'anything'] as $value) {
            $_ENV['FLAG'] = $value;
            $this->assertFalse(
                envBool('FLAG', true),
                "القيمة [{$value}] قُرئت true — عودة فخّ (bool)\"false\"."
            );
        }
    }

    public function testEnvBoolAcceptsTheTruthyForms(): void
    {
        foreach (['1', 'true', 'TRUE', 'yes', 'on', '  true  '] as $value) {
            $_ENV['FLAG'] = $value;
            $this->assertTrue(envBool('FLAG', false), "القيمة [{$value}] لم تُقرأ true.");
        }
    }

    public function testEnvBoolFallsBackToTheDefaultWhenAbsentOrBlank(): void
    {
        unset($_ENV['FLAG']);
        $this->assertTrue(envBool('FLAG', true));
        $this->assertFalse(envBool('FLAG', false));

        $_ENV['FLAG'] = '';
        $this->assertTrue(envBool('FLAG', true));
    }
}
