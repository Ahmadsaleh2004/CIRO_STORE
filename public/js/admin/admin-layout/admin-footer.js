document.addEventListener('DOMContentLoaded', function () {
    var sidebar      = document.getElementById('adminNotifSidebar');
    var closeBtn     = document.getElementById('adminNotifClose');
    var markAllBtn   = document.getElementById('adminNotifMarkAll');
    var deleteAllBtn = document.getElementById('adminNotifDeleteAll');

    if (closeBtn && sidebar) {
        closeBtn.addEventListener('click', function () {
            sidebar.classList.remove('open');
        });
    }

    if (markAllBtn) {
        markAllBtn.addEventListener('click', function () {
            // TODO: استدعاء API لتعليم الكل كمقروء — تذكرة منفصلة
            console.log('TODO: mark all notifications as read');
        });
    }

    if (deleteAllBtn) {
        deleteAllBtn.addEventListener('click', function () {
            // TODO: استدعاء API لحذف كل الإشعارات — تذكرة منفصلة
            console.log('TODO: delete all notifications');
        });
    }
});
