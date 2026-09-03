<?php
/**
 * app/views/admin/inc/_nav-links.php — the admin navigation, defined once.
 *
 * It is rendered in two places: the horizontal <ul> inside the navbar, which is what a
 * desktop sees, and the offcanvas sidebar, which is what everything below 1200px sees.
 * Those two used to be one list only because the sidebar did not exist; the moment it did,
 * the choice was between a second copy of nine links with nine permission checks, or this.
 * A copy would have drifted the first time a page was added to one and not the other.
 *
 * Returned to whoever requires it. `$newMessages` and `$newOrders` come from the enclosing
 * scope — AdminController::adminView() puts them there for the navbar, and require() runs
 * in the caller's scope, so they are in hand here.
 *
 * Each entry:
 *   href    string       Absolute, already prefixed with URLROOT.
 *   label   string       The visible text, emoji included.
 *   class   string       Extra classes for the link itself (empty for most).
 *   badge   int          A count to show as a corner badge; 0 prints nothing.
 *   form    bool         True for Store mode, which is a POST with a CSRF token rather
 *                        than a link, and so is rendered as a button in both places.
 *
 * @var int $newMessages
 * @var int $newOrders
 *
 * @return list<array{href: string, label: string, class: string, badge: int, form: bool}>
 */

$links = [];

if (hasPermission('can_manage_admins')) {
    $links[] = [
        'href'  => URLROOT . '/admin/admins',
        'label' => '👑 Admins',
        'class' => 'text-warning fw-semibold',
        'badge' => 0,
        'form'  => false,
    ];
}

if (hasPermission('can_view_dashboard')) {
    $links[] = [
        'href'  => URLROOT . '/admin/dashboard',
        'label' => '📊 Dashboard',
        'class' => '',
        'badge' => 0,
        'form'  => false,
    ];
}

if (hasPermission('can_manage_products')) {
    $links[] = [
        'href'  => URLROOT . '/admin/products',
        'label' => '🛍️ Products',
        'class' => '',
        'badge' => 0,
        'form'  => false,
    ];
}

if (hasPermission('can_manage_users')) {
    $links[] = [
        'href'  => URLROOT . '/admin/users',
        'label' => '👥 Users',
        'class' => '',
        'badge' => 0,
        'form'  => false,
    ];
}

if (hasPermission('can_manage_support')) {
    $links[] = [
        'href'  => URLROOT . '/admin/support',
        'label' => '💬 Support',
        'class' => '',
        'badge' => (int) ($newMessages ?? 0),
        'form'  => false,
    ];
}

if (hasPermission('can_manage_orders')) {
    $links[] = [
        'href'  => URLROOT . '/admin/orders',
        'label' => '📦 Orders',
        'class' => '',
        'badge' => (int) ($newOrders ?? 0),
        'form'  => false,
    ];
}

if (hasPermission('can_edit_site_content')) {
    $links[] = [
        'href'  => URLROOT . '/admin/settings',
        'label' => '⚙️ Site Configuration',
        'class' => '',
        'badge' => 0,
        'form'  => false,
    ];
}

if (hasPermission('can_manage_branding')) {
    $links[] = [
        'href'  => URLROOT . '/admin/branding',
        'label' => '🎬 Slider',
        'class' => '',
        'badge' => 0,
        'form'  => false,
    ];
}

// Entering store-browsing mode as a visitor — a POST with CSRF, open to every admin.
$links[] = [
    'href'  => URLROOT . '/admin/store-mode/enter',
    'label' => '🌐 Store',
    'class' => '',
    'badge' => 0,
    'form'  => true,
];

return $links;
