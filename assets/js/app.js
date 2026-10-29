/**
 * Filename: app.js
 * Purpose: Coordinate app interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/**
 * DevAtlas :: app.js
 * Shared front-end utilities used by every module. SweetAlert2 is bundled locally.
 */

// Applied immediately (this script is loaded synchronously in <head>,
// before the body renders) so there's no flash of the wrong theme.
const DEV_ATLAS_THEME = (() => 
{
    const MEDIA = window.matchMedia('(prefers-color-scheme: dark)');
    const Valid = value => ['light', 'dark', 'system'].includes(value);
    let preference = 'system';
    try 
    {
        const STORED = localStorage.getItem('devatlas_theme') || localStorage.getItem('at_theme');
        if (Valid(STORED)) 
        {
            preference = STORED;
        }
        document.documentElement.classList.toggle('sidebar-collapsed', localStorage.getItem('at_sidebar_collapsed') === '1');
    }
    catch 
    {

    }
    function Apply() 
    {
        const RESOLVED = preference === 'system' ? MEDIA.matches ? 'dark' : 'light' : preference;
        document.documentElement.dataset.theme = RESOLVED;
        document.documentElement.dataset.themeMode = preference;
        window.dispatchEvent(new CustomEvent('devatlas:themechange', 
        {
            detail: 
            {
                preference,
                resolved: RESOLVED
            }
        }));
    }
    function GetSet(value, isPersist = true) 
    {
        if (!Valid(value)) 
        {
            return;
        }
        preference = value;
        if (isPersist) 
        {
            try 
            {
                localStorage.setItem('devatlas_theme', value);
            }
            catch 
            {

            }
        }
        Apply();
    }
    MEDIA.addEventListener('change', () => 
    {
        if (preference === 'system') 
        {
            Apply();
        }
    });
    window.addEventListener('storage', event => 
    {
        if (event.key === 'devatlas_theme') 
        {
            GetSet(Valid(event.newValue) ? event.newValue : 'system', false);
        }
    });
    Apply();
    return (
    {
        set: GetSet,
        preference: () => preference
    });
})();
const AT = (() => 
{
    const ICON_SPRITE = new URL(document.querySelector('meta[name="icon-sprite"]')?.content || '../vendor/lucide/sprite.svg', document.currentScript.src);
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
    async function Api(url, opts = 
    {

    }) 
    {
        const OPTIONS = 
        {
            method: opts.method || 'GET',
            headers: 
            {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            }
        };
        if (opts.body) 
        {
            OPTIONS.body = JSON.stringify(opts.body);
        }
        const RES = await fetch(url, OPTIONS);
        let data;
        try 
        {
            data = await RES.json();
        }
        catch (e) 
        {
            data = null;
        }
        if (!RES.ok) 
        {
            const MSG = data && data.error ? data.error : `Request failed (${RES.status})`;
            Toast(MSG, 'error');
            throw new Error(MSG);
        }
        return data;
    }
    const FEEDBACK_ICONS = 
    {
        success: 'circle-check',
        error: 'circle-x',
        warning: 'triangle-alert',
        info: 'info',
        question: 'circle-help'
    };
    let isFeedbackBusy = false;
    let isToastActive = false;
    const PENDING_TOASTS = [];
    function FeedbackOptions(type = 'info') 
    {
        const TONE = Object.hasOwn(FEEDBACK_ICONS, type) ? type : 'info';
        return (
        {
            icon: TONE,
            iconHtml: Icon(FEEDBACK_ICONS[TONE]),
            heightAuto: false,
            buttonsStyling: false,
            customClass: 
            {
                popup: 'atlas-alert',
                icon: 'atlas-alert-icon',
                confirmButton: 'btn btn-primary',
                cancelButton: 'btn',
                actions: 'atlas-alert-actions'
            },
            showClass: 
            {
                popup: '',
                backdrop: '',
                icon: ''
            },
            hideClass: 
            {
                popup: '',
                backdrop: '',
                icon: ''
            }
        });
    }
    function ShowNextToast() 
    {
        if (isFeedbackBusy || isToastActive || !PENDING_TOASTS.length) 
        {
            return;
        }
        isToastActive = true;
        const 
        {
            message: MESSAGE,
            type: TYPE
        }
        = PENDING_TOASTS.shift();
        const DIALOGS = [...document.querySelectorAll('dialog:modal')];
        Swal.fire(
        {
            ...FeedbackOptions(TYPE),
            target: DIALOGS.at(-1) || document.body,
            toast: true,
            position: 'top-end',
            titleText: MESSAGE,
            showConfirmButton: false,
            timer: 4500,
            timerProgressBar: true,
            returnFocus: false,
            didOpen: popup => 
            {
                popup.addEventListener('mouseenter', Swal.stopTimer);
                popup.addEventListener('mouseleave', Swal.resumeTimer);
            }
        }).finally(() => 
        {
            isToastActive = false;
            setTimeout(ShowNextToast, 0);
        });
    }
    function Toast(message, type = 'success') 
    {
        // A notification must never replace a confirmation that is awaiting a decision.
        PENDING_TOASTS.push(
        {
            message: String(message),
            type
        });
        if (PENDING_TOASTS.length > 5) 
        {
            PENDING_TOASTS.shift();
        }
        ShowNextToast();
    }
    async function FeedbackDialog(options) 
    {
        if (isFeedbackBusy) 
        {
            return (
            {
                isConfirmed: false
            });
        }
        isFeedbackBusy = true;
        if (isToastActive) 
        {
            Swal.close();
        }
        const PREVIOUS_FOCUS = document.activeElement;
        const HOST = document.createElement('dialog');
        HOST.className = 'atlas-alert-host';
        HOST.setAttribute('aria-label', 'Notification');
        document.body.appendChild(HOST);
        HOST.showModal();
        // A separate native top layer keeps confirmations above existing editors.
        HOST.addEventListener('cancel', event => 
        {
            event.preventDefault();
            Swal.clickCancel();
        });
        try 
        {
            return await Swal.fire(
            {
                ...options,
                target: HOST,
                returnFocus: false,
                keydownListenerCapture: true
            });
        }
        finally 
        {
            HOST.close();
            HOST.remove();
            isFeedbackBusy = false;
            if (PREVIOUS_FOCUS?.isConnected) 
            {
                PREVIOUS_FOCUS.focus(
                {
                    preventScroll: true
                });
            }
            setTimeout(ShowNextToast, 0);
        }
    }
    async function Alert(message, type = 'info', title = '') 
    {
        return FeedbackDialog(
        {
            ...FeedbackOptions(type),
            titleText: title || 
            {
                success: 'Completed',
                error: 'Unable to complete',
                warning: 'Please review',
                info: 'Information'
            }
            [type] || 'Information',
            text: String(message),
            confirmButtonText: 'OK'
        });
    }
    function OpenModal(id) 
    {
        document.getElementById(id)?.classList.add('open');
    }
    function CloseModal(id) 
    {
        document.getElementById(id)?.classList.remove('open');
    }
    async function ConfirmAction(message, title = 'Confirm action', action = 'Delete') 
    {
        const RESULT = await FeedbackDialog(
        {
            ...FeedbackOptions('warning'),
            titleText: String(title),
            text: String(message),
            showCancelButton: true,
            confirmButtonText: String(action),
            cancelButtonText: 'Cancel',
            focusCancel: true,
            allowOutsideClick: false,
            customClass: 
            {
                ...FeedbackOptions('warning').customClass,
                confirmButton: 'btn btn-danger'
            }
        });
        return RESULT.isConfirmed === true;
    }
    function Debounce(fn, wait = 300) 
    {
        let t;
        return (...args) => 
        {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), wait);
        };
    }
    async function CopyText(text) 
    {
        try 
        {
            await navigator.clipboard.writeText(text);
        }
        catch (e) 
        {
            // Fallback for older browsers / non-secure contexts.
            const TA = document.createElement('textarea');
            const PREVIOUS_FOCUS = document.activeElement;
            TA.value = text;
            TA.className = 'clipboard-fallback';
            TA.tabIndex = -1;
            const ACCOUNT_PANEL = document.getElementById('userMenuDropdown');
            (ACCOUNT_PANEL?.contains(PREVIOUS_FOCUS) ? ACCOUNT_PANEL : document.body).appendChild(TA);
            TA.select();
            try 
            {
                document.execCommand('copy');
            }
            catch (e2) 
            {
                /* ignore */
            }
            TA.remove();
            if (PREVIOUS_FOCUS?.isConnected) 
            {
                PREVIOUS_FOCUS.focus(
                {
                    preventScroll: true
                });
            }
        }
        Toast('Copied: ' + text);
    }
    function EscapeHtml(str) 
    {
        if (str === null || str === undefined) 
        {
            return '';
        }
        return String(str).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
    }
    function GetTheme() 
    {
        return document.documentElement.getAttribute('data-theme') || 'light';
    }
    function GetThemePreference() 
    {
        return DEV_ATLAS_THEME.preference();
    }
    function SetTheme(theme) 
    {
        DEV_ATLAS_THEME.set(theme);
        SyncThemeSwitch();
    }
    function ToggleTheme() 
    {
        SetTheme(GetTheme() === 'dark' ? 'light' : 'dark');
    }
    function Icon(name, css = '') 
    {
        if (!/^[a-z0-9-]+$/.test(name)) 
        {
            return '';
        }
        return '<svg class="lucide ' + EscapeHtml(css) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="' + EscapeHtml(ICON_SPRITE.href + '#' + name) + '"></use></svg>';
    }
    function SyncThemeSwitch() 
    {
        const PREFERENCE = GetThemePreference();
        const THEME = GetTheme();
        const SW = document.getElementById('themeSwitch');
        if (SW) 
        {
            SW.checked = THEME === 'dark';
        }
        document.querySelectorAll('[data-account-theme]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.accountTheme === PREFERENCE)));
        document.querySelectorAll('[data-theme-select]').forEach(select => select.value = PREFERENCE);
        const BTN = document.getElementById('themeToggleBtn');
        if (BTN) 
        {
            BTN.innerHTML = Icon(THEME === 'dark' ? 'sun' : 'moon');
        }
    }
    window.addEventListener('devatlas:themechange', SyncThemeSwitch);
    document.addEventListener('change', event => 
    {
        if (event.target.matches('[data-theme-select]')) 
        {
            SetTheme(event.target.value);
        }
    });
    function PositionUserMenu() 
    {
        const MENU = document.getElementById('userMenu');
        const PANEL = document.getElementById('userMenuDropdown');
        const TRIGGER = document.getElementById('userMenuTrigger');
        if (!MENU?.classList.contains('open') || !PANEL || !TRIGGER) 
        {
            return;
        }
        const WIDTH = document.documentElement.clientWidth;
        const HEIGHT = window.innerHeight;
        const GAP = 12;
        const ANCHOR = TRIGGER.getBoundingClientRect();
        if (!TRIGGER.getClientRects().length || ANCHOR.bottom < 0 || ANCHOR.top > HEIGHT) 
        {
            CloseUserMenu();
            return;
        }
        PANEL.style.maxHeight = Math.max(100, HEIGHT - GAP * 2) + 'px';
        let x;
        let y;
        let panelWidth = PANEL.offsetWidth;
        let panelHeight = PANEL.offsetHeight;
        if (WIDTH > 760 && ANCHOR.right + GAP + panelWidth <= WIDTH - GAP) 
        {
            x = ANCHOR.right + GAP;
            y = Math.max(GAP, Math.min(ANCHOR.bottom - panelHeight, HEIGHT - panelHeight - GAP));
        }
        else 
        {
            x = Math.max(GAP, Math.min(ANCHOR.left, WIDTH - panelWidth - GAP));
            const BELOW = HEIGHT - ANCHOR.bottom - GAP * 2;
            const ABOVE = ANCHOR.top - GAP * 2;
            if (Math.max(BELOW, ABOVE) < 180) 
            {
                y = GAP;
            }
            else if (BELOW >= panelHeight || BELOW >= ABOVE) 
            {
                PANEL.style.maxHeight = BELOW + 'px';
                y = ANCHOR.bottom + GAP;
            }
            else 
            {
                PANEL.style.maxHeight = ABOVE + 'px';
                y = Math.max(GAP, ANCHOR.top - GAP - PANEL.offsetHeight);
            }
        }
        PANEL.style.left = Math.round(x) + 'px';
        PANEL.style.top = Math.round(y) + 'px';
    }
    function ToggleUserMenu() 
    {
        const MENU = document.getElementById('userMenu');
        const PANEL = document.getElementById('userMenuDropdown');
        if (!MENU || !PANEL) 
        {
            return;
        }
        if (MENU.classList.contains('open')) 
        {
            CloseUserMenu(true);
            return;
        }
        PANEL.hidden = false;
        MENU.classList.add('open');
        document.getElementById('userMenuTrigger').setAttribute('aria-expanded', 'true');
        SyncThemeSwitch();
        PositionUserMenu();
        PANEL.querySelector('[data-account-focus]')?.focus(
        {
            preventScroll: true
        });
    }
    function CloseUserMenu(isRestoreFocus = false) 
    {
        const MENU = document.getElementById('userMenu');
        const WAS_OPEN = MENU?.classList.contains('open');
        MENU?.classList.remove('open');
        const PANEL = document.getElementById('userMenuDropdown');
        if (PANEL) 
        {
            PANEL.hidden = true;
        }
        const TRIGGER = document.getElementById('userMenuTrigger');
        TRIGGER?.setAttribute('aria-expanded', 'false');
        if (isRestoreFocus && WAS_OPEN) 
        {
            TRIGGER?.focus(
            {
                preventScroll: true
            });
        }
    }
    function SyncSidebarToggle() 
    {
        const COLLAPSED = document.documentElement.classList.contains('sidebar-collapsed');
        const BUTTON = document.getElementById('sidebarToggle');
        if (!BUTTON) 
        {
            return;
        }
        BUTTON.setAttribute('aria-expanded', String(!COLLAPSED));
        const LABEL = COLLAPSED ? 'Expand sidebar labels' : 'Collapse sidebar labels';
        BUTTON.setAttribute('aria-label', LABEL);
        BUTTON.title = LABEL;
    }
    function ToggleSidebar() 
    {
        const COLLAPSED = document.documentElement.classList.toggle('sidebar-collapsed');
        try 
        {
            localStorage.setItem('at_sidebar_collapsed', COLLAPSED ? '1' : '0');
        }
        catch 
        {
            /* Keep the current layout for this visit. */
        }
        SyncSidebarToggle();
        CloseUserMenu();
        CloseModuleSearch();
    }
    const MODULE_SEARCH = 
    {
        catalog: [],
        matches: [],
        modules: [],
        records: [],
        active: -1,
        request: 0,
        timer: null,
        controller: null,
        query: ''
    };
    function InitModuleSearch() 
    {
        const ALIASES = 
        {
            wiki: 'documents wiki documentation help guide manual roadmap',
            holidays: 'holidays calendar working days',
            applications: 'application catalog portfolio ownership systems',
            permissions: 'roles permissions access control',
            notifications: 'notifications reminders inbox alerts',
            bookmarks: 'quick links bookmarks resources',
            diagrams: 'diagram entity relationship erd',
            codereview: 'code review qms codesniffer source diagnostics security',
            proginventory: 'program inventory source files',
            datadict: 'data dictionary database tables fields',
            usermgmt: 'users accounts people',
            settings: 'parameters settings configuration',
            audit: 'audit logs history activity user actions',
            logs: 'error logs diagnostics',
            directory: 'call directory contacts telephone',
            organization: 'organization structure departments'
        };
        MODULE_SEARCH.catalog = [...document.querySelectorAll('#sidebarNav .nav-section a')].map(link => 
        {
            const NAME = link.querySelector('.nav-label')?.textContent.trim() || link.textContent.trim();
            const GROUP = link.closest('.nav-section')?.querySelector('.nav-heading')?.textContent.trim() || 'Workspace';
            const ICON = link.querySelector('.nav-icon');
            const MODULE = new URL(link.href).pathname.match(/\/modules\/([^/]+)\//)?.[1] || 'dashboard';
            link.setAttribute('aria-label', NAME);
            link.title = NAME;
            if (ICON) 
            {
                ICON.setAttribute('aria-hidden', 'true');
            }
            if (link.classList.contains('active')) 
            {
                link.setAttribute('aria-current', 'page');
            }
            return (
            {
                name: NAME,
                group: GROUP,
                icon: ICON?.querySelector('use')?.getAttribute('href')?.split('#')[1] || 'layout-dashboard',
                href: link.href,
                current: link.classList.contains('active'),
                terms: (NAME + ' ' + (ALIASES[MODULE] || '')).toLocaleLowerCase()
            });
        });
    }
    function CloseModuleSearch() 
    {
        ++MODULE_SEARCH.request;
        clearTimeout(MODULE_SEARCH.timer);
        MODULE_SEARCH.controller?.abort();
        const PANEL = document.getElementById('moduleSearchPanel');
        const INPUT = document.getElementById('moduleSearch');
        if (PANEL) 
        {
            PANEL.hidden = true;
        }
        if (INPUT) 
        {
            INPUT.setAttribute('aria-expanded', 'false');
            INPUT.removeAttribute('aria-activedescendant');
        }
        MODULE_SEARCH.active = -1;
    }
    function PositionModuleSearch() 
    {
        const PANEL = document.getElementById('moduleSearchPanel');
        const WRAP = document.getElementById('moduleSearchWrap');
        if (!PANEL || PANEL.hidden || !WRAP) 
        {
            return;
        }
        PANEL.style.maxHeight = Math.max(72, Math.min(440, innerHeight - WRAP.getBoundingClientRect().bottom - 21)) + 'px';
    }
    function SearchModules(value) 
    {
        const PANEL = document.getElementById('moduleSearchPanel');
        const INPUT = document.getElementById('moduleSearch');
        if (!PANEL || !INPUT) 
        {
            return;
        }
        if (!MODULE_SEARCH.catalog.length) 
        {
            InitModuleSearch();
        }
        const QUERY = value.trim().toLocaleLowerCase();
        const WORDS = QUERY.split(/\s+/).filter(Boolean);
        MODULE_SEARCH.modules = MODULE_SEARCH.catalog.filter(item => WORDS.every(word => item.terms.includes(word)));
        if (QUERY) 
        {
            MODULE_SEARCH.modules.sort((a, b) => Number(!a.name.toLocaleLowerCase().startsWith(QUERY)) - Number(!b.name.toLocaleLowerCase().startsWith(QUERY)));
        }
        MODULE_SEARCH.records = [];
        MODULE_SEARCH.query = QUERY;
        MODULE_SEARCH.matches = MODULE_SEARCH.modules;
        MODULE_SEARCH.active = -1;
        INPUT.removeAttribute('aria-activedescendant');
        RenderWorkspaceSearch(QUERY.length >= 2 ? 'Searching records…' : QUERY ? 'Type 2 characters to search records' : '');
        PANEL.hidden = false;
        INPUT.setAttribute('aria-expanded', 'true');
        const HEADER = document.getElementById('appTopbar');
        const BOX = INPUT.getBoundingClientRect();
        if (HEADER && innerHeight - BOX.bottom < 180 && HEADER.getBoundingClientRect().top > 0) 
        {
            scrollBy(0, HEADER.getBoundingClientRect().top);
        }
        PositionModuleSearch();
        const REQUEST = ++MODULE_SEARCH.request;
        clearTimeout(MODULE_SEARCH.timer);
        MODULE_SEARCH.controller?.abort();
        if (QUERY.length < 2) 
        {
            return;
        }
        MODULE_SEARCH.timer = setTimeout(async () => 
        {
            const ENDPOINT = document.getElementById('moduleSearchWrap')?.dataset.recordSearch;
            if (!ENDPOINT) 
            {
                return;
            }
            const CONTROLLER = new AbortController();
            MODULE_SEARCH.controller = CONTROLLER;
            try 
            {
                const FRAGMENT = document.getElementById('recordSearchFragment');
                CONTROLLER.signal.addEventListener('abort', () => htmx.trigger(FRAGMENT, 'htmx:abort'),
                {
                    once: true
                });
                await htmx.ajax('GET', ENDPOINT + '?q=' + encodeURIComponent(value.trim()),
                {
                    source: FRAGMENT,
                    target: FRAGMENT,
                    swap: 'innerHTML',
                    headers:
                    {
                        Accept: 'text/html'
                    }
                });
                const DATA =
                {
                    results: [...FRAGMENT.querySelectorAll('[data-record]')].map(link =>
                    (
                    {
                        title: link.dataset.title,
                        detail: link.dataset.detail,
                        type: link.dataset.type,
                        icon: link.dataset.icon,
                        href: link.getAttribute('href')
                    }))
                };
                if (REQUEST !== MODULE_SEARCH.request || PANEL.hidden) 
                {
                    return;
                }
                MODULE_SEARCH.records = DATA.results.map(row => (
                {
                    name: row.title,
                    group: row.type + ' · ' + row.detail,
                    icon: row.icon,
                    href: row.href,
                    record: true
                }));
                RenderWorkspaceSearch();
            }
            catch (error) 
            {
                if (error.name !== 'AbortError' && REQUEST === MODULE_SEARCH.request && !PANEL.hidden) 
                {
                    RenderWorkspaceSearch('Record search is unavailable. Try again.');
                }
            }
        }, 250);
    }
    function RenderWorkspaceSearch(note = '') 
    {
        MODULE_SEARCH.matches = [...MODULE_SEARCH.modules, ...MODULE_SEARCH.records];
        document.getElementById('moduleSearchResults').innerHTML = MODULE_SEARCH.matches.map((item, index) => '<a class="module-search-option" id="moduleResult' + index + '" role="option" aria-selected="' + String(index === MODULE_SEARCH.active) + '" tabindex="-1" href="' + EscapeHtml(item.href) + '" data-module-index="' + index + '"' + (item.record ? ' data-record-result' : '') + '><span class="nav-icon" aria-hidden="true">' + Icon(item.icon) + '</span><span><strong>' + EscapeHtml(item.name) + '</strong><small>' + EscapeHtml(item.group) + '</small></span>' + (item.current ? '<em>Current page</em>' : '') + '</a>').join('');
        document.getElementById('moduleSearchStatus').textContent = note || (MODULE_SEARCH.query ? MODULE_SEARCH.modules.length + ' modules · ' + MODULE_SEARCH.records.length + ' records' : 'All modules · ' + MODULE_SEARCH.modules.length);
        document.getElementById('moduleSearchEmpty').hidden = MODULE_SEARCH.matches.length > 0 || !!note;
    }
    function SelectModuleResult(index, isScroll = false) 
    {
        if (!MODULE_SEARCH.matches.length) 
        {
            return;
        }
        MODULE_SEARCH.active = (index + MODULE_SEARCH.matches.length) % MODULE_SEARCH.matches.length;
        document.querySelectorAll('#moduleSearchResults [role=option]').forEach((option, i) => option.setAttribute('aria-selected', String(i === MODULE_SEARCH.active)));
        const OPTION = document.getElementById('moduleResult' + MODULE_SEARCH.active);
        const INPUT = document.getElementById('moduleSearch');
        INPUT.setAttribute('aria-activedescendant', OPTION.id);
        if (isScroll) 
        {
            const LIST = document.getElementById('moduleSearchResults');
            const ROW = OPTION.getBoundingClientRect();
            const BOX = LIST.getBoundingClientRect();
            if (ROW.top < BOX.top) 
            {
                LIST.scrollTop -= BOX.top - ROW.top;
            }
            else if (ROW.bottom > BOX.bottom) 
            {
                LIST.scrollTop += ROW.bottom - BOX.bottom;
            }
        }
    }
    function ModuleSearchKeydown(event) 
    {
        const PANEL = document.getElementById('moduleSearchPanel');
        if (!PANEL) 
        {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') 
        {
            event.preventDefault();
            if (PANEL.hidden) 
            {
                SearchModules(event.target.value);
            }
            SelectModuleResult(MODULE_SEARCH.active < 0 ? event.key === 'ArrowDown' ? 0 : MODULE_SEARCH.matches.length - 1 : MODULE_SEARCH.active + (event.key === 'ArrowDown' ? 1 : -1), true);
        }
        else if (event.key === 'Enter') 
        {
            event.preventDefault();
            if (PANEL.hidden) 
            {
                SearchModules(event.target.value);
            }
            const ITEM = MODULE_SEARCH.matches[Math.max(0, MODULE_SEARCH.active)];
            if (ITEM) 
            {
                CloseModuleSearch();
                location.href = ITEM.href;
            }
        }
        else if (event.key === 'Escape') 
        {
            event.preventDefault();
            CloseModuleSearch();
        }
        else if (event.key === 'Tab') 
        {
            CloseModuleSearch();
        }
    }
    async function UploadAvatar(inputEl) 
    {
        const FILE = inputEl.files[0];
        inputEl.value = '';
        const BUTTON = document.getElementById('avatarUploadButton');
        const LABEL = document.getElementById('avatarUploadLabel');
        const FEEDBACK = document.getElementById('avatarUploadFeedback');
        if (!FILE || BUTTON?.disabled) 
        {
            return;
        }
        const Report = (message, tone) => 
        {
            if (FEEDBACK) 
            {
                FEEDBACK.hidden = false;
                FEEDBACK.textContent = message;
                FEEDBACK.dataset.tone = tone;
            }
        };
        if (FILE.size > 2 * 1024 * 1024) 
        {
            Report('Choose an image up to 2 MB.', 'error');
            return;
        }
        if (FILE.type && !['image/png', 'image/jpeg', 'image/gif', 'image/webp'].includes(FILE.type)) 
        {
            Report('Choose a JPG, PNG, GIF or WebP image.', 'error');
            return;
        }
        if (BUTTON) 
        {
            BUTTON.disabled = true;
            BUTTON.setAttribute('aria-busy', 'true');
        }
        if (LABEL) 
        {
            LABEL.textContent = 'Uploading photo…';
        }
        if (FEEDBACK) 
        {
            FEEDBACK.textContent = '';
            FEEDBACK.hidden = true;
        }
        const FORM_DATA = new FormData();
        FORM_DATA.append('file', FILE);
        try 
        {
            const RES = await fetch(window.AT_AVATAR_UPLOAD_URL, 
            {
                method: 'POST',
                headers: 
                {
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: FORM_DATA
            });
            const DATA = await RES.json().catch(() => (
            {

            }));
            if (!RES.ok) 
            {
                throw new Error(DATA.error || 'The photo could not be uploaded. Please try again.');
            }
            if (typeof DATA.path !== 'string' || !DATA.path) 
            {
                throw new Error('The server did not return a photo address. Please try again.');
            }
            const PATH = new URL(DATA.path, location.href);
            if (PATH.origin !== location.origin || !['http:', 'https:'].includes(PATH.protocol)) 
            {
                throw new Error('The server returned an invalid photo address.');
            }
            PATH.searchParams.set('v', Date.now());
            document.querySelectorAll('#sidebarAvatar, #dropdownAvatar').forEach(el => 
            {
                const IMG = document.createElement('img');
                IMG.src = PATH.href;
                IMG.alt = '';
                el.replaceChildren(IMG);
            });
            Report('Profile photo updated.', 'success');
            Toast('Profile photo updated.');
        }
        catch (e) 
        {
            Report(e.message || 'The photo could not be uploaded. Please try again.', 'error');
            if (!document.getElementById('userMenu')?.classList.contains('open')) 
            {
                Toast(e.message, 'error');
            }
        }
        finally 
        {
            if (BUTTON) 
            {
                BUTTON.disabled = false;
                BUTTON.removeAttribute('aria-busy');
            }
            if (LABEL) 
            {
                LABEL.textContent = 'Change profile photo';
            }
            PositionUserMenu();
        }
    }
    let topbarClockFormatter;
    function UpdateTopbarClock() 
    {
        const EL = document.getElementById('topbarClock');
        if (!EL) 
        {
            return;
        }
        const NOW = new Date();
        try 
        {
            topbarClockFormatter ||= new Intl.DateTimeFormat('en-US', 
            {
                timeZone: EL.dataset.timeZone || undefined,
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
            const PARTS = Object.fromEntries(topbarClockFormatter.formatToParts(NOW).map(part => [part.type, part.value]));
            EL.textContent = `${PARTS.hour}:${PARTS.minute} ${PARTS.dayPeriod}`;
            const DATE = document.getElementById('topbarDate');
            if (DATE) 
            {
                DATE.textContent = `${PARTS.weekday}, ${PARTS.month} ${PARTS.day}, ${PARTS.year}`;
            }
            EL.dateTime = NOW.toISOString();
            EL.title = 'Time zone: ' + topbarClockFormatter.resolvedOptions().timeZone;
        }
        catch 
        {
            EL.textContent = NOW.toLocaleString();
        }
    }
    document.addEventListener('DOMContentLoaded', () => 
    {
        SyncThemeSwitch();
        SyncSidebarToggle();
        InitModuleSearch();
        const SEARCH_WRAP = document.getElementById('moduleSearchWrap');
        if (SEARCH_WRAP) 
        {
            SEARCH_WRAP.addEventListener('click', event => 
            {
                if (event.target.closest('[data-module-index]')) 
                {
                    CloseModuleSearch();
                }
            });
            SEARCH_WRAP.addEventListener('pointermove', event => 
            {
                const OPTION = event.target.closest('[data-module-index]');
                if (OPTION) 
                {
                    SelectModuleResult(Number(OPTION.dataset.moduleIndex));
                }
            });
            document.getElementById('moduleSearch').addEventListener('search', event => 
            {
                if (document.activeElement === event.target) 
                {
                    SearchModules(event.target.value);
                }
            });
        }
        const TOPBAR = document.getElementById('appTopbar');
        if (TOPBAR) 
        {
            const SyncTopbarHeight = () => document.documentElement.style.setProperty('--app-topbar-height', Math.ceil(TOPBAR.getBoundingClientRect().height) + 'px');
            SyncTopbarHeight();
            if (window.ResizeObserver) 
            {
                new ResizeObserver(SyncTopbarHeight).observe(TOPBAR);
            }
            else 
            {
                window.addEventListener('resize', SyncTopbarHeight);
            }
        }
        const MENU = document.getElementById('userMenu');
        if (MENU) 
        {
            MENU.addEventListener('click', event => 
            {
                const COPY = event.target.closest('[data-account-copy]');
                if (COPY) 
                {
                    CopyText(COPY.dataset.accountCopy);
                }
            });
            MENU.addEventListener('toggle', PositionUserMenu, true);
            document.getElementById('userMenuTrigger').addEventListener('keydown', event => 
            {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') 
                {
                    event.preventDefault();
                    if (!MENU.classList.contains('open')) 
                    {
                        ToggleUserMenu();
                    }
                    const ACTIONS = [...document.querySelectorAll('#userMenuDropdown :is(button,a,summary):not([disabled])')].filter(el => el.getClientRects().length);
                    (event.key === 'ArrowUp' ? ACTIONS[ACTIONS.length - 1] : document.querySelector('#userMenuDropdown [data-account-focus]'))?.focus(
                    {
                        preventScroll: true
                    });
                }
            });
            if (window.ResizeObserver) 
            {
                new ResizeObserver(() => PositionUserMenu()).observe(document.getElementById('userMenuDropdown'));
            }
        }
        UpdateTopbarClock();
        setInterval(UpdateTopbarClock, 1000);
    });

    // Close the user menu when clicking outside it, or on Escape.
    document.addEventListener('click', e => 
    {
        const SEARCH_WRAP = document.getElementById('moduleSearchWrap');
        if (SEARCH_WRAP && !SEARCH_WRAP.contains(e.target)) 
        {
            CloseModuleSearch();
        }
        const MENU = document.getElementById('userMenu');
        if (MENU && MENU.classList.contains('open') && !MENU.contains(e.target)) 
        {
            CloseUserMenu();
        }
    });
    document.addEventListener('keydown', e => 
    {
        if (e.key === 'Escape' && document.getElementById('userMenu')?.classList.contains('open')) 
        {
            e.preventDefault();
            CloseUserMenu(true);
        }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') 
        {
            e.preventDefault();
            const INPUT = document.getElementById('moduleSearch');
            if (INPUT) 
            {
                INPUT.focus();
                SearchModules(INPUT.value);
                INPUT.select();
            }
        }
    });
    document.addEventListener('focusin', event => 
    {
        const MENU = document.getElementById('userMenu');
        if (MENU?.classList.contains('open') && !MENU.contains(event.target)) 
        {
            CloseUserMenu();
        }
    });
    document.addEventListener('focusin', event => 
    {
        const WRAP = document.getElementById('moduleSearchWrap');
        if (WRAP && !WRAP.contains(event.target)) 
        {
            CloseModuleSearch();
        }
    });
    window.addEventListener('resize', PositionModuleSearch);
    window.addEventListener('scroll', PositionModuleSearch, true);
    window.addEventListener('resize', PositionUserMenu);
    window.addEventListener('scroll', PositionUserMenu, true);

    // Close modals on overlay click / Escape
    document.addEventListener('click', e => 
    {
        if (e.target.classList && e.target.classList.contains('modal-overlay')) 
        {
            e.target.classList.remove('open');
        }
    });
    document.addEventListener('keydown', e => 
    {
        if (e.key === 'Escape') 
        {
            document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
        }
    });
    return (
    {
        api: Api,
        toast: Toast,
        alert: Alert,
        openModal: OpenModal,
        closeModal: CloseModal,
        confirmAction: ConfirmAction,
        debounce: Debounce,
        escapeHtml: EscapeHtml,
        toggleTheme: ToggleTheme,
        setTheme: SetTheme,
        getTheme: GetTheme,
        getThemePreference: GetThemePreference,
        icon: Icon,
        copyText: CopyText,
        toggleUserMenu: ToggleUserMenu,
        closeUserMenu: CloseUserMenu,
        toggleSidebar: ToggleSidebar,
        searchModules: SearchModules,
        moduleSearchKeydown: ModuleSearchKeydown,
        uploadAvatar: UploadAvatar
    });
})();