/**
 * Filename: logs.js
 * Purpose: Coordinate logs interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* Recorded-event summaries, grouping, filters and bounded daily-log previews. */
(() => 
{
    'use strict';

    const El = id => document.getElementById(id);
    const ESC = AT.escapeHtml;
    const STATE = 
    {
        files: [],
        report: null,
        entries: [],
        page: 1,
        visible: [],
        readId: 0,
        fileId: 0,
        isLoading: false,
        isClearing: false,
        expanded: new Set()
    };
    const Api = (action, body) => AT.api(LOG_CONFIG.api + '?action=' + action, body === undefined ? 
    {

    }
    : 
    {
        method: 'POST',
        body
    });
    const DATE_FORMAT = new Intl.DateTimeFormat('en-PH', 
    {
        dateStyle: 'medium',
        timeStyle: 'medium',
        timeZone: 'Asia/Manila'
    });
    const DAY_FORMAT = new Intl.DateTimeFormat('en-PH', 
    {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZone: 'Asia/Manila'
    });
    const TIME_FORMAT = new Intl.DateTimeFormat('en-PH', 
    {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        timeZone: 'Asia/Manila'
    });
    const DisplayTime = value => 
    {
        const DATE = new Date(value ?? '');
        return Number.isNaN(DATE.getTime()) ? 'Time unavailable' : DATE_FORMAT.format(DATE);
    };
    const Bytes = value => 
    {
        if (value < 1024) 
        {
            return `${value} B`;
        }
        if (value < 1048576) 
        {
            return `${(value / 1024).toFixed(1)} KB`;
        }
        return `${(value / 1048576).toFixed(1)} MB`;
    };
    const Badge = severity => severity === 'error' ? 'badge-red' : severity === 'warning' ? 'badge-amber' : severity === 'info' ? 'badge-blue' : 'badge-gray';
    function ErrorMessage(message = '') 
    {
        El('lgError').textContent = message;
        El('lgError').hidden = !message;
    }
    function SetActions() 
    {
        const IS_AVAILABLE = Boolean(STATE.report && El('lgFile').value && !STATE.isLoading);
        El('lgClear').disabled = !IS_AVAILABLE;
        El('lgDownload').setAttribute('aria-disabled', String(!IS_AVAILABLE));
        if (IS_AVAILABLE) 
        {
            El('lgDownload').href = LOG_CONFIG.download + '?file=' + encodeURIComponent(El('lgFile').value);
        }
        else 
        {
            El('lgDownload').removeAttribute('href');
        }
    }
    async function LoadFiles(preferred) 
    {
        const REQUEST = ++STATE.fileId;
        const PREVIOUS = preferred || El('lgFile').value;
        El('lgRefresh').disabled = true;
        El('lgFile').disabled = true;
        ErrorMessage();
        try 
        {
            const FILES = await Api('file_catalog');
            if (REQUEST !== STATE.fileId) 
            {
                return;
            }
            STATE.files = FILES;
            El('lgFile').replaceChildren(...FILES.map(file => new Option(`${DAY_FORMAT.format(new Date(file.date + 'T12:00:00Z'))} · ${Bytes(file.size)}`, file.name)));
            if (!FILES.length) 
            {
                STATE.readId++;
                STATE.report = null;
                STATE.entries = [];
                STATE.isLoading = false;
                El('lgFile').add(new Option('No daily logs available', ''));
                El('lgFileMeta').textContent = 'No daily log files are available.';
                El('lgRefreshed').textContent = 'File list refreshed';
                El('lgPreviewNotice').hidden = true;
                RenderStats();
                Render();
                SetActions();
                return;
            }
            if (FILES.some(file => file.name === PREVIOUS)) 
            {
                El('lgFile').value = PREVIOUS;
            }
            await LoadLog();
        }
        catch (error) 
        {
            ErrorMessage(error.message);
            if (!STATE.report) 
            {
                El('lgEvents').innerHTML = '<div class="lg-empty"><strong>The log files could not be loaded.</strong><p>Use Refresh to try again.</p></div>';
            }
        }
        finally 
        {
            if (REQUEST === STATE.fileId) 
            {
                El('lgRefresh').disabled = false;
                El('lgFile').disabled = !STATE.files.length;
                El('lgEvents').setAttribute('aria-busy', 'false');
            }
        }
    }
    async function LoadLog(isKeepPage = false) 
    {
        const FILE = El('lgFile').value;
        if (!FILE) 
        {
            return;
        }
        const REQUEST = ++STATE.readId;
        STATE.isLoading = true;
        SetActions();
        ErrorMessage();
        El('lgEvents').setAttribute('aria-busy', 'true');
        El('lgRefreshed').textContent = 'Refreshing…';
        try 
        {
            const REPORT = await Api('get_report&file=' + encodeURIComponent(FILE) + '&limit=' + encodeURIComponent(El('lgLimit').value));
            if (REQUEST !== STATE.readId || FILE !== El('lgFile').value) 
            {
                return;
            }
            STATE.report = REPORT;
            STATE.entries = REPORT.entries.map((entry, index) => (
            {
                ...entry,
                _ordinal: index,
                _time: Date.parse(entry.time || '')
            }));
            if (!isKeepPage) 
            {
                STATE.page = 1;
            }
            const CURRENT = El('lgModule').value;
            const MODULES = [...new Set(STATE.entries.map(entry => entry.module))].sort();
            El('lgModule').replaceChildren(new Option('All areas', ''), ...MODULES.map(module => new Option(module, module)));
            if (MODULES.includes(CURRENT)) 
            {
                El('lgModule').value = CURRENT;
            }
            const OTHER_COUNT = STATE.entries.filter(entry => entry.severity === 'other').length;
            El('lgFileMeta').textContent = `${REPORT.file} · ${Bytes(REPORT.size)} · ${REPORT.returned.toLocaleString()} event(s) loaded${OTHER_COUNT ? ` · ${OTHER_COUNT} other/unrecognized level(s)` : ''}`;
            El('lgPreviewNotice').hidden = !REPORT.has_more && !REPORT.preview_limited;
            El('lgPreviewNotice').textContent = REPORT.preview_limited ? 'The file is larger than the bounded preview window. Only complete recent entries within the last 4 MB are shown. Download the raw file for the full history.' : `Showing the latest ${REPORT.returned.toLocaleString()} events. Earlier events are outside this preview; choose a larger preview or download the raw file.`;
            El('lgRefreshed').textContent = 'Refreshed ' + TIME_FORMAT.format(new Date());
            RenderStats();
            Render();
        }
        catch (error) 
        {
            if (REQUEST !== STATE.readId) 
            {
                return;
            }
            ErrorMessage(error.message);
            El('lgRefreshed').textContent = 'Refresh failed';
            if (STATE.report?.file !== FILE) 
            {
                STATE.report = null;
                STATE.entries = [];
                RenderStats();
                Render();
            }
        }
        finally 
        {
            if (REQUEST === STATE.readId) 
            {
                STATE.isLoading = false;
                SetActions();
                El('lgEvents').setAttribute('aria-busy', 'false');
            }
        }
    }
    function RenderStats() 
    {
        El('lgCountAll').textContent = STATE.entries.length.toLocaleString();
        for (const [ID, SEVERITY] of [['lgCountErrors', 'error'], ['lgCountWarnings', 'warning'], ['lgCountInfo', 'info']]) 
        {
            El(ID).textContent = STATE.entries.filter(entry => entry.severity === SEVERITY).length.toLocaleString();
        }
        for (const BUTTON of document.querySelectorAll('[data-severity]')) 
        {
            const IS_ACTIVE = BUTTON.dataset.severity === El('lgSeverity').value;
            BUTTON.classList.toggle('active', IS_ACTIVE);
            BUTTON.setAttribute('aria-pressed', String(IS_ACTIVE));
        }
    }
    function SelectedGroups() 
    {
        const QUERY = El('lgSearch').value.trim().toLowerCase();
        const SEVERITY = El('lgSeverity').value;
        const MODULE = El('lgModule').value;
        const MATCHING = STATE.entries.filter(entry => (!SEVERITY || entry.severity === SEVERITY) && (!MODULE || entry.module === MODULE) && (!QUERY || JSON.stringify([entry.title, entry.meaning, entry.next_step, entry.message, entry.module, entry.route, entry.level, entry.context, entry.source_file]).toLowerCase().includes(QUERY)));
        const GROUPS = new Map();
        for (const ENTRY of MATCHING) 
        {
            if (!GROUPS.has(ENTRY.group_id)) 
            {
                GROUPS.set(ENTRY.group_id, []);
            }
            GROUPS.get(ENTRY.group_id).push(ENTRY);
        }
        return [...GROUPS.values()].filter(entries => !El('lgRepeatedOnly').checked || entries.length > 1).map(entries => entries.sort((a, b) => Number.isFinite(a._time) && Number.isFinite(b._time) ? b._time - a._time || a._ordinal - b._ordinal : Number.isFinite(a._time) ? -1 : Number.isFinite(b._time) ? 1 : a._ordinal - b._ordinal));
    }
    function BuildView() 
    {
        const GROUPED = El('lgGroup').checked;
        const GROUPS = SelectedGroups();
        let rows = GROUPS.flatMap(entries => GROUPED ? [
        {
            entry: entries[0],
            entries,
            key: entries[0].group_id
        }] : entries.map(entry => (
        {
            entry,
            entries: [entry],
            key: entry.group_id + '-' + entry._ordinal
        })));
        rows = rows.map(row => 
        {
            const TIMES = row.entries.map(entry => entry._time).filter(Number.isFinite);
            return (
            {
                ...row,
                first: TIMES.length ? Math.min(...TIMES) : NaN,
                last: TIMES.length ? Math.max(...TIMES) : NaN
            });
        });
        const SORT = El('lgSort').value;
        rows.sort((a, b) => 
        {
            if (SORT === 'repeated' && b.entries.length !== a.entries.length) 
            {
                return b.entries.length - a.entries.length;
            }
            if (!Number.isFinite(a.last) || !Number.isFinite(b.last)) 
            {
                return Number.isFinite(a.last) ? -1 : Number.isFinite(b.last) ? 1 : a.entry._ordinal - b.entry._ordinal;
            }
            return SORT === 'oldest' ? a.first - b.first || b.entry._ordinal - a.entry._ordinal : b.last - a.last || a.entry._ordinal - b.entry._ordinal;
        });
        return (
        {
            rows,
            eventCount: GROUPS.reduce((total, entries) => total + entries.length, 0),
            grouped: GROUPED
        });
    }
    function Render() 
    {
        const 
        {
            rows: ROWS,
            eventCount: EVENT_COUNT,
            grouped: GROUPED
        }
        = BuildView();
        const PAGES = Math.max(1, Math.ceil(ROWS.length / 20));
        STATE.page = Math.max(1, Math.min(STATE.page, PAGES));
        STATE.visible = ROWS.slice((STATE.page - 1) * 20, STATE.page * 20);
        El('lgResultsCount').textContent = `${EVENT_COUNT.toLocaleString()} matching event(s)${GROUPED ? ` in ${ROWS.length.toLocaleString()} group(s)` : ''} · ${STATE.entries.length.toLocaleString()} loaded from this daily file`;
        El('lgPageLabel').textContent = `Page ${STATE.page} of ${PAGES}`;
        El('lgPrevious').disabled = STATE.page === 1;
        El('lgNext').disabled = STATE.page === PAGES;
        if (!ROWS.length) 
        {
            const TITLE = !STATE.files.length ? 'No daily logs yet' : !STATE.entries.length ? 'No events in this preview' : 'No events match your filters';
            const NOTE = !STATE.files.length ? 'Recorded events will appear here when the application writes a log.' : !STATE.entries.length ? 'Check the preview notice or choose another daily file.' : 'Try a different search, severity or area, or use Reset filters.';
            El('lgEvents').innerHTML = `<div class="lg-empty"><strong>${TITLE}</strong><p>${NOTE}</p></div>`;
            return;
        }
        El('lgEvents').innerHTML = STATE.visible.map((row, index) => 
        {
            const ENTRY = row.entry;
            const COUNT = row.entries.length;
            const CONTEXT = JSON.stringify(ENTRY.context, null, 2);
            const METHOD = typeof ENTRY.context.method === 'string' ? ENTRY.context.method : '';
            const DETAILS = [['Affected area', ENTRY.module], ['Page / action', ENTRY.route || 'Not recorded'], ['Request', `${METHOD}${ENTRY.status ? ' · HTTP ' + ENTRY.status : ''}` || 'Not recorded'], ['Recorded level', ENTRY.level], ['First recorded', Number.isFinite(row.first) ? DisplayTime(row.first) : 'Time unavailable'], ['Last recorded', Number.isFinite(row.last) ? DisplayTime(row.last) : 'Time unavailable']];
            if (ENTRY.source_file) 
            {
                DETAILS.push(['Source file', ENTRY.source_file + (ENTRY.source_line ? ':' + ENTRY.source_line : '')]);
            }
            return `<article class="lg-event lg-event-${ENTRY.severity}"><div class="lg-event-meta"><span class="badge ${Badge(ENTRY.severity)}">${ESC(ENTRY.severity_label)}</span><span>${ESC(ENTRY.module)}</span><span>·</span><time>${ESC(DisplayTime(Number.isFinite(row.last) ? row.last : ENTRY.time))}</time>${COUNT > 1 ? `<span class="lg-occurrences">${COUNT.toLocaleString()} occurrences</span>` : ''}<button class="btn btn-sm lg-copy" type="button" data-copy="${index}">Copy summary</button></div><h3>${ESC(ENTRY.title)}</h3><p class="lg-meaning">${ESC(ENTRY.meaning)}</p><div class="lg-next-check"><span aria-hidden="true">${AT.icon('corner-down-right')}</span><div><strong>Suggested check</strong>${ESC(ENTRY.next_step)}</div></div><details class="lg-event-details" data-event-key="${ESC(row.key)}" ${STATE.expanded.has(row.key) ? 'open' : ''}><summary>Recorded message &amp; technical details <span aria-hidden="true">· ${ESC(ENTRY.category)}</span></summary><div class="lg-technical"><h4>Recorded message</h4><pre>${ESC(ENTRY.message)}</pre><dl class="lg-context-grid">${DETAILS.map(([label, value]) => `<div><dt>${label}</dt><dd>${ESC(value)}</dd></div>`).join('')}</dl>${COUNT > 1 ? `<h4>Recent occurrences</h4><ul class="lg-occurrence-list">${row.entries.slice(0, 10).map(item => `<li>${ESC(DisplayTime(item.time))}</li>`).join('')}</ul><p class="lg-technical-note">${COUNT > 10 ? 'Showing the latest 10 matching occurrences. ' : ''}Context below is from the newest matching entry in this group.</p>` : ''}<h4>Technical context</h4><pre>${ESC(CONTEXT === '{}' ? 'No additional context was recorded.' : CONTEXT)}</pre><p class="lg-technical-note">Common credential fields and URL query values are hidden in this view. Raw downloads retain the original content.</p></div></details></article>`;
        }).join('');
    }
    function ResetFilters() 
    {
        El('lgSearch').value = '';
        El('lgSeverity').value = '';
        El('lgModule').value = '';
        El('lgSort').value = 'newest';
        El('lgRepeatedOnly').checked = false;
        El('lgGroup').checked = true;
        STATE.page = 1;
        RenderStats();
        Render();
    }
    async function CopySummary(index) 
    {
        const ROW = STATE.visible[index];
        if (!ROW) 
        {
            return;
        }
        const ENTRY = ROW.entry;
        const SUMMARY = [`Event: ${ENTRY.title}`, `Severity: ${ENTRY.severity_label} (${ENTRY.level})`, `Area: ${ENTRY.module}`, `Occurrences in the matching preview: ${ROW.entries.length}`, `First recorded: ${Number.isFinite(ROW.first) ? DisplayTime(ROW.first) : 'Unknown'}`, `Last recorded: ${Number.isFinite(ROW.last) ? DisplayTime(ROW.last) : 'Unknown'}`, `Page/action: ${ENTRY.route || 'Not recorded'}`, `Meaning: ${ENTRY.meaning}`, `Suggested check: ${ENTRY.next_step}`, '', 'Recorded message:', ENTRY.message, '', 'Technical context:', JSON.stringify(ENTRY.context, null, 2)].join('\n');
        try 
        {
            if (navigator.clipboard?.writeText) 
            {
                await navigator.clipboard.writeText(SUMMARY);
            }
            else 
            {
                const FIELD = document.createElement('textarea');
                FIELD.value = SUMMARY;
                FIELD.setAttribute('readonly', '');
                FIELD.className = 'lg-copy-helper';
                document.body.append(FIELD);
                FIELD.select();
                const COPIED = document.execCommand('copy');
                FIELD.remove();
                if (!COPIED) 
                {
                    throw new Error('Clipboard unavailable.');
                }
            }
            AT.toast('Event summary copied.');
        }
        catch 
        {
            ErrorMessage('The summary could not be copied. Open the event details to select the text, or use a browser with clipboard access.');
        }
    }
    async function OpenDelete() 
    {
        if (!STATE.report || STATE.isLoading || STATE.isClearing) 
        {
            return;
        }
        El('lgMore').open = false;
        STATE.isClearing = true;
        const FILE = STATE.report.file;
        try 
        {
            if (!(await AT.confirmAction('Delete ' + FILE + '? This removes every event in the file, including entries hidden by your filters. Download the raw file first if you need to keep a copy.', 'Delete this daily log?', 'Delete file'))) 
            {
                return;
            }
            await Api('clear_log', 
            {
                file: FILE
            });
            STATE.report = null;
            STATE.entries = [];
            AT.toast('Daily log file deleted.');
            await LoadFiles();
        }
        catch (error) 
        {
            ErrorMessage(error.message);
        }
        finally 
        {
            STATE.isClearing = false;
        }
    }
    El('lgRefresh').addEventListener('click', () => LoadFiles());
    El('lgFile').addEventListener('change', () => LoadLog());
    El('lgLimit').addEventListener('change', () => LoadLog());
    for (const ID of ['lgSearch', 'lgSeverity', 'lgModule', 'lgSort', 'lgGroup', 'lgRepeatedOnly']) 
    {
        El(ID).addEventListener(ID === 'lgSearch' ? 'input' : 'change', () => 
        {
            STATE.page = 1;
            RenderStats();
            Render();
        });
    }
    document.querySelectorAll('[data-severity]').forEach(button => button.addEventListener('click', () => 
    {
        El('lgSeverity').value = button.dataset.severity;
        STATE.page = 1;
        RenderStats();
        Render();
    }));
    El('lgReset').addEventListener('click', ResetFilters);
    El('lgPrevious').addEventListener('click', () => 
    {
        STATE.page--;
        Render();
        El('lgHistoryTitle').scrollIntoView(
        {
            block: 'start',
            behavior: 'smooth'
        });
    });
    El('lgNext').addEventListener('click', () => 
    {
        STATE.page++;
        Render();
        El('lgHistoryTitle').scrollIntoView(
        {
            block: 'start',
            behavior: 'smooth'
        });
    });
    El('lgEvents').addEventListener('click', event => 
    {
        const BUTTON = event.target.closest('[data-copy]');
        if (BUTTON) 
        {
            CopySummary(Number(BUTTON.dataset.copy));
        }
    });
    El('lgEvents').addEventListener('toggle', event => 
    {
        const KEY = event.target.dataset.eventKey;
        if (!KEY) 
        {
            return;
        }
        if (event.target.open) 
        {
            STATE.expanded.add(KEY);
        }
        else 
        {
            STATE.expanded.delete(KEY);
        }
    }, true);
    El('lgClear').addEventListener('click', OpenDelete);
    El('lgTestEntry').addEventListener('click', async () => 
    {
        El('lgMore').open = false;
        El('lgTestEntry').disabled = true;
        try 
        {
            const RESULT = await Api('test_log_entry', 
            {

            });
            await LoadFiles(RESULT.file);
            AT.toast('Diagnostic entry added to today’s log.');
        }
        catch (error) 
        {
            ErrorMessage(error.message);
        }
        finally 
        {
            El('lgTestEntry').disabled = false;
        }
    });
    document.addEventListener('click', event => 
    {
        if (!El('lgMore').contains(event.target)) 
        {
            El('lgMore').open = false;
        }
    });
    document.addEventListener('keydown', event => 
    {
        if (event.key === 'Escape') 
        {
            El('lgMore').open = false;
        }
    });
    const RefreshAutomatically = () => 
    {
        if (El('lgAutoRefresh').checked && !document.hidden && !STATE.isLoading && !STATE.isClearing) 
        {
            LoadLog(true);
        }
    };
    const TIMER = setInterval(RefreshAutomatically, 30000);
    document.addEventListener('visibilitychange', () => 
    {
        if (!document.hidden) 
        {
            RefreshAutomatically();
        }
    });
    addEventListener('pagehide', () => clearInterval(TIMER), 
    {
        once: true
    });
    SetActions();
    LoadFiles();
})();