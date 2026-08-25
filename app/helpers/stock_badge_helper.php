<?php
/**
 * app/helpers/stock_badge_helper.php
 * دالة موحّدة لتحديد باج المخزون (Limited/Out of Stock).
 */
/**
 * @param bool $showInStock هل نُرجع بادجاً أخضر عند توفّر مخزون وفير؟
 *
 * صفحة قائمة المنتجات لا تريده — بادج على كل بطاقة ضجيج بصري. صفحة
 * تفاصيل المنتج تريده، وكانت **تعيد كتابة الخريطة كلها** بـif/elseif
 * من أجل هذا الفرع الثالث وحده. الوسيط يجمع النسختين بلا أن يُفقد أياً
 * منهما سلوكها.
 *
 * ملاحظة على الحدود: العمودان products.stock_quantity و
 * product_variants.stock_quantity كلاهما int unsigned، فالقيمة السالبة
 * غير ممكنة ولا فرع لها هنا.
 */
function getStockBadge(int $stock, bool $showInStock = false): ?array
{
    if ($stock === 0) {
        return ['label' => 'Out of Stock', 'class' => 'bg-danger'];
    }
    if ($stock > 0 && $stock <= 50) {
        return ['label' => "Limited ({$stock} left)", 'class' => 'bg-warning text-dark'];
    }
    if ($showInStock) {
        return ['label' => "In Stock ({$stock})", 'class' => 'bg-success'];
    }
    return null;
}