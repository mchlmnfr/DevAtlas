/**
 * Filename: dashboard.js
 * Purpose: Present the dashboard greeting and account-owned widget customization.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Preview and persist widget order, visibility, spacing and interface motion.
 */
(() =>
{
    const GREETING = document.getElementById('greetingText');
    if (!GREETING)
    {
        return;
    }
    function Greet()
    {
        const NOW = new Date();
        const HOUR = Number(new Intl.DateTimeFormat('en-PH',
        {
            timeZone: 'Asia/Manila',
            hour: 'numeric',
            hourCycle: 'h23'
        }).format(NOW));
        GREETING.textContent = (HOUR < 12 ? 'Good morning' : HOUR < 18 ? 'Good afternoon' : 'Good evening') + ', ' + GREETING.dataset.greetingName;
        document.getElementById('greetingDate').textContent = NOW.toLocaleDateString('en-PH',
        {
            timeZone: 'Asia/Manila',
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }
    Greet();
    setInterval(Greet, 60000);
})();


/* Account-owned dashboard personalization. Changes preview locally until Save succeeds. */
(() => {
    'use strict';
    const root = document.getElementById('actionDashboard');
    const stateElement = document.getElementById('dashboardPreferenceState');
    if (!root || !stateElement) return;
    const byId = id => document.getElementById(id);
    const dialog = byId('dashboardCustomizer');
    const grid = byId('dashboardGrid');
    const options = byId('dashboardWidgetOptions');
    const cards = new Map([...root.querySelectorAll('[data-dashboard-widget]')].map(el => [el.dataset.dashboardWidget, el]));
    const clone = value => JSON.parse(JSON.stringify(value));
    let saved = JSON.parse(stateElement.textContent);
    let draft = clone(saved.preferences);
    let busy = false;
    let dragged = null;
    let keepOnClose = false;
    let opener = null;
    const defaults = () => ({ widgets: ['metrics', 'personal', 'approvals', 'team', 'renewals', 'records', 'links'].map(id => ({ id, visible: true })), density: 'comfortable', motion: 'standard' });
    const available = () => draft.widgets.filter(item => cards.has(item.id));
    const message = (text = '') => { byId('dashboardCustomizeError').textContent = text; byId('dashboardCustomizeError').hidden = !text; };

    function apply(preferences, animate = false) {
        const before = new Map();
        if (animate && window.DevAtlasMotion?.mode() !== 'off') cards.forEach((card, id) => { if (!card.hidden) before.set(id, card.getBoundingClientRect()); });
        preferences.widgets.forEach((widget, index) => {
            const card = cards.get(widget.id);
            if (!card) return;
            card.hidden = !widget.visible;
            card.style.order = String(index);
            grid?.append(card); // Visual, keyboard and screen-reader order stay together.
        });
        root.dataset.density = preferences.density;
        if (window.DevAtlasMotion) window.DevAtlasMotion.set(preferences.motion);
        else document.documentElement.dataset.motion = preferences.motion;
        if (byId('dashboardNoWidgets')) byId('dashboardNoWidgets').hidden = [...cards.values()].some(card => !card.hidden);
        if (animate && window.DevAtlasMotion?.mode() !== 'off') before.forEach((rect, id) => {
            const card = cards.get(id);
            if (card.hidden || typeof card.animate !== 'function') return;
            const after = card.getBoundingClientRect();
            const x = rect.left - after.left, y = rect.top - after.top;
            if (Math.abs(x) + Math.abs(y) > 1) card.animate([{ transform: `translate(${x}px, ${y}px)` }, { transform: 'none' }], { duration: 180, easing: 'ease-out' });
        });
    }

    function renderOptions(focus) {
        options.replaceChildren();
        const widgets = available();
        widgets.forEach((widget, index) => {
            const label = cards.get(widget.id).dataset.widgetLabel;
            const row = document.createElement('li'); row.className = 'dashboard-widget-option'; row.dataset.widgetId = widget.id;
            const grip = document.createElement('span'); grip.className = 'dashboard-drag-grip'; grip.textContent = '⠿'; grip.draggable = true; grip.setAttribute('aria-hidden', 'true');
            const text = document.createElement('label');
            const input = document.createElement('input'); input.type = 'checkbox'; input.checked = widget.visible; input.dataset.widgetToggle = widget.id; input.id = 'widget-toggle-' + widget.id;
            const name = document.createElement('span'); name.textContent = label;
            text.append(input, name);
            const controls = document.createElement('span'); controls.className = 'dashboard-reorder';
            for (const [direction, symbol, disabled] of [['up', '↑', index === 0], ['down', '↓', index === widgets.length - 1]]) {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-sm'; button.dataset.widgetMove = direction; button.dataset.widgetId = widget.id; button.textContent = symbol; button.disabled = disabled;
                button.setAttribute('aria-label', `Move ${label} ${direction}`); controls.append(button);
            }
            row.append(grip, text, controls); options.append(row);
        });
        byId('dashboardVisibleCount').textContent = `${widgets.filter(item => item.visible).length} of ${widgets.length} visible`;
        byId('dashboardDensity').value = draft.density;
        byId('dashboardMotion').value = draft.motion;
        if (focus) {
            const control = options.querySelector(`[data-widget-id="${focus.id}"][data-widget-move="${focus.direction}"]:not(:disabled)`)
                || options.querySelector(`[data-widget-id="${focus.id}"][data-widget-move]:not(:disabled)`)
                || byId('widget-toggle-' + focus.id);
            control?.focus();
        }
    }

    function setBusy(value) {
        busy = value;
        byId('dashboardCustomizeFields').disabled = value;
        byId('dashboardCustomizeSave').disabled = value || !saved.storage_ready;
        for (const id of ['dashboardCustomizeDefaults', 'dashboardCustomizeReload', 'dashboardCustomizeCancel', 'dashboardCustomizeClose']) byId(id).disabled = value;
        byId('dashboardCustomizeSave').textContent = value ? 'Saving…' : 'Save changes';
    }

    async function request(body) {
        const response = await fetch(root.dataset.preferencesUrl, { method: body ? 'POST' : 'GET', credentials: 'same-origin',
            headers: body ? { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' } : {},
            body: body ? JSON.stringify(body) : undefined });
        const type = response.headers.get('content-type') || '';
        if (!type.includes('json')) throw new Error('Sign in again, then reload your dashboard to save your preferences.');
        const result = await response.json();
        if (!response.ok || result.error) {
            if (response.status === 409) byId('dashboardCustomizeReload').hidden = false;
            throw new Error(result.error || 'Could not save workspace preferences.');
        }
        return result;
    }

    function open(event) {
        if (dialog.open) return;
        opener = event?.currentTarget || byId('dashboardCustomize');
        draft = clone(saved.preferences); keepOnClose = false; message();
        byId('dashboardCustomizeReload').hidden = true;
        byId('dashboardCustomizeNotice').textContent = 'Changes preview immediately. Save to keep them for your account.';
        renderOptions(); setBusy(false); dialog.showModal();
    }
    byId('dashboardCustomize').addEventListener('click', open);
    root.querySelectorAll('[data-dashboard-customize]').forEach(button => button.addEventListener('click', open));
    for (const id of ['dashboardCustomizeClose', 'dashboardCustomizeCancel']) byId(id).addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => { if (!keepOnClose) apply(saved.preferences); opener?.focus(); });
    dialog.addEventListener('click', event => {
        const rect = dialog.getBoundingClientRect();
        if (event.target === dialog && !busy && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
    });
    options.addEventListener('change', event => {
        const id = event.target.dataset.widgetToggle;
        const widget = draft.widgets.find(item => item.id === id);
        if (!widget || busy) return;
        widget.visible = event.target.checked; apply(draft);
        byId('dashboardVisibleCount').textContent = `${available().filter(item => item.visible).length} of ${available().length} visible`;
    });
    options.addEventListener('click', event => {
        const button = event.target.closest('[data-widget-move]');
        if (!button || busy) return;
        const id = button.dataset.widgetId, direction = button.dataset.widgetMove;
        const widgets = available(), from = widgets.findIndex(item => item.id === id), to = from + (direction === 'up' ? -1 : 1);
        if (to < 0 || to >= widgets.length) return;
        const a = draft.widgets.findIndex(item => item.id === id), b = draft.widgets.findIndex(item => item.id === widgets[to].id);
        [draft.widgets[a], draft.widgets[b]] = [draft.widgets[b], draft.widgets[a]];
        apply(draft, true); renderOptions({ id, direction });
        byId('dashboardCustomizeNotice').textContent = `${cards.get(id).dataset.widgetLabel} moved ${direction}.`;
    });
    options.addEventListener('dragstart', event => {
        if (busy || !event.target.closest('.dashboard-drag-grip')) { event.preventDefault(); return; }
        dragged = event.target.closest('[data-widget-id]').dataset.widgetId;
        event.dataTransfer.setData('text/plain', dragged); event.dataTransfer.effectAllowed = 'move';
        event.target.closest('li').classList.add('is-dragging');
    });
    options.addEventListener('dragover', event => {
        const row = event.target.closest('li');
        if (!dragged || !row) return;
        event.preventDefault(); event.dataTransfer.dropEffect = 'move';
        options.querySelectorAll('.is-drop-target').forEach(el => el.classList.remove('is-drop-target')); row.classList.add('is-drop-target');
    });
    options.addEventListener('drop', event => {
        const row = event.target.closest('li');
        if (!dragged || !row || busy) return;
        event.preventDefault();
        const target = row.dataset.widgetId;
        if (target !== dragged && cards.has(target)) {
            const sourceIndex = draft.widgets.findIndex(item => item.id === dragged);
            const [item] = draft.widgets.splice(sourceIndex, 1);
            const targetIndex = draft.widgets.findIndex(widget => widget.id === target);
            const after = event.clientY > row.getBoundingClientRect().top + row.getBoundingClientRect().height / 2;
            draft.widgets.splice(targetIndex + (after ? 1 : 0), 0, item); apply(draft, true); renderOptions();
        }
        dragged = null;
    });
    options.addEventListener('dragend', () => { dragged = null; options.querySelectorAll('.is-dragging, .is-drop-target').forEach(el => el.classList.remove('is-dragging', 'is-drop-target')); });
    for (const [id, key] of [['dashboardDensity', 'density'], ['dashboardMotion', 'motion']]) byId(id).addEventListener('change', event => { draft[key] = event.target.value; apply(draft); });
    byId('dashboardCustomizeDefaults').addEventListener('click', () => { draft = defaults(); apply(draft, true); renderOptions(); message(); byId('dashboardCustomizeNotice').textContent = 'Defaults are previewed. Save changes to keep them.'; });
    byId('dashboardCustomizeReload').addEventListener('click', async () => {
        setBusy(true); message();
        try { saved = await request(); draft = clone(saved.preferences); apply(draft); renderOptions(); byId('dashboardCustomizeReload').hidden = true; byId('dashboardCustomizeNotice').textContent = 'Your latest saved settings are loaded.'; }
        catch (error) { message(error.message); }
        finally { setBusy(false); }
    });
    byId('dashboardCustomizeForm').addEventListener('submit', async event => {
        event.preventDefault(); if (busy || !saved.storage_ready) return;
        setBusy(true); message();
        try {
            saved = await request({ ...draft, revision: saved.revision });
            stateElement.textContent = JSON.stringify(saved); apply(saved.preferences); keepOnClose = true; dialog.close();
            if (window.AT?.toast) AT.toast('Dashboard preferences saved.', 'success');
        } catch (error) { message(error.message); }
        finally { setBusy(false); }
    });
    apply(saved.preferences);
})();
