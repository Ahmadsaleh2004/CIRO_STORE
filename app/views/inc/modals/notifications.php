<?php
/**
 * app/views/inc/modals/notifications.php
 * Notification sidebar — a partial only, included from footer.php.
 * Shown to signed-in users alone (footer.php checks $userLoggedIn).
 * Moved out of the old components/footer.php (lines 369–383).
 */
?>
<?php // ══ Notification Sidebar ════════════════════════════════ ?>
<div id="notifSidebar" role="region" aria-label="Notifications panel">
    <div class="notif-header">
        <span>🔔 Notifications</span>
        <div class="d-flex gap-2">
            <button id="notifMarkAll" class="btn btn-sm btn-outline-theme">Mark All Read</button>
            <button id="notifClose" class="btn btn-sm btn-outline-theme" aria-label="Close">✕</button>
        </div>
    </div>
    <ul id="notifList" class="notif-list"><li class="notif-empty">Loading...</li></ul>
    <div class="p-2">
        <button id="notifDeleteAll" class="btn btn-sm btn-outline-danger w-100">🗑️ Delete All</button>
    </div>
</div>
