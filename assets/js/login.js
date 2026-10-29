/**
 * Filename: login.js
 * Purpose: Coordinate login interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* Standalone sign-in interactions; authentication remains a native POST. */
(() => 
{
    const FORM = document.getElementById('loginForm');
    const PASSWORD = document.getElementById('loginPassword');
    const TOGGLE = document.getElementById('loginPasswordToggle');
    const THEME = document.getElementById('loginThemeButton');
    const SyncTheme = () => THEME?.setAttribute('aria-label', `Switch to ${AT.getTheme() === 'dark' ? 'light' : 'dark'} theme`);
    THEME?.addEventListener('click', () => 
    {
        AT.toggleTheme();
        SyncTheme();
    });
    SyncTheme();
    TOGGLE?.addEventListener('click', () => 
    {
        const IS_VISIBLE = PASSWORD.type === 'password';
        PASSWORD.type = IS_VISIBLE ? 'text' : 'password';
        TOGGLE.textContent = IS_VISIBLE ? 'Hide' : 'Show';
        TOGGLE.setAttribute('aria-pressed', String(IS_VISIBLE));
        TOGGLE.setAttribute('aria-label', IS_VISIBLE ? 'Hide password' : 'Show password');
    });
    for (const EVENT of ['keydown', 'keyup']) 
    {
        PASSWORD?.addEventListener(EVENT, event => 
        {
            document.getElementById('loginCapsLock').hidden = !event.getModifierState?.('CapsLock');
        });
    }
    PASSWORD?.addEventListener('blur', () => 
    {
        document.getElementById('loginCapsLock').hidden = true;
    });
    const Reset = () => 
    {
        const SUBMIT = document.getElementById('loginSubmit');
        if (!SUBMIT) 
        {
            return;
        }
        SUBMIT.disabled = false;
        SUBMIT.classList.remove('is-loading');
        document.getElementById('loginSubmitLabel').textContent = 'Sign in';
        FORM.removeAttribute('aria-busy');
    };
    FORM?.addEventListener('submit', event => 
    {
        const SUBMIT = document.getElementById('loginSubmit');
        if (SUBMIT.disabled) 
        {
            event.preventDefault();
            return;
        }
        PASSWORD.type = 'password';
        SUBMIT.disabled = true;
        SUBMIT.classList.add('is-loading');
        document.getElementById('loginSubmitLabel').textContent = 'Signing in…';
        FORM.setAttribute('aria-busy', 'true');
    });
    addEventListener('pageshow', Reset);
    document.querySelectorAll('[data-auth-form]').forEach(form => 
    {
        const BUTTON = form.querySelector('[type="submit"]');
        const MARKUP = BUTTON.innerHTML;
        form.addEventListener('submit', event => 
        {
            if (BUTTON.disabled) 
            {
                event.preventDefault();
                return;
            }
            BUTTON.disabled = true;
            form.setAttribute('aria-busy', 'true');
            BUTTON.classList.add('is-loading');
            if (BUTTON.dataset.busyLabel) 
            {
                BUTTON.textContent = BUTTON.dataset.busyLabel;
            }
        });
        addEventListener('pageshow', () => 
        {
            if (!BUTTON.hasAttribute('data-cooldown')) 
            {
                BUTTON.disabled = false;
            }
            BUTTON.classList.remove('is-loading');
            BUTTON.innerHTML = MARKUP;
            form.removeAttribute('aria-busy');
        });
    });
    document.querySelectorAll('[data-cooldown]').forEach(button => 
    {
        const LABEL = button.textContent;
        const UNTIL = Date.now() + Number(button.dataset.cooldown) * 1000;
        const Tick = () => 
        {
            const SECONDS = Math.max(0, Math.ceil((UNTIL - Date.now()) / 1000));
            button.disabled = SECONDS > 0;
            button.textContent = SECONDS ? `Resend in ${SECONDS}s` : LABEL;
        };
        Tick();
        const TIMER = setInterval(() => 
        {
            Tick();
            if (Date.now() >= UNTIL) 
            {
                clearInterval(TIMER);
            }
        }, 1000);
        addEventListener('pageshow', Tick);
    });
    document.getElementById('loginError')?.focus();
})();