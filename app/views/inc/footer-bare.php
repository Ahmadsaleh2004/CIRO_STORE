<?php
/**
 * app/views/inc/footer-bare.php
 * إغلاق صفحات layout الـ'bare'. مقابل head-bare.php.
 *
 * لا يحمّل أي JS من تلقاء نفسه — صفحات الـbare مستقلة عمداً، وكل صفحة
 * تعلن سكربتاتها بنفسها: عبر $bareScripts أو بوسومها الخاصة في الجسم.
 *
 *   $bareScripts  string  HTML خام يُطبع قبل </body> مباشرة
 */
?>
<?= $bareScripts ?? '' ?>
</body>
</html>
