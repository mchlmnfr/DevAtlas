/**
 * Filename: notifications.js
 * Purpose: Coordinate notifications interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

(() => 
{
    const 
    {
        $: ELEMENT,
        esc: ESC
    }
    = WUI;
    const ROOT = ELEMENT('notificationsApp');
    const API = ROOT.dataset.api;
    const IS_ADMIN = ROOT.dataset.admin === '1';
    let page = 1;
    let pages = 1;
    const Post = (action, body = 
    {

    }) => AT.api(API + '?action=' + action, 
    {
        method: 'POST',
        body
    });
    async function Load() 
    {
        WUI.error('notificationError', '');
        ELEMENT('notificationRows').setAttribute('aria-busy', 'true');
        try 
        {
            const RESULT = await AT.api(API + '?action=inbox&page=' + page + '&unread=' + (ELEMENT('notificationUnread').checked ? '1' : '0'));
            page = RESULT.page;
            pages = RESULT.pages;
            ELEMENT('notificationRows').innerHTML = RESULT.notifications.map(n => '<article class="wf-notice ' + (!n.is_read ? 'unread' : '') + '"><h3>' + ESC(n.title) + '</h3><p>' + ESC(n.message) + '</p><div class="wf-notice-footer"><time>' + ESC(new Date(n.created_at).toLocaleString('en-PH', 
            {
                timeZone: 'Asia/Manila'
            })) + '</time><a class="btn btn-sm" data-notification-open="' + Number(n.notification_id) + '" href="' + ESC(n.target_path) + '">Open details</a>' + (!n.is_read ? '<button class="btn btn-sm" data-mark-read="' + Number(n.notification_id) + '">Mark as read</button>' : '<span class="ws-tag gray">Read</span>') + '</div></article>').join('') || '<div class="ws-card ws-state"><strong>You’re up to date.</strong><p>No notifications match this view.</p></div>';
            ELEMENT('notificationCount').textContent = RESULT.total + ' notifications · page ' + page + ' of ' + pages;
            ELEMENT('notificationPrevious').disabled = page <= 1;
            ELEMENT('notificationNext').disabled = page >= pages;
            AT.refreshNotificationCount?.();
        }
        catch (e) 
        {
            WUI.error('notificationError', e.message);
        }
        finally 
        {
            ELEMENT('notificationRows').setAttribute('aria-busy', 'false');
        }
    }
    ELEMENT('notificationRows').onclick = async e => 
    {
        const B = e.target.closest('[data-mark-read],[data-notification-open]');
        if (!B) 
        {
            return;
        }
        const OPEN = B.dataset.notificationOpen;
        if (OPEN) 
        {
            e.preventDefault();
        }
        try 
        {
            await Post('mark_read', 
            {
                notification_id: OPEN || B.dataset.markRead
            });
            if (OPEN) 
            {
                location.assign(B.href);
            }
            else 
            {
                Load();
            }
        }
        catch (e) 
        {
            WUI.error('notificationError', e.message);
        }
    };
    ELEMENT('notificationRefresh').onclick = Load;
    ELEMENT('notificationReadAll').onclick = () => WUI.busy(ELEMENT('notificationReadAll'), 'Updating…', async () => 
    {
        try 
        {
            await Post('mark_all_read');
            Load();
        }
        catch (e) 
        {
            WUI.error('notificationError', e.message);
        }
    });
    ELEMENT('notificationUnread').onchange = () => 
    {
        page = 1;
        Load();
    };
    ELEMENT('notificationPrevious').onclick = () => 
    {
        page--;
        Load();
    };
    ELEMENT('notificationNext').onclick = () => 
    {
        page++;
        Load();
    };
    AT.api(API + '?action=preferences').then(p => ELEMENT('notificationEmail').checked = p.email_enabled).catch(e => WUI.error('notificationError', e.message));
    ELEMENT('notificationEmail').onchange = async () => 
    {
        try 
        {
            await Post('save_preferences', 
            {
                email_enabled: ELEMENT('notificationEmail').checked
            });
            AT.toast('Email preference saved.');
        }
        catch (e) 
        {
            WUI.error('notificationError', e.message);
        }
    };
    async function Deliveries() 
    {
        try 
        {
            const ROWS = await AT.api(API + '?action=deliveries');
            ELEMENT('notificationDeliveries').innerHTML = ROWS.map(d => '<tr><td>' + ESC(d.employee_id) + '</td><td>' + ESC(d.title) + '</td><td>' + ESC(d.status) + '</td><td>' + Number(d.attempts) + '</td><td>' + ESC(d.last_error || d.sent_at || 'Queued for the worker') + '</td></tr>').join('') || '<tr><td colspan="5" class="ws-state">No queued email deliveries.</td></tr>';
        }
        catch (e) 
        {
            WUI.error('notificationError', e.message);
        }
    }
    if (IS_ADMIN) 
    {
        const CONFIG_CONTROLS = [...ELEMENT('notificationConfig').querySelectorAll('input,button')];
        CONFIG_CONTROLS.forEach(control => control.disabled = true);
        ELEMENT('notificationConfig').setAttribute('aria-busy', 'true');
        AT.api(API + '?action=config').then(c => 
        {
            ELEMENT('notificationDays').value = JSON.parse(c.days).join(',');
            ELEMENT('notificationRepeat').value = c.overdue_repeat_days;
            ELEMENT('notificationGlobalEmail').checked = c.email_enabled;
            ELEMENT('notificationDailyEnabled').checked = c.daily_log_enabled;
            ELEMENT('notificationApprovalEnabled').checked = c.approval_enabled;
            ELEMENT('notificationLogEmail').checked = c.daily_log_email_enabled;
            ELEMENT('notificationLogHour').value = c.daily_log_hour;
            ELEMENT('notificationLogLookback').value = c.daily_log_lookback_days;
            ELEMENT('notificationApprovalDelay').value = c.approval_delay_days;
            ELEMENT('notificationWorker').textContent = 'Last reminder check: ' + (c.last_generated_at ? new Date(c.last_generated_at).toLocaleString() : 'Not run yet') + ' · Last email worker: ' + (c.last_worker_at ? new Date(c.last_worker_at).toLocaleString() : 'Not run yet');
            CONFIG_CONTROLS.forEach(control => control.disabled = false);
            ELEMENT('notificationConfig').removeAttribute('aria-busy');
        }).catch(e => WUI.error('notificationError', e.message));
        ELEMENT('notificationConfig').onsubmit = e => 
        {
            e.preventDefault();
            WUI.busy(ELEMENT('notificationConfigSave'), 'Saving…', async () => 
            {
                try 
                {
                    const RAW = ELEMENT('notificationDays').value.split(',').map(s => s.trim());
                    if (RAW.some(s => !/^\d+$/.test(s))) 
                    {
                        throw new Error('Use comma-separated whole numbers for reminder days.');
                    }
                    await Post('save_config', 
                    {
                        email_enabled: ELEMENT('notificationGlobalEmail').checked,
                        reminder_days: RAW.map(Number),
                        overdue_repeat_days: Number(ELEMENT('notificationRepeat').value),
                        daily_log_enabled: ELEMENT('notificationDailyEnabled').checked,
                        approval_enabled: ELEMENT('notificationApprovalEnabled').checked,
                        daily_log_email_enabled: ELEMENT('notificationLogEmail').checked,
                        daily_log_hour: Number(ELEMENT('notificationLogHour').value),
                        daily_log_lookback_days: Number(ELEMENT('notificationLogLookback').value),
                        approval_delay_days: Number(ELEMENT('notificationApprovalDelay').value)
                    });
                    AT.toast('Reminder delivery settings saved.');
                }
                catch (e) 
                {
                    WUI.error('notificationError', e.message);
                }
            });
        };
        ELEMENT('notificationGenerate').onclick = () => WUI.busy(ELEMENT('notificationGenerate'), 'Checking…', async () => 
        {
            try 
            {
                const RESULT = await Post('run_due');
                AT.toast(RESULT.generated + ' reminder(s) created.');
                Load();
                Deliveries();
            }
            catch (e) 
            {
                WUI.error('notificationError', e.message);
            }
        });
        ELEMENT('notificationDeliveryRefresh').onclick = Deliveries;
        Deliveries();
    }
    Load();
})();