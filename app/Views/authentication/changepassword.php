<?php
/**
 * Filename: changepassword.php
 * Purpose: Render the Authentication workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="<?= H($token) ?>">
        <title>Change Password · <?= H(APP_NAME) ?></title>
        <link
            rel="icon"
            type="image/svg+xml"
            href="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
        >
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/app.css')) ?>">

        <?php echo view('layouts/ui_assets', get_defined_vars());
?>
        <script src="<?= H(AssetUrl('assets/js/app.js')) ?>"></script>
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/devatlas.css')) ?>">
    </head>
    <body class="layout-d4b8c4fc">

        <div class="card layout-b582d723">
            <div class="layout-11805da5">
                <img
                    src="<?= H(AssetUrl('assets/brand/devatlas-logo.svg')) ?>"
                    width="260"
                    height="74"
                    alt="<?= H(APP_NAME) ?>"
                    class="brand-lockup-light"
                >
                <img
                    src="<?= H(AssetUrl('assets/brand/devatlas-logo-dark.svg')) ?>"
                    width="260"
                    height="74"
                    alt="<?= H(APP_NAME) ?>"
                    class="brand-lockup-dark"
                >
                <div class="text-sm muted">Change Password</div>
            </div>

            <?php if ($isSuccess): ?>
            <div class="text-sm layout-2e628134">
                Password changed successfully.
            </div>
            <a class="btn btn-primary w-full layout-3dc9c801" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/index.php')) ?>">Continue to Dashboard <?= UiIcon('arrow-right') ?></a>

            <?php else: ?>

            <?php if ($forced): ?>
            <div class="text-sm layout-e82ab5d8">
                You must change your password before continuing. Enter and confirm your new password below.
            </div>

            <?php endif; ?>

            <?php if ($error): ?>
            <div class="text-sm layout-18f0c06d">
                <?= H($error) ?>
            </div>

            <?php endif; ?>

            <form method="POST">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= H($token) ?>"
                >
                <?php if (!$forced): ?>
                <div class="form-row">
                    <label for="currentPassword">Current Password</label>
                    <input
                        id="currentPassword"
                        type="password"
                        name="current_password"
                        required
                        autocomplete="current-password"
                        autofocus
                    >
                </div>
                <?php endif; ?>
                <div class="form-row">
                    <label for="newPassword">New Password</label>
                    <input
                        id="newPassword"
                        type="password"
                        name="new_password"
                        minlength="8"
                        required
                        autocomplete="new-password"
                        placeholder="min. 8 characters"
                        <?= $forced ? 'autofocus' : '' ?>
                    >
                </div>
                <div class="form-row">
                    <label for="confirmPassword">Confirm New Password</label>
                    <input
                        id="confirmPassword"
                        type="password"
                        name="confirm_password"
                        minlength="8"
                        required
                        autocomplete="new-password"
                    >
                </div>
                <button type="submit" class="btn btn-primary w-full layout-97f3fd96">Change Password</button>
            </form>

            <?php if (!$forced): ?>
            <div class="layout-f8774ad5">
                <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/index.php')) ?>" class="text-sm muted">Cancel</a>
            </div>

            <?php endif; ?>

            <?php endif; ?>
            <div class="auth-appearance">

                <?php echo view('layouts/theme_control', get_defined_vars());
?>
            </div>
        </div>
    </body>
</html>

