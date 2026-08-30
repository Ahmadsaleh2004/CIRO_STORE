<?php
/**
 * app/views/inc/head-bare.php
 * The <head> for the 'bare' layout pages — the standalone pages that load neither the
 * store navbar nor the admin one.
 *
 * This file exists because three pages used to write a full <!DOCTYPE html> and <head>
 * by hand: admin/login, admin/store-reauth and auth/reset-password. Three copies of the
 * same tags, drifting apart every time one of them was edited alone.
 *
 * The variables — all optional except $bareTitle:
 *
 *   $bareTitle      string  The page title (escaped here)
 *   $bareLang       string  The lang value on <html>          (defaults to 'en')
 *   $bareDir        string  The dir value on <html>           (defaults to 'ltr')
 *   $bareBodyClass  string  Classes for <body>                (defaults to '')
 *   $bareThemeBoot  bool    Print themeBootScript()           (defaults to false)
 *   $bareSwal       bool    Include SweetAlert2's styles      (defaults to false)
 *   $bareCss        array   CSS paths relative to URLROOT, in order
 *   $bareHead       string  Raw HTML printed at the end of the head (an extra meta tag,
 *                           or a page-specific style block)
 *
 * Every bare page so far is an authentication page, so the robots tag preventing
 * indexing is printed here unconditionally rather than as an option. The first bare page
 * that needs indexing turns it into a variable.
 */

$bareLang      = $bareLang      ?? 'en';
$bareDir       = $bareDir       ?? 'ltr';
$bareBodyClass = $bareBodyClass ?? '';
$bareThemeBoot = $bareThemeBoot ?? false;
$bareCss       = $bareCss       ?? [];

// One bare page today calls vendorJs('sweetalert2') — admin/login. And SweetAlert's
// styles are now an external stylesheet rather than an injection from JavaScript, so
// whoever includes the script must include the sheet, or the dialog appears as bare
// text. The flag exists instead of unconditional inclusion, so the error pages and the
// password reset page do not carry a stylesheet they never use.
$bareSwal      = $bareSwal      ?? false;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($bareLang) ?>" dir="<?= htmlspecialchars($bareDir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php // Prevent indexing — every bare page is private (authentication or errors) ?>
    <meta name="robots" content="noindex, nofollow">

    <title><?= htmlspecialchars($bareTitle ?? SITENAME) ?></title>
<?php if ($bareThemeBoot): ?>
<?= themeBootScript() ?>
<?php endif; ?>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<?= vendorCss('bootstrap-css') ?>
<?php if ($bareSwal): ?>
<?= vendorCss('sweetalert2-css') ?>
<?php endif; ?>
<?php foreach ($bareCss as $bareCssFile): ?>
    <link rel="stylesheet" href="<?= URLROOT ?>/<?= ltrim($bareCssFile, '/') ?>">
<?php endforeach; ?>
    <?= $bareHead ?? '' ?>
</head>
<body<?= $bareBodyClass !== '' ? ' class="' . htmlspecialchars($bareBodyClass) . '"' : '' ?>>
