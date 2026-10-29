<?php
/**
 * Filename: resetpassword.php
 * Purpose: Render the Authentication workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/auth_start', ['title' => $isSuccess ? 'Password updated.' : 'Choose a new password.', 'description' => $isSuccess ? 'You can now sign in with your new password.' : 'Choose a password you have not used for this account.', 'eyebrow' => 'ACCOUNT ACCESS']) ?>

<?php if ($error): ?>
<div
    id="loginError"
    class="login-error"
    role="alert"
    tabindex="-1"
><?= H($error) ?></div>

<?php endif; ?>

<?php if ($isSuccess): ?>
<div class="auth-notice" role="status">Your password has been changed. Other sign-in sessions will expire on their next account check (within 30 seconds). Previous codes and reset links no longer work.</div>
<a class="btn btn-primary login-submit" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/login.php')) ?>">Continue to sign in <?= UiIcon('arrow-right') ?></a>

<?php elseif (!$isValid): ?>
<div class="auth-notice"><?= H(EmailAuth::INVALID_RESET) ?></div><a class="btn btn-primary login-submit" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/forgot_password.php')) ?>">Request a new reset link</a>

<?php else: ?>
<form method="POST" data-auth-form>
    <input
        type="hidden"
        name="csrf_token"
        value="<?= H(CsrfToken()) ?>"
    >
    <div class="form-row"><label for="newPassword">New password</label><input
            id="newPassword"
            name="new_password"
            type="password"
            minlength="8"
            required
            autocomplete="new-password"
            aria-describedby="passwordGuidance"
            autofocus
        >
        <p class="auth-field-hint" id="passwordGuidance">At least 8 characters; maximum 72 bytes. Use a unique password.</p>
    </div>
    <div class="form-row"><label for="confirmPassword">Confirm new password</label><input
            id="confirmPassword"
            name="confirm_password"
            type="password"
            minlength="8"
            required
            autocomplete="new-password"
        ></div>
    <button
        class="btn btn-primary login-submit"
        type="submit"
        data-busy-label="Updating password…"
    >Update password<span class="login-spinner" aria-hidden="true"></span></button>
</form>

<?php endif; ?>
<a class="auth-back-link" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/login.php')) ?>"><?= UiIcon('arrow-left') ?> Back to sign in</a>

<?php echo view('layouts/auth_end');
?>
