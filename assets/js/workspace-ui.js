/**
 * Filename: workspace-ui.js
 * Purpose: Coordinate workspace ui interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* Shared native-dialog, feedback and formatting helpers for workspace modules. */
const WUI = (() => 
{
    const GetElement = id => document.getElementById(id);
    const Esc = value => AT.escapeHtml(value);
    const Icon = (name = 'grid') => AT.icon(
    {
        grid: 'layout-dashboard',
        users: 'users',
        search: 'search',
        folder: 'folder',
        file: 'file-text',
        shield: 'shield-check',
        mail: 'mail',
        branch: 'network',
        check: 'check',
        clock: 'clock-3'
    }
    [name] || 'layout-dashboard', 'ws-icon');
    const GetError = (id, message) => 
    {
        const EL = GetElement(id);
        EL.textContent = message || '';
        EL.hidden = !message;
        if (message) 
        {
            EL.focus();
        }
    };
    const Open = id => GetElement(id).showModal();
    const Close = id => GetElement(id).close();
    const Bytes = value => 
    {
        const N = Number(value) || 0;
        if (N < 1024) 
        {
            return `${N} B`;
        }
        const UNITS = ['KB', 'MB', 'GB', 'TB'];
        let size = N / 1024;
        let index = 0;
        while (size >= 1024 && index < UNITS.length - 1) 
        {
            size /= 1024;
            index++;
        }
        return `${size.toLocaleString(undefined, {
      maximumFractionDigits: 1
    })} ${UNITS[index]}`;
    };
    const GetDate = value => value ? new Intl.DateTimeFormat('en-PH', 
    {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Manila'
    }).format(new Date(Number(value))) : 'Not available';
    async function Busy(button, label, task) 
    {
        if (button.disabled) 
        {
            return;
        }
        const HTML = button.innerHTML;
        button.disabled = true;
        button.textContent = label;
        try 
        {
            return await task();
        }
        finally 
        {
            button.disabled = false;
            button.innerHTML = HTML;
        }
    }
    const Confirm = (title, message, action = 'Delete') => AT.confirmAction(message, title, action);
    document.addEventListener('click', event => 
    {
        const BUTTON = event.target.closest('[data-dialog-close]');
        if (BUTTON) 
        {
            Close(BUTTON.dataset.dialogClose);
        }
        const REVEAL = event.target.closest('[data-reveal]');
        if (REVEAL) 
        {
            const INPUT = GetElement(REVEAL.dataset.reveal);
            const IS_VISIBLE = INPUT.type === 'password';
            INPUT.type = IS_VISIBLE ? 'text' : 'password';
            REVEAL.textContent = IS_VISIBLE ? 'Hide' : 'Show';
            REVEAL.setAttribute('aria-pressed', String(IS_VISIBLE));
        }
    });
    return (
    {
        $: GetElement,
        esc: Esc,
        icon: Icon,
        error: GetError,
        open: Open,
        close: Close,
        busy: Busy,
        bytes: Bytes,
        date: GetDate,
        confirm: Confirm
    });
})();