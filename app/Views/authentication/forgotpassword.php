<?php
/**
 * Filename: forgotpassword.php
 * Purpose: Render the Authentication workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/auth_start', ['title' => $isRequested ? 'Check your email.' : 'Forgot your password?', 'description' => $isRequested ? 'Your next step is in the password reset email.' : 'Enter your Employee ID or registered email and we will prepare a password reset link.', 'eyebrow' => 'ACCOUNT ACCESS']) ?>

<?php if ($error): ?>
<div
    id="loginError"
    class="login-error"
    role="alert"
    tabindex="-1"
><?= H($error) ?></div>

<?php endif; ?>

<?php if ($isRequested): ?>
<div class="auth-notice" role="status"><?= H(EmailAuth::REQUEST_MESSAGE) ?></div>
<p class="auth-instructions">Reset links expire after <strong>30 minutes</strong> and can be used once. Your password stays the same until you finish the reset.</p>

<?php endif; ?>
<form method="POST" data-auth-form>
    <input
        type="hidden"
        name="csrf_token"
        value="<?= H(CsrfToken()) ?>"
    >
    <div class="form-row"><label for="recoveryIdentifier">Employee ID or registered email</label><input
            id="recoveryIdentifier"
            name="identifier"
            type="text"
            value="<?= H(mb_substr($identifier, 0, 200)) ?>"
            maxlength="200"
            autocomplete="username"
            autocapitalize="none"
            spellcheck="false"
            required
            <?= $isRequested ? '' : 'autofocus' ?>
            placeholder="Enter your ID or email"
        ></div>
    <button
        class="btn btn-primary login-submit"
        type="submit"
        data-busy-label="Preparing email…"
        <?= $isRequested ? 'data-cooldown="' . max(0, (int)$state['retry_at'] - time()) . '"' : '' ?>
    ><?= $isRequested ? 'Resend reset email' : 'Email me a reset link' ?><span class="login-spinner" aria-hidden="true"></span></button>
</form>
<a class="auth-back-link" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/login.php')) ?>"><?= UiIcon('arrow-left') ?> Back to sign in</a>

<?php echo view('layouts/auth_end');
?>
