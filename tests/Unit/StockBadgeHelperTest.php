<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * getStockBadge — ولها **مرآة في JS** (stockBadge في js/core/utils.js).
 * العتبة 50 والنصوص مكرّرة بين اللغتين عمداً في مشروع بلا خطوة بناء.
 *
 * قيمة هذه الاختبارات أنها تجمّد الطرف الـPHP: أي تغيير في العتبة أو
 * الصياغة يُسقطها، فيُذكَّر المعدِّل بأن النسخة الأخرى تحتاج التغيير
 * نفسه — وهو بالضبط الالتزام الذي وثّقه الملف ولم يكن أحد يفرضه.
 */
final class StockBadgeHelperTest extends TestCase
{
    public function testZeroStockIsOutOfStock(): void
    {
        $badge = getStockBadge(0);

        $this->assertSame('Out of Stock', $badge['label']);
        $this->assertSame('bg-danger', $badge['class']);
    }

    public function testZeroStockIsOutOfStockRegardlessOfTheShowInStockFlag(): void
    {
        // النفاد يسبق كل شيء — لا يجوز أن يقلبه وسيط عرض.
        $this->assertSame('Out of Stock', getStockBadge(0, true)['label']);
    }

    public function testLowStockShowsTheRemainingCount(): void
    {
        $badge = getStockBadge(7);

        $this->assertSame('Limited (7 left)', $badge['label']);
        $this->assertSame('bg-warning text-dark', $badge['class']);
    }

    /** حدّا العتبة تحديداً — 50 محدود و51 وفير. */
    public function testTheThresholdBoundaryIsFifty(): void
    {
        $this->assertSame('Limited (50 left)', getStockBadge(50)['label']);
        $this->assertNull(getStockBadge(51));
        $this->assertSame('In Stock (51)', getStockBadge(51, true)['label']);
    }

    public function testStockOfOneIsStillLimited(): void
    {
        $this->assertSame('Limited (1 left)', getStockBadge(1)['label']);
    }

    /**
     * قائمة المنتجات لا تريد بادجاً أخضر على كل بطاقة — ضجيج بصري.
     * الافتراضي null هو ما يحقّق ذلك.
     */
    public function testPlentifulStockYieldsNoBadgeByDefault(): void
    {
        $this->assertNull(getStockBadge(500));
    }

    public function testPlentifulStockYieldsAGreenBadgeWhenAsked(): void
    {
        $badge = getStockBadge(500, true);

        $this->assertSame('In Stock (500)', $badge['label']);
        $this->assertSame('bg-success', $badge['class']);
    }
}
