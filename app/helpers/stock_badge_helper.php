<?php

/**
 * app/helpers/stock_badge_helper.php
 * A single function deciding the stock badge (Limited / Out of Stock).
 */
/**
 * @param bool $showInStock Return a green badge when stock is plentiful?
 *
 * The product list page does not want one — a badge on every card is visual noise. The
 * product details page does, and it used to **rewrite the whole mapping** as an
 * if/elseif chain for the sake of that third branch alone. The parameter unifies the two
 * versions without either losing its behaviour.
 *
 * A note on the bounds: both products.stock_quantity and
 * product_variants.stock_quantity are int unsigned, so a negative value is impossible
 * and there is no branch for one here.
 *
 * ⚠️ **This function has a mirror in JavaScript**: stockBadge() in js/core/utils.js,
 * serving the cards the browser builds (the wishlist and the product details). The
 * threshold of 50, the labels and the classes are duplicated across the two languages
 * deliberately — there is no way around it in a project with no build step to share the
 * constants. **If you change something here, change it there as well.**
 * @return array<string, mixed>
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
