<?php
/**
 * app/helpers/stock_badge_helper.php
 * دالة موحّدة لتحديد باج المخزون (Limited/Out of Stock).
 */
function getStockBadge(int $stock): ?array
{
    if ($stock === 0) {
        return ['label' => 'Out of Stock', 'class' => 'bg-danger'];
    }
    if ($stock > 0 && $stock <= 50) {
        return ['label' => "Limited ({$stock} left)", 'class' => 'bg-warning text-dark'];
    }
    return null;
}