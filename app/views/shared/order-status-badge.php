<?php
/**
 * app/views/shared/order-status-badge.php
 * The order status badge — the colours the admin panel settled on.
 *
 * The status→colour map used to be written out in five files: four in the admin panel
 * (orders/index · orders/details · users/details · manage-admins/details)
 * and one in the store front (account/my-info).
 *
 * The variables:
 *   $orderStatus  string  The value of the orders.status column
 *   $badgeExtraClass string  Classes added to the tag: 'fs-6' for the order details
 *                           page, 'ms-2' for the customer's page
 *   $badgeLabel   string  Alternative text for the status (leave it empty for the default)
 *
 * A historical note: the customer's page used to show a cancelled order in bg-secondary
 * (grey) while the four admin pages showed it in bg-danger (red). The difference was
 * kept through phase 5 because it was a design decision rather than a refactoring, and
 * the project's owner then chose to unify on red — so cancelled is red everywhere.
 */

$badgeExtraClass = $badgeExtraClass ?? '';
$statusClass = match ($orderStatus) {
    'completed' => 'bg-success',
    'cancelled' => 'bg-danger',
    'taken'     => 'bg-primary',
    default     => 'bg-warning text-dark',
};

// The default text: not_taken → "Not taken". The order list page wrote "Not Taken" with
// a capital T; it is passed through $badgeLabel to preserve that.
$label = ($badgeLabel ?? '') !== ''
    ? $badgeLabel
    : ucfirst(str_replace('_', ' ', $orderStatus));
?>
<span class="badge <?= $statusClass ?><?= $badgeExtraClass !== '' ? ' ' . $badgeExtraClass : '' ?>"><?= htmlspecialchars($label) ?></span>
