<?php

namespace Tests\Integration;

use App\Models\AdminModel;
use PHPUnit\Framework\TestCase;

/**
 * قاعدة الرتب — حمّالة أمان.
 *
 * canManageTarget هي ما يمنع أدمن رتبة C من حذف أدمن رتبة B أو من
 * ترقية نفسه. الخريطة A=4 > B=3 > C=2 > D=1، والمقارنة **أكبر تماماً**
 * لا «أكبر أو يساوي» — وهذا الفرق هو كل شيء: لو صارت >= لاستطاع كل
 * أدمن أن يحذف أقرانه في رتبته، ومنهم من أضافه.
 *
 * لا تلمس هذه الاختبارات القاعدة (الدالة حسابية بحتة)، لكنها هنا لا في
 * Unit لأنها تصف قاعدة عمل لا سلوك دالة.
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
     * الحالة التي تحرسها كلمة «تماماً» في المقارنة. لو كانت >= لكان كل
     * أدمن قادراً على حذف من هم في رتبته — بمن فيهم من أنشأ حسابه.
     */
    public function testAnEqualRankCannotManageItsPeer(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $role) {
            $this->assertFalse(
                AdminModel::canManageTarget($role, $role),
                "رتبة {$role} استطاعت إدارة رتبتها — المقارنة صارت >= بدل >."
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
     * رتبة مجهولة تساوي 0، فلا تدير شيئاً — والأهمّ أنها **تُدار من
     * الجميع**. هذا هو السلوك الآمن: قيمة تالفة في العمود يجب أن تُنقص
     * الصلاحية لا أن تمنحها.
     */
    public function testAnUnknownRoleHasNoAuthority(): void
    {
        foreach (['', 'X', 'a', 'ADMIN', 'null'] as $bogus) {
            $this->assertFalse(
                AdminModel::canManageTarget($bogus, 'D'),
                "رتبة مجهولة [{$bogus}] منحت سلطة."
            );
        }
    }

    public function testRoleComparisonIsCaseSensitive(): void
    {
        // العمود enum('A','B','C','D') — الحروف الصغيرة ليست رتباً صالحة
        // ويجب ألّا تُعامَل كأنها كذلك.
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
