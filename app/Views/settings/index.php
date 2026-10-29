<?php
/**
 * Filename: index.php
 * Purpose: Render the Settings workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="settingsApp"
    data-api="<?= APP_BASE_URL ?>/modules/settings/api.php"
    data-base="<?= APP_BASE_URL ?>"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">ADMINISTRATION / PARAMETERS</div>
            <h2>Keep your workspace configured.</h2>
            <p>Manage password rules and the email settings people rely on to access their accounts.</p>
        </div><a
            class="btn"
            href="<?= H(RouteUrls::Url(APP_BASE_URL . '/auth_email_preview.php')) ?>"
            target="_blank"
            rel="noopener noreferrer"
        >Protected email details <?= UiIcon('arrow-up-right') ?></a>
    </section>
    <section class="ws-summary" aria-label="Current saved settings">
        <div class="ws-stat"><span class="ws-stat-icon" data-icon="clock"></span><span><strong id="settingsExpiry">—</strong><small>Password rotation</small></span></div>
        <div class="ws-stat"><span class="ws-stat-icon" data-icon="shield"></span><span><strong id="settingsAttempts">—</strong><small>Failed attempts before lock</small></span></div>
        <div class="ws-stat"><span class="ws-stat-icon" data-icon="mail"></span><span><strong id="settingsMail">—</strong><small>SMTP configuration</small></span></div>
        <div class="ws-stat"><span class="ws-stat-icon" data-icon="check"></span><span><strong id="settingsLinks">—</strong><small>Recovery link address</small></span></div>
    </section>
    <div
        id="settingsLoadError"
        class="ws-error"
        role="alert"
        tabindex="-1"
        hidden
    >We couldn’t load your settings. <button
            type="button"
            class="ws-link-button"
            id="settingsRetry"
        >Try again</button></div>
    <div class="settings-layout">
        <aside>
            <nav
                class="settings-nav ws-card"
                role="tablist"
                aria-label="Parameter sections"
                aria-orientation="vertical"
            ><button
                    id="tab-policy"
                    class="settings-tab"
                    type="button"
                    role="tab"
                    aria-selected="true"
                    aria-controls="panel-policy"
                    data-section="policy"
                ><span data-icon="shield"></span><span><strong>Password policy</strong><small>Account and sign-in rules</small></span><i id="dirty-policy" hidden>Unsaved</i></button><button
                    id="tab-smtp"
                    class="settings-tab"
                    type="button"
                    role="tab"
                    aria-selected="false"
                    aria-controls="panel-smtp"
                    data-section="smtp"
                    tabindex="-1"
                ><span data-icon="mail"></span><span><strong>Email delivery</strong><small>Outgoing SMTP connection</small></span><i id="dirty-smtp" hidden>Unsaved</i></button><button
                    id="tab-recovery"
                    class="settings-tab"
                    type="button"
                    role="tab"
                    aria-selected="false"
                    aria-controls="panel-recovery"
                    data-section="recovery"
                    tabindex="-1"
                ><span data-icon="branch"></span><span><strong>Recovery links</strong><small>Address used in reset emails</small></span><i id="dirty-recovery" hidden>Unsaved</i></button></nav>
            <div class="ws-note"><strong>Save each section separately.</strong> Changing email settings won’t replace your password policy or recovery address.</div>
        </aside>
        <div class="settings-panels">
            <section
                class="ws-card"
                id="panel-policy"
                role="tabpanel"
                aria-labelledby="tab-policy"
            >
                <form id="settings-policy" data-section="policy">
                    <div class="ws-card-heading">
                        <div>
                            <h3>Password policy</h3>
                            <p>Set the defaults used when people sign in or receive a new account.</p>
                        </div><span class="ws-tag">All accounts</span>
                    </div>
                    <div class="ws-card-body">
                        <div
                            id="error-policy"
                            class="ws-error"
                            role="alert"
                            tabindex="-1"
                            hidden
                        ></div>
                        <fieldset class="settings-fields" disabled>
                            <div class="form-row"><label for="s_default_password">Default temporary password *</label>
                                <div class="ws-password"><input
                                        type="password"
                                        id="s_default_password"
                                        required
                                        minlength="8"
                                        autocomplete="new-password"
                                    ><button
                                        type="button"
                                        data-reveal="s_default_password"
                                        aria-pressed="false"
                                        aria-label="Show or hide default password"
                                    >Show</button></div>
                                <div class="hint">Used for new accounts when no custom password is entered. The person must change it at first sign-in.</div>
                            </div>
                            <div class="form-grid">
                                <div class="form-row"><label for="s_password_expiry_days">Password expiry (days) *</label><input
                                        type="number"
                                        id="s_password_expiry_days"
                                        min="0"
                                        step="1"
                                        required
                                    >
                                    <div class="hint">0 turns off scheduled expiry. Expired accounts must update their password after signing in.</div>
                                </div>
                                <div class="form-row"><label for="s_max_login_attempts">Maximum failed password attempts *</label><input
                                        type="number"
                                        id="s_max_login_attempts"
                                        min="1"
                                        step="1"
                                        required
                                    >
                                    <div class="hint">The account locks after this many consecutive incorrect password attempts.</div>
                                </div>
                            </div>
                            <div class="ws-note"><strong>A locked account needs help.</strong> Unlock accounts from the <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/usermgmt/index.php')) ?>">Users</a> module after checking the request.</div>
                        </fieldset>
                    </div>
                    <div class="settings-save-bar"><span id="status-policy" role="status">Loading settings…</span>
                        <div class="ws-actions"><button
                                class="btn"
                                type="button"
                                data-discard="policy"
                                disabled
                            >Discard changes</button><button
                                class="btn btn-primary"
                                type="submit"
                                id="save-policy"
                                disabled
                            >Save policy</button></div>
                    </div>
                </form>
            </section>
            <section
                class="ws-card"
                id="panel-smtp"
                role="tabpanel"
                aria-labelledby="tab-smtp"
                hidden
            >
                <form id="settings-smtp" data-section="smtp">
                    <div class="ws-card-heading">
                        <div>
                            <h3>Outgoing email</h3>
                            <p>Used for welcome emails, password reset links and one-time sign-in codes.</p>
                        </div><span class="ws-tag" id="smtpSavedState">Loading</span>
                    </div>
                    <div class="ws-card-body">
                        <div
                            id="error-smtp"
                            class="ws-error"
                            role="alert"
                            tabindex="-1"
                            hidden
                        ></div>
                        <fieldset class="settings-fields" disabled>
                            <fieldset class="ws-form-section">
                                <legend>CONNECTION</legend>
                                <div class="form-grid">
                                    <div class="form-row"><label for="s_smtp_host">SMTP host</label><input
                                            type="text"
                                            id="s_smtp_host"
                                            maxlength="255"
                                            placeholder="smtp.yourcompany.local"
                                            autocomplete="off"
                                        ></div>
                                    <div class="form-row"><label for="s_smtp_port">Port *</label><input
                                            type="number"
                                            id="s_smtp_port"
                                            min="1"
                                            max="65535"
                                            step="1"
                                            required
                                        ></div>
                                </div>
                                <div class="form-row"><label for="s_smtp_encryption">Connection encryption</label><select id="s_smtp_encryption">
                                        <option value="tls">STARTTLS</option>
                                        <option value="ssl">SSL / TLS</option>
                                        <option value="none">None — internal relay</option>
                                    </select></div>
                                <div class="form-grid">
                                    <div class="form-row"><label for="s_smtp_username">Username <span class="muted">(optional)</span></label><input
                                            type="text"
                                            id="s_smtp_username"
                                            maxlength="255"
                                            autocomplete="off"
                                        >
                                        <div class="hint">Leave blank if your mail relay does not require authentication.</div>
                                    </div>
                                    <div class="form-row"><label for="s_smtp_password">SMTP password</label>
                                        <div class="ws-password"><input
                                                type="password"
                                                id="s_smtp_password"
                                                maxlength="255"
                                                autocomplete="new-password"
                                                placeholder="Leave blank to keep the saved password"
                                            ><button
                                                type="button"
                                                data-reveal="s_smtp_password"
                                                aria-pressed="false"
                                                aria-label="Show or hide SMTP password"
                                            >Show</button></div>
                                        <div class="hint" id="smtpPasswordHint">The saved password is never displayed.</div>
                                    </div>
                                </div>
                            </fieldset>
                            <fieldset class="ws-form-section">
                                <legend>SENDER</legend>
                                <div class="form-grid">
                                    <div class="form-row"><label for="s_smtp_from_name">From name</label><input
                                            type="text"
                                            id="s_smtp_from_name"
                                            maxlength="150"
                                            placeholder="DevAtlas"
                                        ></div>
                                    <div class="form-row"><label for="s_smtp_from_email">From email</label><input
                                            type="email"
                                            id="s_smtp_from_email"
                                            maxlength="255"
                                            placeholder="noreply@yourcompany.local"
                                        ></div>
                                </div>
                            </fieldset>
                            <div class="settings-test-row">
                                <div><strong>Check your connection</strong>
                                    <p>Save your delivery settings, then send a test message.</p>
                                </div><button
                                    class="btn"
                                    type="button"
                                    id="settingsTestEmail"
                                >Send test email</button>
                            </div>
                        </fieldset>
                    </div>
                    <div class="settings-save-bar"><span id="status-smtp" role="status">Loading settings…</span>
                        <div class="ws-actions"><button
                                class="btn"
                                type="button"
                                data-discard="smtp"
                                disabled
                            >Discard changes</button><button
                                class="btn btn-primary"
                                type="submit"
                                id="save-smtp"
                                disabled
                            >Save email settings</button></div>
                    </div>
                </form>
            </section>
            <section
                class="ws-card"
                id="panel-recovery"
                role="tabpanel"
                aria-labelledby="tab-recovery"
                hidden
            >
                <form id="settings-recovery" data-section="recovery">
                    <div class="ws-card-heading">
                        <div>
                            <h3>Password recovery links</h3>
                            <p>Make sure reset emails point to the address people use to reach this application.</p>
                        </div>
                    </div>
                    <div class="ws-card-body">
                        <div
                            id="error-recovery"
                            class="ws-error"
                            role="alert"
                            tabindex="-1"
                            hidden
                        ></div>
                        <fieldset class="settings-fields" disabled>
                            <div class="form-row"><label for="s_auth_base_url">Application URL *</label><input
                                    type="url"
                                    id="s_auth_base_url"
                                    maxlength="500"
                                    required
                                    placeholder="https://your-server<?= APP_BASE_URL ?>"
                                >
                                <div class="hint">Include http:// or https:// and end with <?= H(APP_BASE_URL) ?>. Use a server address reachable from the users’ devices.</div>
                            </div>
                            <div class="settings-link-example"><span>RESET LINKS WILL START WITH</span><code id="recoveryLinkExample">—</code></div>
                            <div class="ws-note"><strong>Localhost is for this computer.</strong> If people use DevAtlas from other devices, enter the server name or address they use instead.</div>
                            <div class="settings-recovery-help"><span data-icon="mail"></span>
                                <div><strong>When email is unavailable</strong>
                                    <p>Signed-in support can inspect delivery details and active credentials in <a
                                            href="<?= H(RouteUrls::Url(APP_BASE_URL . '/auth_email_preview.php')) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >protected email details <?= UiIcon('arrow-up-right') ?></a>.</p>
                                </div>
                            </div>
                        </fieldset>
                    </div>
                    <div class="settings-save-bar"><span id="status-recovery" role="status">Loading settings…</span>
                        <div class="ws-actions"><button
                                class="btn"
                                type="button"
                                data-discard="recovery"
                                disabled
                            >Discard changes</button><button
                                class="btn btn-primary"
                                type="submit"
                                id="save-recovery"
                                disabled
                            >Save recovery address</button></div>
                    </div>
                </form>
            </section>
        </div>
    </div>
</div>
<dialog
    id="testEmailModal"
    class="ws-dialog"
    aria-labelledby="testEmailTitle"
>
    <form id="testEmailForm">
        <div class="ws-dialog-header">
            <div>
                <h3 id="testEmailTitle">Send a test email</h3>
                <p>Use the saved SMTP settings to check delivery.</p>
            </div><button
                type="button"
                class="ws-dialog-close"
                data-dialog-close="testEmailModal"
                aria-label="Close test email"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                id="testEmailError"
                class="ws-error"
                role="alert"
                tabindex="-1"
                hidden
            ></div>
            <div class="form-row"><label for="te_to">Recipient email *</label><input
                    type="email"
                    id="te_to"
                    value="<?= H($currentUser['email'] ?? '') ?>"
                    maxlength="255"
                    required
                    autocomplete="email"
                ></div>
            <div
                id="testEmailResult"
                class="ws-note"
                role="status"
                hidden
            ></div>
        </div>
        <div class="ws-dialog-footer"><button
                type="button"
                class="btn"
                data-dialog-close="testEmailModal"
            >Close</button><button
                type="submit"
                class="btn btn-primary"
                id="testEmailSend"
            >Send test email</button></div>
    </form>
</dialog>

<?php echo view('layouts/footer', get_defined_vars());
?>
