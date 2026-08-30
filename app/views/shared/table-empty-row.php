<?php
/**
 * app/views/shared/table-empty-row.php
 * The "no records" row inside an empty admin table.
 *
 * The block used to be written into five tables: backup · manage-admins/index ·
 * orders/index · product/index · users/index. Four of them identical apart from the
 * colspan and the text, and the fifth differing in its padding.
 *
 * The variables:
 *   $emptyColspan  int     The column count (required — it differs per table)
 *   $emptyMessage  string  The text to display
 *   $emptyPadding  string  The vertical padding class: 'py-4' (the default) or 'py-5'
 *
 * ⚠️ $emptyMessage is printed as-is, unescaped: the products page passes text
 * containing the search term already escaped with htmlspecialchars. Escaping here would
 * have rendered the entities literally. It is the caller's responsibility to escape what
 * comes from the user — and the one page that does so has done it.
 *
 * A note on the colour: users/index used Bootstrap's .text-muted class while the rest
 * used the var(--muted-text) variable. I measured both in the browser in light and dark
 * mode and they gave exactly the same colour — the project redefines .text-muted to the
 * same tone — so unifying on the variable changes nothing visible.
 */

$emptyPadding = $emptyPadding ?? 'py-4';
?>
<tr>
    <td colspan="<?= (int)$emptyColspan ?>" class="text-center <?= htmlspecialchars($emptyPadding) ?> u-muted"><?= htmlspecialchars($emptyMessage ?? 'No records found.') ?></td>
</tr>
