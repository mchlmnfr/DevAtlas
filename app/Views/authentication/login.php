<?php
/**
 * Filename: login.php
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
        <title>Sign In · <?= H(APP_NAME) ?></title>
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
            <a
                class="login-brand"
                href="<?= H(RouteUrls::Url(APP_BASE_URL . '/login.php')) ?>"
                aria-label="<?= H(APP_NAME) ?> sign in"
            ><img
                    class="brand-icon"
                    src="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
                    width="42"
                    height="42"
                    alt=""
                ><span><?= H(APP_NAME) ?><small>Enterprise IT management</small></span></a>
            <nav class="login-public-resources" aria-label="Public resources">
                <a class="login-public-directory" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/directory/index.php')) ?>"><?= UiIcon('contact') ?><span>Call Directory</span><?= UiIcon('arrow-up-right') ?></a>
                <a class="login-public-directory login-public-links" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/bookmarks/index.php')) ?>"><?= UiIcon('external-link') ?><span>Quick Links</span><?= UiIcon('arrow-up-right') ?></a>
            </nav>
        </header>
        <main class="login-layout">
            <section class="login-story" aria-labelledby="loginStoryTitle">
                <div class="login-story-copy"><span class="login-eyebrow"><span></span> THE DEVATLAS WORKSPACE</span>
                    <h2 id="loginStoryTitle">Your systems.<br><em>One clear view.</em></h2>
                    <p>Bring your documentation, infrastructure and team operations together. Everything your department needs to move work forward.</p>
                </div>
                <div class="atlas-story-workspace" aria-hidden="true">
                    <div class="atlas-story-workspace-head"><span><?= UiIcon('compass') ?> DevAtlas</span><small>CONNECTED WORKSPACE</small></div>
                    <div class="atlas-story-grid">
                        <div><span class="atlas-story-icon"><?= UiIcon('database') ?></span><strong>Documentation</strong><small>Data dictionaries &amp; diagrams</small></div>
                        <div><span class="atlas-story-icon cyan"><?= UiIcon('server') ?></span><strong>Infrastructure</strong><small>Servers, systems &amp; inventory</small></div>
                        <div><span class="atlas-story-icon cyan"><?= UiIcon('code-xml') ?></span><strong>Code review</strong><small>Standards, findings &amp; follow-up</small></div>
                        <div><span class="atlas-story-icon"><?= UiIcon('users') ?></span><strong>Team operations</strong><small>Daily logs, approvals &amp; reports</small></div>
                    </div>
                    <div class="atlas-story-connected"><i></i> Documentation. Systems. People. Connected.</div>
                </div>
                <div class="atlas-story-footer"><?= UiIcon('shield-check') ?><span>Role-based access. Accountable workflows.</span></div>
            </section>
            <section class="login-form-section" aria-labelledby="loginTitle">
                <div class="login-form-wrap">
                    <div class="login-form-heading"><span class="login-form-overline">GOOD TO SEE YOU AGAIN</span>
                        <h1 id="loginTitle">Welcome back.</h1>
                        <p>Sign in to your <?= H(APP_NAME) ?> workspace.</p>
                    </div>
                    <nav class="login-methods" aria-label="Sign-in method"><a href="<?= H($loginUrl . '?mode=password' . $redirectQuery) ?>" <?= $mode === 'password' ? 'aria-current="page"' : '' ?>>Password</a><a href="<?= H($loginUrl . '?mode=code' . $redirectQuery) ?>" <?= $mode === 'code' ? 'aria-current="page"' : '' ?>>Email code</a></nav>

                    <?php if ($error): ?>
                    <div
                        id="loginError"
                        class="login-error"
                        role="alert"
                        tabindex="-1"
                    ><strong>We couldn’t sign you in</strong><span><?= H($error) ?></span></div>

                    <?php endif; ?>

                    <?php if ($mode === 'code'): ?>

                    <?php if ($message): ?>
                    <div class="auth-notice" role="status"><?= H($message) ?></div>

                    <?php endif; ?>

                    <?php if ($codeState): ?>
                    <p class="auth-instructions">Enter the <strong>6-digit code</strong> from your latest email. It is valid for 10 minutes, for one use, in this browser.</p>
                    <form method="POST" data-auth-form>
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= H($token) ?>"
                        ><input
                            type="hidden"
                            name="mode"
                            value="code"
                        ><input
                            type="hidden"
                            name="action"
                            value="verify_code"
                        ><input
                            type="hidden"
                            name="redirect"
                            value="<?= H($redirectTarget) ?>"
                        >
                        <div class="form-row"><label for="emailCode">One-time code</label><input
                                id="emailCode"
                                class="auth-code-input"
                                type="text"
                                name="code"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                pattern="[0-9]{6}"
                                minlength="6"
                                maxlength="6"
                                placeholder="000000"
                                required
                                autofocus
                            ></div>
                        <button
                            class="btn btn-primary login-submit"
                            type="submit"
                            data-busy-label="Verifying…"
                        >Verify &amp; sign in<span class="login-spinner" aria-hidden="true"></span></button>
                    </form>
                    <div class="auth-resend-row">
                        <form method="POST" data-auth-form><input
                                type="hidden"
                                name="csrf_token"
                                value="<?= H($token) ?>"
                            ><input
                                type="hidden"
                                name="mode"
                                value="code"
                            ><input
                                type="hidden"
                                name="action"
                                value="request_code"
                            ><input
                                type="hidden"
                                name="redirect"
                                value="<?= H($redirectTarget) ?>"
                            ><input
                                type="hidden"
                                name="identifier"
                                value="<?= H($identifier) ?>"
                            ><button
                                class="auth-text-button"
                                type="submit"
                                data-cooldown="<?= max(0, (int)$codeState['retry_at'] - time()) ?>"
                            >Resend code</button></form>
                        <form method="POST"><input
                                type="hidden"
                                name="csrf_token"
                                value="<?= H($token) ?>"
                            ><input
                                type="hidden"
                                name="mode"
                                value="code"
                            ><input
                                type="hidden"
                                name="action"
                                value="change_account"
                            ><input
                                type="hidden"
                                name="redirect"
                                value="<?= H($redirectTarget) ?>"
                            ><button class="auth-text-button" type="submit">Use another account</button></form>
                    </div>

                    <?php else: ?>
                    <p class="auth-instructions">We’ll email a one-time code to the address on your account.</p>
                    <form method="POST" data-auth-form><input
                            type="hidden"
                            name="csrf_token"
                            value="<?= H($token) ?>"
                        ><input
                            type="hidden"
                            name="mode"
                            value="code"
                        ><input
                            type="hidden"
                            name="action"
                            value="request_code"
                        ><input
                            type="hidden"
                            name="redirect"
                            value="<?= H($redirectTarget) ?>"
                        >
                        <div class="form-row"><label for="codeIdentifier">Employee ID or registered email</label><input
                                id="codeIdentifier"
                                name="identifier"
                                type="text"
                                value="<?= H(mb_substr($identifier, 0, 200)) ?>"
                                maxlength="200"
                                autocomplete="username"
                                autocapitalize="none"
                                spellcheck="false"
                                required
                                autofocus
                                placeholder="Enter your ID or email"
                            ></div>
                        <button
                            class="btn btn-primary login-submit"
                            type="submit"
                            data-busy-label="Preparing email…"
                        >Email me a sign-in code<span class="login-spinner" aria-hidden="true"></span></button>
                    </form>

                    <?php endif; ?>

                    <?php else: ?>
                    <form method="POST" id="loginForm">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= H($token) ?>"
                        >
                        <input
                            type="hidden"
                            name="redirect"
                            value="<?= H($redirectTarget) ?>"
                        >
                        <div class="form-row">
                            <label for="loginEmployeeId">Employee ID</label>
                            <div class="login-input-wrap"><?= UiIcon('user-round') ?><input
                                    id="loginEmployeeId"
                                    type="text"
                                    name="employee_id"
                                    value="<?= H(mb_substr($employeeId, 0, 50)) ?>"
                                    maxlength="50"
                                    <?= $error ? '' : 'autofocus' ?>
                                    required
                                    autocomplete="username"
                                    placeholder="Enter your employee ID"
                                    autocapitalize="none"
                                    spellcheck="false"
                                ></div>
                        </div>
                        <div class="form-row">
                            <label for="loginPassword">Password</label>
                            <div class="login-input-wrap login-password-wrap"><?= UiIcon('lock-keyhole') ?><input
                                    id="loginPassword"
                                    type="password"
                                    name="password"
                                    required
                                    autocomplete="current-password"
                                    placeholder="Enter your password"
                                    aria-describedby="loginCapsLock"
                                ><button
                                    id="loginPasswordToggle"
                                    type="button"
                                    aria-label="Show password"
                                    aria-pressed="false"
                                >Show</button></div>
                            <p
                                id="loginCapsLock"
                                class="login-caps-lock"
                                hidden
                            >Caps Lock is on.</p>
                        </div>
                        <div class="auth-forgot"><a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/forgot_password.php')) ?>">Forgot password?</a></div>
                        <button
                            type="submit"
                            class="btn btn-primary login-submit"
                            id="loginSubmit"
                        ><span id="loginSubmitLabel">Sign in</span><?= UiIcon('arrow-right') ?><span class="login-spinner" aria-hidden="true"></span></button>
                    </form>

                    <?php endif; ?>
                    <p class="login-access-note">Need an account or help signing in?<br><strong>Contact your system administrator.</strong></p>
                </div>
            </section>
        </main>
        <footer class="login-footer"><span><?= H(APP_NAME) ?> · Enterprise IT management</span>

            <?php echo view('layouts/theme_control', get_defined_vars());
?>
        </footer>
    </body>
</html>
