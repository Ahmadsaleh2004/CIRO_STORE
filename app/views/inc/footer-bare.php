<?php
/**
 * app/views/inc/footer-bare.php
 * Closes the 'bare' layout pages. The counterpart to head-bare.php.
 *
 * It loads no JavaScript of its own — the bare pages are deliberately standalone, and
 * each declares its own scripts: through $bareScripts, or with its own tags in the body.
 *
 *   $bareScripts  string  Raw HTML printed immediately before </body>
 */
?>
<?= $bareScripts ?? '' ?>
</body>
</html>
