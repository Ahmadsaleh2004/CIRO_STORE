<?php

/**
 * app/helpers/product_tag_helper.php
 * The product's marketing tag: best-seller | new | limited | regular.
 *
 * productTag() used to be defined as getTag() **inside**
 * app/views/product/product.php — a global function declared in a view file. Two
 * problems: it could not be called from any other view, and rendering that view twice
 * in one request would fail with "Cannot redeclare". (Phase 4 changed require_once to
 * require, which made that genuinely possible.)
 *
 * The tag itself is consumed in two places: the server-rendered product cards, and the
 * JSON array js/features/products-catalog.js reads to build the "best sellers" and "new
 * arrivals" rows.
 */

/**
 * @param array<string, mixed> $p The product row, from which it reads:
 *                sales_count · date_added · _display.stock_quantity
 *                (or stock_quantity when there is no displayed variant)
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
