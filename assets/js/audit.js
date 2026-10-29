/**
 * Filename: audit.js
 * Purpose: Coordinate audit interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* Stable, read-only audit history with a snapshot boundary for pagination. */
(() => 
{
    'use strict';

    const 
    {
        $: ELEMENT,
        esc: ESC,
        open: OPEN,
        error: ERROR
    }
    = WUI;
    const ROOT = ELEMENT('auditApp');
    const API = ROOT.dataset.api;
    let page = 1;
    let pages = 1;
    let anchor = '0';
    let request = 0;
    let detailRequest = 0;
    let selected = 0;
    let changePage = 1;
    let changePages = 1;
    const Filters = () => (
    {
        q: ELEMENT('auditSearch').value.trim(),
        actor: ELEMENT('auditUser').value,
        module: ELEMENT('auditModule').value,
        outcome: ELEMENT('auditOutcome').value,
        source: ELEMENT('auditSource').value,
        from: ELEMENT('auditFrom').value,
        to: ELEMENT('auditTo').value
    });
    const Query = extra => new URLSearchParams(
    {
        ...Filters(),
        page,
        anchor,
        ...extra
    }).toString();
    const Time = value => new Intl.DateTimeFormat('en-PH', 
    {
        timeZone: 'Asia/Manila',
        dateStyle: 'medium',
        timeStyle: 'medium'
    }).format(new Date(value));
    const TONES = 
    {
        success: 'green',
        failed: 'red',
        denied: 'amber',
        partial: 'amber',
        reported: 'gray',
        in_progress: 'amber'
    };
    const Outcome = value => '<span class="ws-tag ' + (TONES[value] || 'gray') + '">' + ESC(
    {
        success: 'Successful',
        failed: 'Failed',
        denied: 'Denied',
        partial: 'Partial',
        reported: 'Reported',
        in_progress: 'Incomplete / in progress'
    }
    [value] || value) + '</span>';
    document.querySelectorAll('[data-icon]').forEach(el => el.innerHTML = WUI.icon(el.dataset.icon));
    async function Catalog() 
    {
        const MODULE = ELEMENT('auditModule').value;
        const ACTOR = ELEMENT('auditUser').value;
        const RESULT = await AT.api(API + '?action=catalog');
        ELEMENT('auditModule').replaceChildren(new Option('All modules', ''), ...Object.entries(RESULT.modules).map(([id, name]) => new Option(name, id)));
        ELEMENT('auditUser').replaceChildren(new Option('All users and guests', ''), ...RESULT.people.map(p => new Option((p.actor_name || p.actor_employee_id) + ' · ' + p.actor_employee_id, p.actor_id)));
        ELEMENT('auditModule').value = MODULE;
        ELEMENT('auditUser').value = ACTOR;
    }
    async function Load(isFresh = false) 
    {
        const CURRENT = ++request;
        if (isFresh) 
        {
            anchor = '0';
            page = 1;
        }
        ERROR('auditError', '');
        ELEMENT('auditRows').setAttribute('aria-busy', 'true');
        try 
        {
            const REPORT = await AT.api(API + '?action=history&' + Query());
            if (CURRENT !== request) 
            {
                return;
            }
            anchor = REPORT.anchor;
            page = REPORT.page;
            pages = REPORT.pages;
            for (const [ID, KEY] of [['auditTotal', 'total'], ['auditPeople', 'people'], ['auditChanged', 'changed'], ['auditAttention', 'attention']]) 
            {
                ELEMENT(ID).textContent = Number(REPORT.stats[KEY]).toLocaleString();
            }
            ELEMENT('auditRows').innerHTML = REPORT.events.map(e => '<tr><td>' + ESC(Time(e.occurred_at)) + '<small>#' + ESC(e.event_id) + '</small></td><td><strong>' + ESC(e.actor_name || 'Guest / unauthenticated') + '</strong><small>' + ESC(e.actor_employee_id || 'No signed-in identity') + '</small></td><td><strong>' + ESC(e.action_label) + '</strong><small>' + ESC(e.module_label) + '</small><span class="audit-source">' + (e.source === 'browser' ? 'Browser interaction' : 'Server operation') + '</span></td><td>' + Outcome(e.outcome) + '</td><td>' + (e.change_count ? Number(e.change_count) + ' record(s)' : '—') + '</td><td><button type="button" class="btn btn-sm" data-audit-event="' + ESC(e.event_id) + '">View details</button></td></tr>').join('') || '<tr><td colspan="6" class="ws-state"><strong>No matching activity.</strong>Try other filters or refresh after using a module.</td></tr>';
            ELEMENT('auditCount').textContent = Number(REPORT.stats.total).toLocaleString() + ' events · page ' + page + ' of ' + pages;
            ELEMENT('auditPrevious').disabled = page <= 1;
            ELEMENT('auditNext').disabled = page >= pages;
            ELEMENT('auditExport').href = ROOT.dataset.export + '?' + Query(
            {
                page: 1
            });
        }
        catch (e) 
        {
            if (CURRENT === request) 
            {
                ERROR('auditError', e.message);
            }
        }
        finally 
        {
            if (CURRENT === request) 
            {
                ELEMENT('auditRows').setAttribute('aria-busy', 'false');
            }
        }
    }
    async function Detail() 
    {
        const CURRENT = ++detailRequest;
        ERROR('auditDetailError', '');
        try 
        {
            const RESULT = await AT.api(API + '?action=detail&event_id=' + selected + '&page=' + changePage);
            const E = RESULT.event;
            if (CURRENT !== detailRequest) 
            {
                return;
            }
            changePage = RESULT.page;
            changePages = RESULT.pages;
            ELEMENT('auditDetailTitle').textContent = E.action_label;
            ELEMENT('auditDetailSubtitle').textContent = E.module_label + ' · ' + Time(E.occurred_at);
            const FIELDS = [['User', (E.actor_name || 'Guest') + (E.actor_employee_id ? ' · ' + E.actor_employee_id : '')], ['Outcome', E.outcome], ['Source', E.source === 'server' ? 'Server operation' : 'Browser-reported interaction'], ['Request reference', E.request_id], ['Page', E.path], ['HTTP status', E.http_status || 'Not applicable'], ['IP address', E.ip_address || 'Not available'], ['Duration', E.duration_ms === null ? 'Not applicable' : E.duration_ms + ' ms'], ['Session reference', E.session_ref || 'Not available']];
            ELEMENT('auditDetailBody').innerHTML = '<dl class="audit-meta">' + FIELDS.map(([label, value]) => '<div><dt>' + ESC(label) + '</dt><dd>' + ESC(value) + '</dd></div>').join('') + '</dl>' + (Object.keys(E.details || 
            {

            }).length ? '<h4 class="audit-details-title">Recorded context</h4><pre class="audit-extra">' + ESC(JSON.stringify(E.details, null, 2)) + '</pre>' : '') + '<h4 class="audit-details-title">Committed record changes · ' + RESULT.change_count + '</h4>' + RESULT.changes.map(c => '<section class="audit-change"><div class="audit-change-heading"><strong>' + ESC(c.table_name) + ' #' + ESC(c.record_id) + '</strong><span class="ws-tag gray">' + ESC(c.operation) + '</span></div><div class="audit-change-fields">Changed fields: ' + c.changed_fields.map(ESC).join(', ') + '</div>' + (Object.keys(c.before_values || 
            {

            }).length || Object.keys(c.after_values || 
            {

            }).length ? '<div class="audit-values"><div><strong>Before · safe status fields</strong><pre>' + ESC(JSON.stringify(c.before_values, null, 2)) + '</pre></div><div><strong>After · safe status fields</strong><pre>' + ESC(JSON.stringify(c.after_values, null, 2)) + '</pre></div></div>' : '<p class="hint">Values were not retained. Only the record reference and changed field names are recorded.</p>') + '</section>').join('') + (!RESULT.change_count ? '<p class="ws-note">No committed database row changes are attached to this event. Views, exports, browser interactions and rejected operations can have no changes.</p>' : '');
            ELEMENT('auditChangePages').hidden = changePages <= 1;
            ELEMENT('auditChangeCount').textContent = 'Changes page ' + changePage + ' of ' + changePages;
            ELEMENT('auditChangesPrevious').disabled = changePage <= 1;
            ELEMENT('auditChangesNext').disabled = changePage >= changePages;
        }
        catch (e) 
        {
            if (CURRENT === detailRequest) 
            {
                ERROR('auditDetailError', e.message);
            }
        }
    }
    ELEMENT('auditRows').addEventListener('click', event => 
    {
        const BUTTON = event.target.closest('[data-audit-event]');
        if (BUTTON) 
        {
            selected = BUTTON.dataset.auditEvent;
            changePage = 1;
            ELEMENT('auditDetailBody').textContent = 'Loading details…';
            OPEN('auditDetailDialog');
            Detail();
        }
    });
    ELEMENT('auditPrevious').onclick = () => 
    {
        page--;
        Load();
    };
    ELEMENT('auditNext').onclick = () => 
    {
        page++;
        Load();
    };
    ELEMENT('auditChangesPrevious').onclick = () => 
    {
        changePage--;
        Detail();
    };
    ELEMENT('auditChangesNext').onclick = () => 
    {
        changePage++;
        Detail();
    };
    ELEMENT('auditRefresh').onclick = () => Catalog().then(() => Load(true)).catch(e => ERROR('auditError', e.message));
    ELEMENT('auditReset').onclick = () => 
    {
        for (const ID of ['auditSearch', 'auditUser', 'auditModule', 'auditOutcome', 'auditSource', 'auditFrom', 'auditTo']) 
        {
            ELEMENT(ID).value = '';
        }
        Load(true);
    };
    ELEMENT('auditSearch').addEventListener('input', AT.debounce(() => Load(true), 300));
    for (const ID of ['auditUser', 'auditModule', 'auditOutcome', 'auditSource', 'auditFrom', 'auditTo']) 
    {
        ELEMENT(ID).addEventListener('change', () => Load(true));
    }
    Catalog().then(() => Load(true)).catch(e => ERROR('auditError', e.message));
})();