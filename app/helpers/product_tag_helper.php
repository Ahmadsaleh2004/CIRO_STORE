<?php

/**
 * app/helpers/product_tag_helper.php
 * وسم المنتج التسويقي: best-seller | new | limited | regular.
 *
 * كانت productTag() معرَّفة باسم getTag() **داخل** app/views/product/product.php
 * — دالة عامة تُعرَّف في ملف عرض. مشكلتان: لا يمكن استدعاؤها من أي view
 * آخر، ولو عُرض ذلك الـview مرتين في طلب واحد لسقط بـ«Cannot redeclare».
 * (المرحلة 4 حوّلت require_once إلى require فصار ذلك ممكناً فعلاً.)
 *
 * الوسم نفسه يُستهلك في مكانين: بطاقات المنتجات المبنية على الخادم،
 * ومصفوفة JSON التي يقرأها js/features/products-catalog.js لبناء
 * أشرطة «الأكثر مبيعاً» و«الجديد».
 */

/**
 * @param array $p صفّ المنتج، ويُقرأ منه:
 *                sales_count · date_added · _display.stock_quantity
 *                (أو stock_quantity عند غياب المتغيّر المعروض)
 */
function productTag(array $p): string
{
    if (($p['sales_count'] ?? 0) >= 5) {
        return 'best-seller';
    }

    $days = (time() - strtotime($p['date_added'] ?? 'now')) / 86400;
    if ($days <= 60) {
        return 'new';
    }

    $displayStock = (int)($p['_display']['stock_quantity'] ?? $p['stock_quantity'] ?? 0);
    if ($displayStock > 0 && $displayStock <= 50) {
        return 'limited';
    }

    return 'regular';
}
