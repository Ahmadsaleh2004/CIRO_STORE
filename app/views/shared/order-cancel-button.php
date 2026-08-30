<?php
/**
 * app/views/shared/order-cancel-button.php
 * The cancel/delete order button — shared between the user interface (my-info.php) and
 * the admin panel (order-details.php).
 * Variables required before including it:
 *   $orderId        — the order number
 *   $orderContext   — 'user' | 'admin' (it governs the button's text and the endpoint called)
 */
$orderContext = $orderContext ?? 'user';
$endpoint     = $orderContext === 'admin'
    ? URLROOT . '/admin/orders/delete'
    : URLROOT . '/checkout/cancel-order';
?>
<button type="button"
        class="btn btn-sm btn-outline-danger order-cancel-btn"
        data-order-id="<?= (int)$orderId ?>"
        data-context="<?= $orderContext ?>"
        data-endpoint="<?= $endpoint ?>">
    🗑️ <?= $orderContext === 'admin' ? 'Delete Order' : 'Cancel Order' ?>
</button>