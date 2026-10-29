<?php
/**
 * Filename: authemailpreview.php
 * Purpose: Render the Authentication workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div class="card">
    <div class="auth-preview-heading">
        <div>
            <h2><?= $preview ? ($preview['purpose'] === 'signin' ? 'Sign-in code email' : 'Password reset email') : 'Authentication emails' ?></h2>
            <p>Recent requests · Times in Asia/Manila</p>
        </div>
        <div class="flex gap-8"><a class="btn" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/auth_email_preview.php')) ?>">Recent emails</a><a class="btn" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/settings/index.php')) ?>">Email settings</a></div>
    </div>
    <div class="auth-preview-note">This page requires the same signed-in access as User Management. Confirm the person’s identity before sharing a code or reset link. For code sign-in, the person must keep the original requesting browser open.</div>

    <?php if ($id !== '' && !$preview): ?>
    <p>No email details are available for this reference. The account may be ineligible, requests may be limited, or this record may have expired. Verify the account and registered email in User Management, then request a new email.</p>

    <?php elseif ($preview): ?>
    <dl class="auth-preview-meta">
        <div>
            <dt>Employee ID</dt>
            <dd><?= H($preview['employee_id']) ?></dd>
        </div>
        <div>
            <dt>Registered email</dt>
            <dd><?= H($preview['recipient'] ?: 'No email on file') ?></dd>
        </div>
        <div>
            <dt>Delivery</dt>
            <dd><span class="auth-preview-status"><?= H(ucfirst($preview['delivery_status'])) ?></span></dd>
        </div>
        <div>
            <dt>Requested</dt>
            <dd><?= H($formatTime($preview['created_at'])) ?></dd>
        </div>
        <div>
            <dt>Expires</dt>
            <dd><?= H($formatTime($preview['expires_at'])) ?></dd>
        </div>
        <div>
            <dt>Credential</dt>
            <dd><?= $preview['secret'] ? 'Available · single use' : 'Unavailable · used, expired or revoked' ?></dd>
        </div>
    </dl>
    <p class="text-sm muted"><?= H($preview['delivery_note'] ?? 'Delivery is being attempted. Refresh for the latest status.') ?></p>
    <div class="auth-preview-message">

        <?php if (!$preview['secret']): ?>
        <h3>This credential is no longer available.</h3>
        <p>Ask the person to request a new email. Used, expired and revoked credentials cannot be displayed.</p>

        <?php elseif ($preview['purpose'] === 'signin'): ?>
        <h3>Your one-time sign-in code</h3>
        <div class="auth-preview-code"><?= H($preview['secret']) ?></div>
        <p>Enter this code in the browser where it was requested. Five incorrect attempts invalidate it.</p>

        <?php else: ?>
        <h3>Choose a new password</h3>
        <p>Copy the reset link and give it to the verified account holder. It can be used once before the expiry above.</p><code class="auth-preview-link"><?= H($preview['reset_url']) ?></code>
        <p>The account holder must open the link in their own browser to finish the reset.</p>

        <?php endif; ?>
    </div>

    <?php else: ?>
    <p class="text-sm muted">The latest 50 requests from the last 24 hours. Credentials are hidden after use or expiry. Only eligible accounts create an email record.</p>
    <div class="auth-preview-table">
        <table>
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Purpose</th>
                    <th>Delivery</th>
                    <th>Requested</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>

                <?php foreach ($recent as $row): ?>
                <tr>
                    <td><?= H($row['employee_id']) ?></td>
                    <td><?= $row['purpose'] === 'signin' ? 'Sign-in code' : 'Password reset' ?></td>
                    <td><span class="auth-preview-status"><?= H(ucfirst($row['delivery_status'])) ?></span></td>
                    <td><?= H($formatTime($row['created_at'])) ?></td>
                    <td><a
                            href="<?= H(RouteUrls::Url(APP_BASE_URL . '/auth_email_preview.php')) ?>?id=<?= H($row['challenge_id']) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >View details <?= UiIcon('arrow-up-right') ?></a></td>
                </tr>

                <?php endforeach; ?>

                <?php if (!$recent): ?>
                <tr>
                    <td colspan="5">No recent authentication emails.</td>
                </tr>

                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
</div>

<?php echo view('layouts/footer', get_defined_vars());
?>
