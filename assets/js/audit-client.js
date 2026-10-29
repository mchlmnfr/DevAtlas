/**
 * Filename: audit-client.js
 * Purpose: Coordinate audit client interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* Supplemental browser activity. Never send field values, labels, file names or copied text. */
(() => 
{
    'use strict';

    if (!window.AT_AUDIT) 
    {
        return;
    }
    const ENDPOINT = window.AT_AUDIT.endpoint;
    const TOKEN = document.querySelector('meta[name=csrf-token]')?.content;
    if (!ENDPOINT || !TOKEN) 
    {
        return;
    }
    const QUEUE = [];
    const TIMERS = new Map();
    let isSending = false;
    const Uuid = () => 
    {
        if (window.crypto?.randomUUID) 
        {
            return crypto.randomUUID();
        }
        const DATA = new Uint8Array(16);
        if (window.crypto?.getRandomValues) 
        {
            crypto.getRandomValues(DATA);
        }
        else 
        {
            DATA.forEach((_, i) => DATA[i] = Math.floor(Math.random() * 256));
        }
        DATA[6] = DATA[6] & 15 | 64;
        DATA[8] = DATA[8] & 63 | 128;
        const H = [...DATA].map(v => v.toString(16).padStart(2, '0')).join('');
        return H.slice(0, 8) + '-' + H.slice(8, 12) + '-' + H.slice(12, 16) + '-' + H.slice(16, 20) + '-' + H.slice(20);
    };
    const Control = element => 
    {
        if (element.id && /^[A-Za-z][A-Za-z0-9_.:-]{0,79}$/.test(element.id)) 
        {
            return element.id;
        }
        for (const KEY of ['data-action', 'data-dd', 'data-engine', 'data-mode', 'data-account-theme', 'data-dialog-close']) 
        {
            const VALUE = element.getAttribute(KEY);
            if (VALUE && /^[a-z][a-z_-]{0,39}$/i.test(VALUE)) 
            {
                return VALUE;
            }
        }
        // Names of these attributes describe the action; their values may be secrets.
        for (const KEY of ['data-copy', 'data-pin', 'data-edit', 'data-delete', 'data-table', 'data-edit-column', 'data-edit-edge', 'data-delete-edge']) 
        {
            if (element.hasAttribute(KEY)) 
            {
                return KEY.slice(5);
            }
        }
        if (element.closest('#moduleSearchResults')) 
        {
            return 'moduleSearchResult';
        }
        if (element.hasAttribute('data-audit-event')) 
        {
            return 'viewActivityDetails';
        }
        if (element.closest('#sidebarNav')) 
        {
            return 'moduleNavigation';
        }
        return element.tagName.toLowerCase();
    };
    function Add(kind, element) 
    {
        if (!element || QUEUE.length >= 100) 
        {
            return;
        }
        QUEUE.push(
        {
            id: Uuid(),
            kind,
            control: Control(element)
        });
        if (QUEUE.length >= 20) 
        {
            Flush();
        }
    }
    async function Flush() 
    {
        if (isSending || !QUEUE.length) 
        {
            return;
        }
        const EVENTS = QUEUE.splice(0, 20);
        isSending = true;
        try 
        {
            const RESPONSE = await fetch(ENDPOINT, 
            {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: 
                {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': TOKEN
                },
                body: JSON.stringify(
                {
                    page: location.pathname,
                    events: EVENTS
                })
            });
            if (RESPONSE.status >= 500) 
            {
                QUEUE.unshift(...EVENTS);
            }
        }
        catch 
        {
            if (QUEUE.length < 80) 
            {
                QUEUE.unshift(...EVENTS);
            }
        }
        finally 
        {
            isSending = false;
        }
    }
    document.addEventListener('click', event => 
    {
        const ELEMENT = event.target.closest?.('button,a,summary,[role="button"]');
        if (ELEMENT && !ELEMENT.closest('[data-audit-ignore]')) 
        {
            Add('click', ELEMENT);
        }
    }, true);
    document.addEventListener('change', event => 
    {
        if (event.target.matches('select,input[type=checkbox],input[type=radio],input[type=file]')) 
        {
            Add('change', event.target);
        }
    }, true);
    document.addEventListener('input', event => 
    {
        if (!event.target.matches('input[type=search]')) 
        {
            return;
        }
        const ELEMENT = event.target;
        clearTimeout(TIMERS.get(ELEMENT));
        TIMERS.set(ELEMENT, setTimeout(() => 
        {
            TIMERS.delete(ELEMENT);
            Add('search', ELEMENT);
        }, 900));
    }, true);
    document.addEventListener('submit', event => Add('submit', event.target), true);
    document.addEventListener('copy', event => Add('copy', event.target.closest?.('[id]') || document.body), true);
    setInterval(Flush, 1500);
    document.addEventListener('visibilitychange', () => 
    {
        if (document.visibilityState === 'hidden') 
        {
            Flush();
        }
    });
    window.addEventListener('pagehide', Flush);
})();