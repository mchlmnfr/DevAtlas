/**
 * Filename: code-review.js
 * Purpose: Coordinate code review interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

const CR_API = document.getElementById('crApp').dataset.api;
const CR_EXPORT = document.getElementById('crApp').dataset.export;
const CR_LIMITS = Object.freeze(
{
    files: Number(document.getElementById('crApp').dataset.maxFiles),
    fileBytes: Number(document.getElementById('crApp').dataset.maxFileBytes),
    totalBytes: Number(document.getElementById('crApp').dataset.maxTotalBytes),
    requestBytes: Number(document.getElementById('crApp').dataset.maxRequestBytes)
});
const CR_EXTENSIONS = new Set(['php', 'phtml', 'inc', 'js', 'css', 'sql', 'html', 'htm']);
const CR_EXCLUDED = new Set(['.git', '.svn', 'vendor', 'node_modules', 'logs', 'uploads', 'dist', 'build', 'plugins', 'third_party', 'third-party', 'bower_components', 'writable']);
const CR = 
{
    mode: 'client',
    report: null,
    inventoryPage: 1,
    inventorySearch: '',
    controller: null,
    resultPage: 1,
    resultSearch: '',
    resultSeverity: '',
    resultTab: 'findings'
};
const CrEl = id => document.getElementById(id);
const CR_ESC = AT.escapeHtml;
function CrSetMode(mode) 
{
    CR.mode = mode;
    CrEl('crClientBtn').setAttribute('aria-pressed', String(mode === 'client'));
    CrEl('crServerBtn').setAttribute('aria-pressed', String(mode === 'server'));
    CrMarkStale();
    CrEl('crClientFields').hidden = mode !== 'client';
    CrEl('crServerFields').hidden = mode !== 'server';
    CrEl('crSourceNotice').textContent = mode === 'client' ? 'Source file contents are sent to this server when you select Review files. Findings and metadata are saved; raw source is not retained.' : 'Source files are read on this server when you select Review files. Findings and metadata are saved; raw source is not retained.';
}
function CrSyncEngine() 
{
    const ENGINE = CrEl('crEngine').value;
    document.querySelectorAll('[data-engine]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.engine === ENGINE)));
    CrEl('crEngineLabel').textContent = CrEl('crEngine').selectedOptions[0].textContent;
    CrMarkStale();
    const IS_INVENTORY = ENGINE === 'inventory';
    CrEl('crType').disabled = IS_INVENTORY;
    if (IS_INVENTORY) 
    {
        CrEl('crType').value = '';
    }
    CrEl('crQmsOptions').hidden = CrEl('crEngine').value !== 'qms';
}
CrEl('crEngine').addEventListener('change', CrSyncEngine);
CrEl('crChooseFolder').addEventListener('click', () => CrEl('crFolder').click());
CrEl('crFolder').addEventListener('change', () => 
{
    const FILES = Array.from(CrEl('crFolder').files);
    const COUNT = FILES.length;
    const FOLDER = FILES[0]?.webkitRelativePath?.split('/')[0] || 'Selected project folder';
    CrEl('crFolderTitle').textContent = COUNT ? FOLDER : 'Choose a project folder';
    CrEl('crFolderInfo').textContent = COUNT ? `${COUNT.toLocaleString()} file(s) selected · ${WUI.bytes(FILES.reduce((total, file) => total + file.size, 0))}. Only supported source files will be reviewed.` : 'Browse folders on this computer';
    CrEl('crChooseFolder').textContent = COUNT ? 'Change folder' : 'Choose folder';
    CrEl('crClientFields').classList.toggle('has-folder', COUNT > 0);
});
async function CrLoadProjects() 
{
    try 
    {
        const NAMES = await AT.api(CR_API + '?action=server_projects');
        const SELECT = CrEl('crProject');
        SELECT.replaceChildren(new Option('Choose a server project', ''));
        NAMES.forEach(name => SELECT.add(new Option(name, name)));
        const OWN = NAMES.find(name => name.toLowerCase() === 'devatlas') || NAMES.find(name => name.toLowerCase() === 'analysttoolkit');
        if (OWN) 
        {
            SELECT.value = OWN;
        }
    }
    catch (e) 
    {
        CrEl('crProject').replaceChildren(new Option('Could not load folders', ''));
    }
}
function CrOptions() 
{
    return (
    {
        engine: CrEl('crEngine').value,
        type: CrEl('crType').value,
        modified_days: Number(CrEl('crModified').value),
        search: CrEl('crSearch').value.trim(),
        application_id: CrEl('crApplication').value,
        qms_scope: CrEl('crQmsScope').value,
        qms_indent: CrEl('crQmsIndent').value
    });
}
async function CrClientFiles(options) 
{
    const SELECTED = Array.from(CrEl('crFolder').files);
    if (!SELECTED.length) 
    {
        throw new Error('Choose a folder on your computer.');
    }
    const FILES = [];
    let totalBytes = 0;
    let skipped = 0;
    const CUTOFF = options.modified_days ? Date.now() - options.modified_days * 86400000 : 0;
    for (const FILE of SELECTED) 
    {
        CR.controller?.signal.throwIfAborted();
        const PATH = FILE.webkitRelativePath || FILE.name;
        if (PATH.toLowerCase().endsWith('assets/css/tailwind.css'))
        {
            continue;
        }
        if (PATH.split('/').slice(0, -1).some(segment => segment.startsWith('.') || CR_EXCLUDED.has(segment.toLowerCase()))) 
        {
            continue;
        }
        const EXT = (FILE.name.split('.').pop() || '').toLowerCase();
        if (!CR_EXTENSIONS.has(EXT) || options.engine === 'inventory' && !['php', 'phtml', 'inc'].includes(EXT) || options.type && EXT !== options.type) 
        {
            continue;
        }
        if (options.search && !FILE.name.toLowerCase().includes(options.search.toLowerCase())) 
        {
            continue;
        }
        if (CUTOFF && FILE.lastModified < CUTOFF) 
        {
            continue;
        }
        if (FILE.size > CR_LIMITS.fileBytes) 
        {
            skipped++;
            continue;
        }
        if (FILES.length >= CR_LIMITS.files || totalBytes + FILE.size > CR_LIMITS.totalBytes) 
        {
            throw new Error('Selection exceeds ' + CR_LIMITS.files.toLocaleString() + ' files or ' + CR_LIMITS.totalBytes / 1048576 + ' MB after filters. Narrow the scan.');
        }
        const CONTENT = await FILE.text();
        totalBytes += new TextEncoder().encode(CONTENT).length;
        if (totalBytes > CR_LIMITS.totalBytes) 
        {
            throw new Error('Selection exceeds ' + CR_LIMITS.totalBytes / 1048576 + ' MB after text conversion. Narrow the scan.');
        }
        FILES.push(
        {
            path: PATH,
            content: CONTENT,
            modified: Math.floor(FILE.lastModified / 1000)
        });
        CrEl('crProgress').textContent = `Reading ${FILES.length} file(s)…`;
    }
    if (!FILES.length) 
    {
        throw new Error('No supported files matched. Check filters or file size limits.');
    }
    return (
    {
        files: FILES,
        skipped,
        project: FILES[0].path.split('/')[0] || 'My computer'
    });
}
async function CrScan() 
{
    if (CR.controller) 
    {
        return;
    }
    const BUTTON = CrEl('crScanBtn');
    const OPTIONS = CrOptions();
    const MODE = CR.mode;
    const PROJECT = CrEl('crProject').value;
    CR.controller = new AbortController();
    BUTTON.disabled = true;
    BUTTON.textContent = 'Reviewing…';
    CrEl('crConfig').disabled = true;
    CrEl('crCancel').hidden = false;
    CrEl('crProgress').textContent = 'Preparing files…';
    WUI.error('crError', '');
    try 
    {
        let payload = 
        {
            ...OPTIONS,
            mode: MODE
        };
        let clientSkipped = 0;
        if (MODE === 'client') 
        {
            const SELECTED = await CrClientFiles(OPTIONS);
            payload = 
            {
                ...payload,
                project: SELECTED.project,
                files: SELECTED.files
            };
            clientSkipped = SELECTED.skipped;
        }
        else 
        {
            if (!PROJECT) 
            {
                throw new Error('Choose a server project.');
            }
            payload.project = PROJECT;
        }
        CR.controller.signal.throwIfAborted();
        CrEl('crProgress').textContent = 'Analyzing source…';
        const REQUEST_BODY = JSON.stringify(payload);
        if (new Blob([REQUEST_BODY]).size > CR_LIMITS.requestBytes) 
        {
            throw new Error('Encoded scan request exceeds ' + CR_LIMITS.requestBytes / 1048576 + ' MB. Narrow the scan.');
        }
        const RESPONSE = await fetch(CR_API + '?action=scan', 
        {
            method: 'POST',
            signal: CR.controller.signal,
            headers: 
            {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content
            },
            body: REQUEST_BODY
        });
        const REPORT = await RESPONSE.json();
        if (!RESPONSE.ok) 
        {
            throw new Error(REPORT.error || 'The review failed.');
        }
        REPORT.skipped += clientSkipped;
        CR.report = REPORT;
        CrRender(REPORT);
        CrEl('crSavedReview').href = 'history.php?review_id=' + Number(REPORT.review_id);
        CrEl('crSavedReview').hidden = !REPORT.review_id;
        CrEl('crExportXlsx').disabled = REPORT.file_count === 0;
        CrEl('crExportOds').disabled = REPORT.file_count === 0;
        CrEl('crReportBar').hidden = false;
        CrEl('crReportContext').textContent = REPORT.project + ' · ' + CrEl('crEngineLabel').textContent + ' · ' + new Date().toLocaleTimeString('en-PH', 
        {
            hour: '2-digit',
            minute: '2-digit'
        });
        CrEl('crStale').hidden = true;
        CrEl('crProgress').textContent = 'Review complete';
        CrEl('crReportBar').scrollIntoView(
        {
            behavior: 'smooth',
            block: 'start'
        });
    }
    catch (e) 
    {
        if (e.name === 'AbortError') 
        {
            CrEl('crProgress').textContent = 'Review canceled';
        }
        else 
        {
            WUI.error('crError', e.message);
            CrEl('crProgress').textContent = 'Review could not complete';
        }
    }
    finally 
    {
        CR.controller = null;
        CrEl('crConfig').disabled = false;
        BUTTON.disabled = false;
        BUTTON.textContent = 'Review files';
        CrEl('crCancel').hidden = true;
        CrEl('crType').disabled = CrEl('crEngine').value === 'inventory';
    }
}
function CrRender(report) 
{
    if (report.engine === 'qms') 
    {
        CrRenderQms(report);
        return;
    }
    if (report.engine === 'inventory') 
    {
        CrRenderInventory(report);
        return;
    }
    CR.resultPage = 1;
    CR.resultSearch = '';
    CR.resultSeverity = '';
    CR.resultTab = 'findings';
    const T = report.totals;
    const STATS = [['Files reviewed', report.file_count], ['Findings', report.finding_count], ['Lines of code', T.lines], ['Functions', T.functions]];
    CrEl('crResults').innerHTML = '<div class="ws-summary">' + STATS.map(([label, value]) => '<div class="ws-stat"><span><strong>' + Number(value) + '</strong><small>' + CR_ESC(label) + '</small></span></div>').join('') + '</div>' + '<section class="ws-card"><div class="ws-card-heading"><div><h3>Review workspace</h3><p>Pattern matches are review prompts. Check the source before deciding on a fix.</p></div><span class="ws-tag gray">' + Number(T.todos) + ' TODOs · ' + Number(T.evals) + ' eval calls · ' + Number(T.long_lines) + ' long lines</span></div>' + (report.skipped || report.findings_limited ? '<p class="cr-limit">' + Number(report.skipped) + ' files skipped because of limits or read errors.' + (report.findings_limited ? ' Findings were capped at 5,000. Narrow the scan to inspect more.' : '') + '</p>' : '') + '<div class="cr-result-controls"><div class="cr-source-tabs"><button type="button" class="btn btn-sm btn-primary" id="crFindingsTab" aria-pressed="true">Findings</button><button type="button" class="btn btn-sm" id="crFilesTab" aria-pressed="false">Files reviewed</button></div><div class="form-row"><label for="crResultSearch">Search results</label><input id="crResultSearch" type="search" placeholder="File, rule or message"></div><div class="form-row"><label for="crResultSeverity">Severity</label><select id="crResultSeverity"><option value="">All severities</option><option>High</option><option>Medium</option><option>Low</option><option>Info</option></select></div></div>' + '<div class="ws-table-wrap"><table class="ws-table cr-result-table"><thead id="crResultHead"></thead><tbody id="crResultBody"></tbody></table></div><div class="ws-pagination"><span id="crResultCount" aria-live="polite"></span><div class="ws-actions"><button class="btn btn-sm" id="crResultPrev">Previous</button><button class="btn btn-sm" id="crResultNext">Next</button></div></div></section>';
    for (const [ID, EVENT, KEY] of [['crResultSearch', 'input', 'resultSearch'], ['crResultSeverity', 'change', 'resultSeverity']]) 
    {
        CrEl(ID).addEventListener(EVENT, e => 
        {
            CR[KEY] = e.target.value;
            CR.resultPage = 1;
            CrRenderRows();
        });
    }
    for (const TAB of ['Findings', 'Files']) 
    {
        CrEl('cr' + TAB + 'Tab').onclick = () => 
        {
            CR.resultTab = TAB.toLowerCase();
            CR.resultPage = 1;
            CrRenderRows();
        };
    }
    CrEl('crResultPrev').onclick = () => 
    {
        CR.resultPage--;
        CrRenderRows();
    };
    CrEl('crResultNext').onclick = () => 
    {
        CR.resultPage++;
        CrRenderRows();
    };
    CrRenderRows();
    if (report.engine === 'owasp') 
    {
        CrEl('crResults').insertAdjacentHTML('afterbegin', '<section class="ws-card"><div class="ws-card-heading"><div><h3>OWASP Top 10:2025 coverage</h3><p>' + CR_ESC(report.owasp.limitation) + '</p></div><a href="https://top10.owasp.org/2025/" target="_blank" rel="noopener noreferrer">Official OWASP reference ' + AT.icon('arrow-up-right') + '</a></div><div class="wf-coverage">' + report.owasp.categories.map(c => '<article><h4>' + CR_ESC(c.id) + ' · ' + CR_ESC(c.title) + '</h4><span class="ws-tag ' + (c.finding_count ? 'amber' : 'gray') + '">' + CR_ESC(c.status) + '</span><p>' + CR_ESC(c.manual) + '</p><small>' + Number(c.finding_count) + ' displayed matches · ' + CR_ESC(c.coverage) + '</small></article>').join('') + '</div></section>');
    }
}
function CrRenderRows() 
{
    const IS_FINDINGS = CR.resultTab === 'findings';
    const QUERY = CR.resultSearch.toLowerCase();
    const SEVERITY = CR.resultSeverity;
    CrEl('crResultSeverity').disabled = !IS_FINDINGS;
    for (const TAB of ['Findings', 'Files']) 
    {
        const IS_ACTIVE = TAB.toLowerCase() === CR.resultTab;
        CrEl('cr' + TAB + 'Tab').setAttribute('aria-pressed', String(IS_ACTIVE));
        CrEl('cr' + TAB + 'Tab').classList.toggle('btn-primary', IS_ACTIVE);
    }
    let rows = IS_FINDINGS ? CR.report.files.flatMap(file => (file.findings || []).map(f => (
    {
        ...f,
        path: file.path
    }))) : CR.report.files;
    rows = rows.filter(row => (!IS_FINDINGS || !SEVERITY || row.severity === SEVERITY) && (!QUERY || [row.path, row.rule, row.message, row.type].join(' ').toLowerCase().includes(QUERY)));
    const PAGES = Math.max(1, Math.ceil(rows.length / (window.Experience?.pageSize(25)||25)));
    CR.resultPage = Math.max(1, Math.min(CR.resultPage, PAGES));
    CrEl('crResultHead').innerHTML = IS_FINDINGS ? '<tr><th>File / line</th><th>Severity</th><th>Rule & finding</th></tr>' : '<tr><th>File</th><th>Type / size</th><th>Metrics</th><th>Findings</th></tr>';
    CrEl('crResultBody').innerHTML = rows.slice((CR.resultPage - 1) * (window.Experience?.pageSize(25)||25), CR.resultPage * (window.Experience?.pageSize(25)||25)).map(row => IS_FINDINGS ? '<tr><td class="cr-path"><strong>' + CR_ESC(row.path) + '</strong><small>Line ' + Number(row.line) + '</small></td><td><span class="badge ' + (row.severity === 'High' ? 'badge-red' : row.severity === 'Medium' ? 'badge-amber' : 'badge-blue') + '">' + CR_ESC(row.severity) + '</span></td><td><strong>' + CR_ESC(row.rule) + '</strong>' + (row.category ? '<small>' + CR_ESC(row.category) + '</small>' : '') + '<p>' + CR_ESC(row.message) + '</p>' + (row.remediation ? '<details><summary>Suggested action</summary><p>' + CR_ESC(row.remediation) + '</p></details>' : '') + '</td></tr>' : '<tr><td class="cr-path"><strong>' + CR_ESC(row.path) + '</strong></td><td>' + CR_ESC(row.type.toUpperCase()) + '<small>' + WUI.bytes(row.size) + '</small></td><td>' + Number(row.metrics.lines) + ' lines · ' + Number(row.metrics.functions) + ' functions<small>' + Number(row.metrics.todos) + ' TODOs · ' + Number(row.metrics.long_lines) + ' long lines</small></td><td>' + Number(row.findings?.length || 0) + '</td></tr>').join('') || '<tr><td colspan="4" class="ws-state"><strong>' + (QUERY || SEVERITY ? 'No matching results.' : IS_FINDINGS ? 'No findings were detected by this engine.' : 'No supported files matched.') + '</strong>' + (IS_FINDINGS ? 'Review coverage and source code before recording compliance.' : '') + '</td></tr>';
    CrEl('crResultCount').textContent = rows.length + ' ' + (IS_FINDINGS ? 'findings' : 'files') + ' · page ' + CR.resultPage + ' of ' + PAGES;
    CrEl('crResultPrev').disabled = CR.resultPage <= 1;
    CrEl('crResultNext').disabled = CR.resultPage >= PAGES;
}
function CrRenderInventory(report) 
{
    CR.inventoryPage = 1;
    CR.inventorySearch = '';
    const ISSUES = report.files.flatMap(file => (file.findings || []).map(f => `${file.path}:${f.line} ${f.message}`));
    const STATS = [['Files', report.file_count], ['Functions', report.function_count], ['Lines', report.totals.lines], ['Parse issues', ISSUES.length]];
    let html = `<div class="card"><div class="card-header"><h2 class="layout-38965f9b">Source code inventory: ${CR_ESC(report.project)}</h2><span class="badge badge-blue">PHP</span></div>
    <div class="grid cols-4">${STATS.map(([label, value]) => `<div class="stat-card"><div class="lbl">${label}</div><div class="num">${Number(value)}</div></div>`).join('')}</div>
    ${report.skipped ? `<p class="text-sm muted">${Number(report.skipped)} matching file(s) skipped because of scan limits or read errors.</p>` : ''}
    ${report.inventory_limited ? '<p class="text-sm muted">Function rows were capped at 3,000. Narrow the scan to inspect more.</p>' : ''}
    <p class="text-sm muted">Variable and return types inferred from assignments are estimates. Review source code for full behavior.</p></div>`;
    html += `<div class="card"><div class="card-header"><h2 class="layout-38965f9b">Function matrix</h2><span class="badge badge-gray">${Number(report.function_count)} row(s)</span></div>
    <div class="form-row"><label for="crInventorySearch">Find function or path</label><input id="crInventorySearch" type="search" placeholder="Search inventory"></div>
    <div class="table-wrap"><table><thead><tr><th>Function / file</th><th>Purpose</th><th>Arguments</th><th>Returns</th><th>Full details</th></tr></thead><tbody id="crInventoryBody"></tbody></table></div>
    <div class="flex gap-8 layout-4d31fe90"><span id="crInventoryCount" class="text-sm muted"></span><button class="btn" id="crInventoryPrev" type="button">Previous</button><button class="btn" id="crInventoryNext" type="button">Next</button></div></div>`;
    if (ISSUES.length) 
    {
        html += `<div class="card"><h2>PHP parse issues</h2><ul class="cr-findings">${ISSUES.map(issue => `<li>${CR_ESC(issue)}</li>`).join('')}</ul></div>`;
    }
    CrEl('crResults').innerHTML = html;
    CrEl('crInventorySearch').addEventListener('input', event => 
    {
        CR.inventorySearch = event.target.value;
        CR.inventoryPage = 1;
        CrRenderInventoryRows();
    });
    CrEl('crInventoryPrev').addEventListener('click', () => 
    {
        CR.inventoryPage--;
        CrRenderInventoryRows();
    });
    CrEl('crInventoryNext').addEventListener('click', () => 
    {
        CR.inventoryPage++;
        CrRenderInventoryRows();
    });
    CrRenderInventoryRows();
}
function CrRenderInventoryRows() 
{
    const ROWS = (CR.report?.inventory || []).filter(row => !CR.inventorySearch || `${row.path} ${row.function_name} ${row.description}`.toLowerCase().includes(CR.inventorySearch.toLowerCase()));
    const PAGES = Math.max(1, Math.ceil(ROWS.length / (window.Experience?.pageSize(25)||25)));
    CR.inventoryPage = Math.min(Math.max(1, CR.inventoryPage), PAGES);
    const List = items => (items || []).map(item => CR_ESC(item.name || item.value || '') + ' <span class="muted">' + CR_ESC(item.data_type || item.type || '') + '</span>').join('<br>') || '<span class="muted">None</span>';
    CrEl('crInventoryBody').innerHTML = ROWS.slice((CR.inventoryPage - 1) * (window.Experience?.pageSize(25)||25), CR.inventoryPage * (window.Experience?.pageSize(25)||25)).map(row => '<tr><td class="cr-path"><strong>' + CR_ESC(row.function_name) + '</strong><small>' + CR_ESC(row.path) + ' : ' + Number(row.line) + '</small></td><td>' + CR_ESC(row.description || 'No description') + '</td><td>' + List(row.arguments) + '</td><td>' + CR_ESC(row.return_data_type || 'void') + '</td><td><details><summary>Inspect</summary><dl class="cr-function-detail"><dt>Source code ID</dt><dd>' + CR_ESC(row.source_code_id) + '</dd><dt>Function ID</dt><dd>' + CR_ESC(row.function_id) + '</dd><dt>Variables</dt><dd>' + List(row.variables) + '</dd><dt>Out parameters</dt><dd>' + List(row.out_parameters) + '</dd><dt>Returns</dt><dd>' + List(row.returns) + '</dd><dt>Return passing</dt><dd>' + CR_ESC(row.return_type) + '</dd><dt>Remarks</dt><dd>' + CR_ESC(row.remarks || 'None') + '</dd></dl></details></td></tr>').join('') || '<tr><td colspan="5" class="ws-state">No functions match.</td></tr>';
    CrEl('crInventoryCount').textContent = `${ROWS.length} function(s) · page ${CR.inventoryPage} of ${PAGES}`;
    CrEl('crInventoryPrev').disabled = CR.inventoryPage <= 1;
    CrEl('crInventoryNext').disabled = CR.inventoryPage >= PAGES;
}
async function CrExport(format) 
{
    if (!CR.report) 
    {
        return;
    }
    const BUTTON = CrEl(format === 'ods' ? 'crExportOds' : 'crExportXlsx');
    BUTTON.disabled = true;
    try 
    {
        const TOKEN = document.querySelector('meta[name="csrf-token"]').content;
        const RESPONSE = await fetch(CR_EXPORT + '?format=' + encodeURIComponent(format), 
        {
            method: 'POST',
            headers: 
            {
                'Content-Type': 'application/json',
                'X-CSRF-Token': TOKEN
            },
            body: JSON.stringify(CR.report)
        });
        if (!RESPONSE.ok) 
        {
            const ERROR = await RESPONSE.json().catch(() => (
            {

            }));
            throw new Error(ERROR.error || `Export failed (${RESPONSE.status}).`);
        }
        const BLOB = await RESPONSE.blob();
        const DISPOSITION = RESPONSE.headers.get('Content-Disposition') || '';
        const FILENAME = DISPOSITION.match(/filename="([^"]+)"/)?.[1] || 'code_review.' + format;
        const LOCAL_URL = URL.createObjectURL(BLOB);
        const LINK = document.createElement('a');
        LINK.href = LOCAL_URL;
        LINK.download = FILENAME;
        document.body.appendChild(LINK);
        LINK.click();
        LINK.remove();
        setTimeout(() => URL.revokeObjectURL(LOCAL_URL), 30000);
        AT.toast('Review exported.');
    }
    catch (e) 
    {
        AT.toast(e.message, 'error');
    }
    finally 
    {
        BUTTON.disabled = false;
    }
}
function CrMarkStale() 
{
    if (CR.report && !CR.controller) 
    {
        CrEl('crStale').hidden = false;
    }
}
document.querySelectorAll('[data-engine]').forEach(button => button.onclick = () => 
{
    CrEl('crEngine').value = button.dataset.engine;
    CrSyncEngine();
});
document.querySelectorAll('[data-icon]').forEach(el => el.innerHTML = WUI.icon(el.dataset.icon));
CrEl('crConfig').addEventListener('change', CrMarkStale);
CrEl('crConfig').addEventListener('input', CrMarkStale);
CrEl('crCancel').onclick = () => CR.controller?.abort();
CrSetMode('client');
CrSyncEngine();
CrLoadProjects();
AT.api('history_api.php?action=catalog').then(c => 
{
    CrEl('crApplication').replaceChildren(new Option('No application linked', ''), ...c.applications.map(a => new Option(a.application_code + ' · ' + a.application_name, a.application_id)));
}).catch(() => 
{
    CrEl('crApplication').disabled = true;
});
document.addEventListener('experience:page-size', e => { if (!CR.report) return; e.detail.handled = true; if (CrEl('crResultBody')) CrRenderRows(); if (CrEl('crInventoryBody')) CrRenderInventoryRows(); });
