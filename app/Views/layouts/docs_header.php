<?php
/**
    * Filename: docs_header.php
    * Purpose: Provide docs header processing and supporting DevAtlas workflows.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-29
    * Recent updates: Render CodeIgniter layouts and align first-party template markup.
    */

require_once ROOTPATH . 'config/config.php';
require_once ROOTPATH . 'lib/helpers.php';
require_once ROOTPATH . 'lib/Auth.php';
Auth::RequirePage();
$token = CsrfToken();
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="csrf-token" content="<?= H($token) ?>">
        <title><?= H($pageTitle) ?> &middot; <?= H(APP_NAME) ?></title>
        <link
            rel="icon"
            type="image/svg+xml"
            href="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
        >
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/app.css')) ?>">
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/workspace.css')) ?>">
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/docs.css')) ?>">
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/help.css')) ?>">

        <?php foreach ($pageStyles ?? [] as $stylesheet): ?>
        <link rel="stylesheet" href="<?= H(AssetUrl($stylesheet)) ?>">

        <?php endforeach; ?>

        <?php echo view('layouts/ui_assets', get_defined_vars());
?>
        <script src="<?= H(AssetUrl('assets/js/app.js')) ?>"></script>
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/devatlas.css')) ?>">
    </head>
    <body class="workspace-page docs-page">
        <header class="docs-header"><a class="docs-brand" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/index.php')) ?>"><img
                    src="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
                    width="32"
                    height="32"
                    alt=""
                ><span><?= H(APP_NAME) ?><small>Help &amp; updates</small></span></a>
            <nav aria-label="Help and updates">

                <?php if (Permissions::Can('wiki')): ?>
                <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/wiki/index.php')) ?>" <?= $activeNav === 'wiki' ? 'aria-current="page"' : '' ?>>Documents Wiki</a>

                <?php endif; ?>
                <button type="button" data-updates-open>What's New</button><a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/index.php')) ?>">Back to app <?= UiIcon('arrow-right') ?></a>
            </nav>
        </header>
        <main class="docs-content">
