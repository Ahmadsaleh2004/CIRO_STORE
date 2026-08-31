<?php

namespace Tests\Integration;

use App\Models\AdminModel;
use PHPUnit\Framework\TestCase;

/**
 * The rank rule — it carries security weight.
 *
 * canManageTarget is what stops a rank C admin deleting a rank B admin or promoting
 * themselves. The map is A=4 > B=3 > C=2 > D=1, and the comparison is **strictly greater**
 * rather than "greater than or equal" — and that difference is everything: were it >=, every
 * admin could delete their peers at the same rank, including whoever created their account.
 *
 * These tests do not touch the database (the function is purely arithmetic), but they live
 * here rather than in Unit because they describe a business rule rather than a function's
 * behaviour.
 */
final class AdminRankRuleTest extends TestCase
{
    public function testAHigherRankManagesEveryLowerRank(): void
    {
        $this->assertTrue(AdminModel::canManageTarget('A', 'B'));
        $this->assertTrue(AdminModel::canManageTarget('A', 'C'));
        $this->assertTrue(AdminModel::canManageTarget('A', 'D'));
        $this->assertTrue(AdminModel::canManageTarget('B', 'C'));
        $this->assertTrue(AdminModel::canManageTarget('B', 'D'));
        $this->assertTrue(AdminModel::canManageTarget('C', 'D'));
    }

    /**
     * The case the word "strictly" in the comparison guards. Were it >=, every admin could
     * delete those at their own rank — including whoever created their account.
     */
    public function testAnEqualRankCannotManageItsPeer(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $role) {
            $this->assertFalse(
                AdminModel::canManageTarget($role, $role),
                "Rank {$role} was able to manage its own rank — the comparison has become >= instead of >."
            );
        }
    }

    public function testALowerRankNeverManagesAHigherOne(): void
    {
        $this->assertFalse(AdminModel::canManageTarget('B', 'A'));
        $this->assertFalse(AdminModel::canManageTarget('C', 'A'));
        $this->assertFalse(AdminModel::canManageTarget('D', 'A'));
        $this->assertFalse(AdminModel::canManageTarget('C', 'B'));
        $this->assertFalse(AdminModel::canManageTarget('D', 'B'));
        $this->assertFalse(AdminModel::canManageTarget('D', 'C'));
    }

    /**
     * An unknown rank equals 0, so it manages nothing — and more importantly it **is
     * managed by everyone**. That is the safe behaviour: a corrupt value in the column must
     * reduce authority rather than grant it.
     */
    public function testAnUnknownRoleHasNoAuthority(): void
    {
        foreach (['', 'X', 'a', 'ADMIN', 'null'] as $bogus) {
            $this->assertFalse(
                AdminModel::canManageTarget($bogus, 'D'),
                "An unknown rank [{$bogus}] granted authority."
            );
        }
    }

    public function testRoleComparisonIsCaseSensitive(): void
    {
        // The column is enum('A','B','C','D') — lower-case letters are not valid ranks and
        // must not be treated as though they were.
        $this->assertFalse(AdminModel::canManageTarget('a', 'D'));
        $this->assertTrue(AdminModel::canManageTarget('A', 'd'));
    }

    public function testRankValuesAreStrictlyOrdered(): void
    {
        $this->assertGreaterThan(AdminModel::getRankValue('B'), AdminModel::getRankValue('A'));
        $this->assertGreaterThan(AdminModel::getRankValue('C'), AdminModel::getRankValue('B'));
        $this->assertGreaterThan(AdminModel::getRankValue('D'), AdminModel::getRankValue('C'));
        $this->assertGreaterThan(AdminModel::getRankValue('Z'), AdminModel::getRankValue('D'));
    }
}
