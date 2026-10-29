/**
 * Filename: settings.js
 * Purpose: Coordinate settings interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* Section-specific parameter forms keep unrelated unsaved changes intact. */
(() => 
{
    const 
    {
        $: ELEMENT,
        icon: ICON
    }
    = WUI;
    const API = ELEMENT('settingsApp').dataset.api;
    const FIELDS = 
    {
        policy: ['default_password', 'password_expiry_days', 'max_login_attempts'],
        smtp: ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_name', 'smtp_from_email'],
        recovery: ['auth_base_url']
    };
    let saved = 
    {

    };
    let baselines = 
    {

    };
    let isLoaded = false;
    const DIRTY = new Set();
    const SAVING = new Set();
    const SECTIONS = Object.keys(FIELDS);
    document.querySelectorAll('[data-icon]').forEach(el => el.innerHTML = ICON(el.dataset.icon));
    const Values = section => Object.fromEntries(FIELDS[section].map(field => [field, ELEMENT('s_' + field).value]));
    const Snapshot = section => JSON.stringify(Values(section));
    function Sync(section) 
    {
        if (!isLoaded) 
        {
            return;
        }
        const IS_CHANGED = Snapshot(section) !== baselines[section];
        IS_CHANGED ? DIRTY.add(section) : DIRTY.delete(section);
        ELEMENT('dirty-' + section).hidden = !IS_CHANGED;
        ELEMENT('save-' + section).disabled = !IS_CHANGED || SAVING.has(section);
        document.querySelector(`[data-discard="${section}"]`).disabled = !IS_CHANGED || SAVING.has(section);
        ELEMENT('status-' + section).textContent = SAVING.has(section) ? 'Saving…' : IS_CHANGED ? 'You have unsaved changes.' : 'All changes saved.';
        ELEMENT('status-' + section).classList.toggle('is-dirty', IS_CHANGED);
        if (section === 'recovery') 
        {
            ELEMENT('recoveryLinkExample').textContent = ELEMENT('s_auth_base_url').value.replace(/\/+$/, '') + '/reset_password.php';
        }
    }
    function Fill(section) 
    {
        for (const FIELD of FIELDS[section]) 
        {
            ELEMENT('s_' + FIELD).value = saved[FIELD] ?? '';
        }
        baselines[section] = Snapshot(section);
        Sync(section);
    }
    function Summary() 
    {
        const EXPIRY = Number(saved.password_expiry_days);
        ELEMENT('settingsExpiry').textContent = EXPIRY > 0 ? `${EXPIRY} days` : 'Off';
        ELEMENT('settingsAttempts').textContent = saved.max_login_attempts;
        const IS_CONFIGURED = !!saved.smtp_host && !!saved.smtp_from_email;
        ELEMENT('settingsMail').textContent = IS_CONFIGURED ? 'Configured' : 'Not set';
        ELEMENT('smtpSavedState').textContent = IS_CONFIGURED ? 'Settings saved' : 'Not configured';
        ELEMENT('settingsLinks').textContent = ['localhost', '127.0.0.1', '[::1]', '::1'].includes(new URL(saved.auth_base_url).hostname) ? 'Local only' : 'Server URL';
        ELEMENT('smtpPasswordHint').textContent = saved.smtp_password_set ? 'A password is saved. Leave blank to keep it.' : 'No SMTP password is saved. Leave blank if your relay does not require one.';
    }
    async function Load() 
    {
        ELEMENT('settingsLoadError').hidden = true;
        try 
        {
            saved = await AT.api(API + '?action=get_settings');
            isLoaded = true;
            for (const SECTION of SECTIONS) 
            {
                Fill(SECTION);
                document.querySelector(`#settings-${SECTION} > .ws-card-body > .settings-fields`).disabled = false;
            }
            Summary();
        }
        catch (error) 
        {
            ELEMENT('settingsLoadError').hidden = false;
        }
    }
    function Select(section, isFocus = false) 
    {
        if (!SECTIONS.includes(section)) 
        {
            section = 'policy';
        }
        for (const VALUE of SECTIONS) 
        {
            ELEMENT('panel-' + VALUE).hidden = VALUE !== section;
            const TAB = ELEMENT('tab-' + VALUE);
            TAB.setAttribute('aria-selected', String(VALUE === section));
            TAB.tabIndex = VALUE === section ? 0 : -1;
        }
        if (isFocus) 
        {
            ELEMENT('tab-' + section).focus();
        }
        history.replaceState(null, '', '#' + section);
    }
    SECTIONS.forEach(section => 
    {
        const FORM = ELEMENT('settings-' + section);
        FORM.addEventListener('input', () => Sync(section));
        FORM.addEventListener('change', () => Sync(section));
        FORM.addEventListener('submit', async event => 
        {
            event.preventDefault();
            if (SAVING.has(section) || !DIRTY.has(section)) 
            {
                return;
            }
            const BODY = 
            {
                section,
                ...Values(section)
            };
            SAVING.add(section);
            FORM.querySelector('.settings-fields').disabled = true;
            Sync(section);
            WUI.error('error-' + section, '');
            try 
            {
                const RESULT = await AT.api(API + '?action=save_settings', 
                {
                    method: 'POST',
                    body: BODY
                });
                Object.assign(saved, BODY, RESULT.values || 
                {

                });
                if (section === 'smtp') 
                {
                    if (BODY.smtp_password) 
                    {
                        saved.smtp_password_set = true;
                    }
                    saved.smtp_password = '';
                }
                Fill(section);
                Summary();
                AT.toast('Settings saved.');
            }
            catch (error) 
            {
                WUI.error('error-' + section, error.message);
            }
            finally 
            {
                SAVING.delete(section);
                FORM.querySelector('.settings-fields').disabled = false;
                Sync(section);
            }
        });
    });
    document.querySelectorAll('[data-discard]').forEach(button => button.addEventListener('click', () => 
    {
        Fill(button.dataset.discard);
        WUI.error('error-' + button.dataset.discard, '');
    }));
    document.querySelectorAll('.settings-tab').forEach((button, index) => 
    {
        button.addEventListener('click', () => Select(button.dataset.section));
        button.addEventListener('keydown', event => 
        {
            let next = null;
            if (['ArrowDown', 'ArrowRight'].includes(event.key)) 
            {
                next = (index + 1) % SECTIONS.length;
            }
            if (['ArrowUp', 'ArrowLeft'].includes(event.key)) 
            {
                next = (index + SECTIONS.length - 1) % SECTIONS.length;
            }
            if (event.key === 'Home') 
            {
                next = 0;
            }
            if (event.key === 'End') 
            {
                next = SECTIONS.length - 1;
            }
            if (next !== null) 
            {
                event.preventDefault();
                Select(SECTIONS[next], true);
            }
        });
    });
    ELEMENT('settingsTestEmail').addEventListener('click', () => 
    {
        WUI.error('testEmailError', '');
        ELEMENT('testEmailResult').hidden = true;
        if (DIRTY.has('smtp')) 
        {
            WUI.error('error-smtp', 'Save or discard your email changes before testing the saved connection.');
            return;
        }
        WUI.open('testEmailModal');
    });
    ELEMENT('testEmailForm').addEventListener('submit', event => 
    {
        event.preventDefault();
        WUI.busy(ELEMENT('testEmailSend'), 'Sending…', async () => 
        {
            WUI.error('testEmailError', '');
            ELEMENT('testEmailResult').hidden = true;
            try 
            {
                await AT.api(API + '?action=send_test_email', 
                {
                    method: 'POST',
                    body: 
                    {
                        to: ELEMENT('te_to').value.trim()
                    }
                });
                ELEMENT('testEmailResult').textContent = 'The SMTP server accepted the test message. Check the recipient inbox and spam folder to confirm delivery.';
                ELEMENT('testEmailResult').hidden = false;
            }
            catch (error) 
            {
                WUI.error('testEmailError', error.message);
            }
        });
    });
    ELEMENT('settingsRetry').addEventListener('click', Load);
    addEventListener('beforeunload', event => 
    {
        if (DIRTY.size) 
        {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    addEventListener('hashchange', () => Select(location.hash.slice(1)));
    Select(location.hash.slice(1));
    Load();
})();