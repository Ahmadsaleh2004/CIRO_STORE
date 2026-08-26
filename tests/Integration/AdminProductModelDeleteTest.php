<?php

namespace Tests\Integration;

use App\Models\AdminProductModel;
use Tests\Support\DatabaseTestCase;

/**
 * AdminProductModel::delete — العقد الصريح الذي حلّ محلّ `return true`
 * غير المشروط.
 *
 * العطل الأصلي: DELETE على معرّف غير موجود **ينجح** في SQL ويحذف صفر
 * صفوف. فكانت الدالة تُرجع true لمنتج لم يوجد قط، ويكتب الكنترولر صفَّ
 * تدقيق وإشعاراً عن حذف لم يحدث — سجلّ تدقيق يكذب.
 *
 * العقد الآن ثلاثي:
 *   true  → حُذف فعلاً
 *   false → لم يوجد (وصفر أثر جانبي)
 *   null  → خطأ تقني
 */
final class AdminProductModelDeleteTest extends DatabaseTestCase
{
    private function insertProduct(string $name = 'Test Product'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, stock_quantity) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, 99.99, 10]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertVariant(int $productId, string $color = 'Black'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_variants (product_id, color_name, price, stock_quantity)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$productId, $color, 99.99, 5]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testDeletingAnExistingProductReturnsTrueAndRemovesTheRow(): void
    {
        $id = $this->insertProduct();
        $this->assertSame(1, $this->countRows('products'));

        $this->assertTrue(AdminProductModel::delete($id));
        $this->assertSame(0, $this->countRows('products'));
    }

    /**
     * الحالة التي وُجد العقد من أجلها.
     */
    public function testDeletingAMissingProductReturnsFalseNotTrue(): void
    {
        $this->assertSame(0, $this->countRows('products'));

        $result = AdminProductModel::delete(999999);

        $this->assertFalse(
            $result,
            'حذف معرّف غير موجود أرجع ' . var_export($result, true)
            . ' — عودة عطل «سجلّ التدقيق الكاذب».'
        );
    }

    /**
     * الأهمّ من قيمة الإرجاع: **صفر أثر جانبي**.
     *
     * الدالة تحذف الـvariants والتصنيفات قبل أن تكتشف أن المنتج غير
     * موجود. بلا rollBack كانت ستمحو بيانات منتجات أخرى لو تصادف
     * المعرّف — وهذا ما يحرسه هذا الاختبار.
     */
    public function testAFailedDeleteLeavesOtherProductsAndVariantsUntouched(): void
    {
        $keep = $this->insertProduct('Survivor');
        $this->insertVariant($keep, 'Red');
        $this->insertVariant($keep, 'Blue');

        $this->assertFalse(AdminProductModel::delete(999999));

        $this->assertSame(1, $this->countRows('products'), 'اختفى منتج لم يُطلب حذفه.');
        $this->assertSame(2, $this->countRows('product_variants'), 'اختفت variants لمنتج آخر.');
    }

    public function testDeletingAProductAlsoRemovesItsVariants(): void
    {
        $id = $this->insertProduct();
        $this->insertVariant($id, 'Red');
        $this->insertVariant($id, 'Blue');
        $this->assertSame(2, $this->countRows('product_variants'));

        $this->assertTrue(AdminProductModel::delete($id));

        $this->assertSame(0, $this->countRows('products'));
        $this->assertSame(0, $this->countRows('product_variants'), 'بقيت variants يتيمة.');
    }

    public function testDeletingTheSameProductTwiceReturnsTrueThenFalse(): void
    {
        $id = $this->insertProduct();

        $this->assertTrue(AdminProductModel::delete($id), 'الحذف الأول فشل.');
        $this->assertFalse(
            AdminProductModel::delete($id),
            'الحذف الثاني أرجع true — أي أن ضغطتين على الزر تكتبان صفّي تدقيق لحذف واحد.'
        );
    }

    public function testDeletingOneProductDoesNotTouchAnother(): void
    {
        $doomed   = $this->insertProduct('Doomed');
        $survivor = $this->insertProduct('Survivor');
        $this->insertVariant($doomed, 'Red');
        $this->insertVariant($survivor, 'Green');

        $this->assertTrue(AdminProductModel::delete($doomed));

        $this->assertSame(1, $this->countRows('products'));
        $this->assertSame(1, $this->countRows('product_variants'));

        $remaining = $this->pdo->query('SELECT name FROM products')->fetchColumn();
        $this->assertSame('Survivor', $remaining);
    }
}
