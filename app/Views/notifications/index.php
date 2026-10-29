<?php
/**
 * Filename: index.php
 * Purpose: Render the Notifications workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="notificationsApp"
    data-api="<?= APP_BASE_URL ?>/modules/notifications/api.php"
    data-admin="<?= Permissions::IsAdmin() ? '1' : '0' ?>"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">PERSONAL INBOX</div>
            <h2>Keep the next step in view.</h2>
            <p>Daily-log reminders, weekly approvals, renewals and code-review assignments appear here.</p>
        </div>
        <div class="ws-actions"><button class="btn" id="notificationRefresh">Refresh</button><button class="btn btn-primary" id="notificationReadAll">Mark all as read</button></div>
    </section>
    <div
        id="notificationError"
        class="ws-error"
        hidden
        role="alert"
        tabindex="-1"
    ></div>
    <section class="ws-card">
        <div class="wf-panel">
            <div class="wf-grid"><label class="wf-checkbox"><input id="notificationUnread" type="checkbox">Show unread only</label><label class="wf-checkbox"><input id="notificationEmail" type="checkbox">Email me when reminder email delivery is enabled</label></div>
            <p class="wf-muted">Email preferences do not hide your in-app notifications.</p>
        </div>
    </section>
    <div class="wf-inbox" id="notificationRows"></div>
    <div class="ws-pagination"><span id="notificationCount"></span>
        <div class="ws-actions"><button class="btn btn-sm" id="notificationPrevious">Previous</button><button class="btn btn-sm" id="notificationNext">Next</button></div>
    </div>

    <?php if (Permissions::IsAdmin()):?>
    <section class="ws-card">
        <div class="ws-card-heading">
            <div>
                <h3>Reminder delivery settings</h3>
                <p>Renewals go to linked application owners, or Administrators when no owner is assigned. Review reminders go to the assigned reviewer.</p>
            </div><button class="btn" id="notificationGenerate">Check reminders now</button>
        </div>
        <form id="notificationConfig" class="wf-panel">
            <div class="form-grid">
                <div class="form-row"><label for="notificationDays">Days before expiry</label><input id="notificationDays" placeholder="30,14,7,1,0">
                    <p class="hint">Use 0 for the expiry date. Missed thresholds are caught on the next check.</p>
                </div>
                <div class="form-row"><label for="notificationRepeat">Repeat overdue reminders every</label><input
                        id="notificationRepeat"
                        type="number"
                        min="1"
                        max="90"
                        value="7"
                    >
                    <p class="hint">Days, from 1 to 90.</p>
                </div>
            </div>
            <fieldset class="notification-log-settings">
                <legend>Daily logs &amp; weekly approvals</legend>
                <div class="form-grid"><label class="wf-checkbox"><input id="notificationDailyEnabled" type="checkbox">Remind employees about missing hours and returned weeks</label><label class="wf-checkbox"><input id="notificationApprovalEnabled" type="checkbox">Remind eligible reviewers about pending employee weeks</label></div>
                <div class="form-grid">
                    <div class="form-row"><label for="notificationLogHour">Daily check hour (Asia/Manila)</label><input
                            id="notificationLogHour"
                            type="number"
                            min="0"
                            max="23"
                            value="17"
                            required
                        >
                        <p class="hint">0 to 23. At most one digest of each type per person per working day.</p>
                    </div>
                    <div class="form-row"><label for="notificationLogLookback">Check the last how many calendar days?</label><input
                            id="notificationLogLookback"
                            type="number"
                            min="1"
                            max="90"
                            value="14"
                            required
                        >
                        <p class="hint">Only working days since the account was created are counted.</p>
                    </div>
                    <div class="form-row"><label for="notificationApprovalDelay">Approval reminder delay (days)</label><input
                            id="notificationApprovalDelay"
                            type="number"
                            min="0"
                            max="30"
                            value="1"
                            required
                        >
                        <p class="hint">Wait this long after the latest submitted entry in a week. Use 0 for no delay.</p>
                    </div>
                </div><label class="wf-checkbox"><input id="notificationLogEmail" type="checkbox">Also email daily-log and weekly-approval reminders</label>
                <p class="wf-muted">These reminders are in-app by default. They skip weekends and organization holidays. Employees receive their own reminders; assigned reviewers receive eligible weeks, and administrators cover weeks without an eligible reviewer. Future dates and private drafts do not count as submitted hours.</p>
            </fieldset><label class="wf-checkbox"><input id="notificationGlobalEmail" type="checkbox">Enable reminder emails using saved SMTP settings</label>
            <p class="wf-muted">Configure and test SMTP in <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/settings/index.php')) ?>">Parameters</a>. Enabling email also queues unread reminders from the last 24 hours for recipients whose email preference is enabled. The scheduled worker processes the outbound queue.</p><button class="btn btn-primary" id="notificationConfigSave">Save delivery settings</button>
            <p id="notificationWorker" class="wf-muted"></p>
        </form>
    </section>
    <section class="ws-card">
        <div class="ws-card-heading">
            <div>
                <h3>Recent email delivery</h3>
                <p>Failed deliveries retry up to five times. Unavailable or opted-out recipients are skipped.</p>
            </div><button class="btn" id="notificationDeliveryRefresh">Refresh delivery</button>
        </div>
        <div class="ws-table-wrap">
            <table class="ws-table">
                <thead>
                    <tr>
                        <th>Recipient</th>
                        <th>Notification</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="notificationDeliveries"></tbody>
            </table>
        </div>
    </section>

    <?php endif; ?>
</div>

<?php echo view('layouts/footer', get_defined_vars());
?>
