<?php
/**
 * Filename: auth_start.php
 * Purpose: Render the recovery page header.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
$token = CsrfToken();
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="<?= H($token) ?>">
        <title><?= H($title) ?> Â· <?= H(APP_NAME) ?></title>
        <link
            rel="icon"
            type="image/svg+xml"
            href="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
        >
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/app.css')) ?>">
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/login.css')) ?>">

        <?php echo view('layouts/ui_assets', get_defined_vars());
    ?>
        <script src="<?= H(AssetUrl('assets/js/app.js')) ?>"></script>
        <script src="<?= H(AssetUrl('assets/js/login.js')) ?>" defer></script>
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/devatlas.css')) ?>">
    </head>
    <body class="login-page">
        <header class="login-header">
            <a class="login-brand" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/login.php')) ?>"><img
                    class="brand-icon"
                    src="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
                    width="42"
                    height="42"
                    alt=""
                ><span><?= H(APP_NAME) ?><small>Enterprise IT management</small></span></a>
        </header>
        <main class="auth-layout">
            <div class="auth-page-icon" aria-hidden="true"><?= UiIcon('mail') ?></div>
            <div class="login-form-heading"><span class="login-form-overline"><?= H($eyebrow) ?></span>
                <h1><?= H($title) ?></h1>
                <p><?= H($description) ?></p>
            </div>
