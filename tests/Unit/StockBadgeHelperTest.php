<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * getStockBadge — which has **a mirror in JS** (stockBadge in js/core/utils.js).
 * The threshold of 50 and the wording are duplicated across the two languages deliberately,
 * in a project with no build step.
 *
 * The value of these tests is that they freeze the PHP side: any change to the threshold or
 * the wording fails them, and whoever is editing is reminded that the other copy needs the
 * same change — which is exactly the commitment the file documented and nobody enforced.
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
        // Being out of stock outranks everything — a display flag must not override it.
        $this->assertSame('Out of Stock', getStockBadge(0, true)['label']);
    }

    public function testLowStockShowsTheRemainingCount(): void
    {
        $badge = getStockBadge(7);

        $this->assertSame('Limited (7 left)', $badge['label']);
        $this->assertSame('bg-warning text-dark', $badge['class']);
    }

    /** The two sides of the threshold precisely — 50 is limited and 51 is plentiful. */
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
     * The product listing does not want a green badge on every card — that is visual noise.
     * The null default is what achieves that.
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
