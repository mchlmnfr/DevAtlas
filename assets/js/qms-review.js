/**
 * Filename: qms-review.js
 * Purpose: Coordinate qms review interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* QMS CodeSniffer review presentation. Source contents are never rendered. */
let qmsPage = 1;
let qmsRows = [];
const QmsTone = status => status === 'Needs attention' ? 'badge-amber' : status === 'Manual review' ? 'badge-blue' : 'badge-gray';
function CrRenderQms(report) 
{
    qmsPage = 1;
    const CRITERIA = report.qms.criteria;
    const PROFILE = report.qms.profile;
    qmsRows = report.files.flatMap(file => file.findings.map(finding => (
    {
        ...finding,
        path: file.path
    })));
    const NEEDS_ATTENTION = CRITERIA.filter(row => row.status === 'Needs attention').length;
    const MANUAL = CRITERIA.filter(row => row.status === 'Manual review').length;
    CrEl('crResults').innerHTML = `
    <section class="card qms-report" aria-labelledby="qmsTitle">
      <div class="card-header"><div><span class="qms-eyebrow">QMS CodeSniffer</span><h2 id="qmsTitle">${CR_ESC(report.project)}</h2></div><span class="badge badge-blue">24 criteria</span></div>
      <div class="qms-stats">${[['Files reviewed', report.file_count], ['Displayed findings', report.finding_count], ['Criteria needing attention', NEEDS_ATTENTION], ['Criteria awaiting review', MANUAL]].map(([label, value]) => `<div class="stat-card"><div class="lbl">${label}</div><div class="num">${Number(value)}</div></div>`).join('')}</div>
      <p class="text-sm">Scope: <strong>${CR_ESC(PROFILE.scope)}</strong> · Indentation: <strong>${PROFILE.indent === 'tabs' ? 'Tabs' : `${Number(PROFILE.indent)} spaces`}</strong></p>
      <p class="hint">“No issues detected” describes the static checks performed. Review the coverage notes and manual checks before recording compliance.</p>
      ${report.skipped ? `<p class="hint">${Number(report.skipped)} matching file(s) skipped due to scan limits or read errors.</p>` : ''}
      ${report.findings_limited ? '<p class="qms-limit" role="status">Some findings are hidden by the 25-per-criterion/file or 5,000-per-scan limit. Checklist counts include detected matches. Narrow the scan to inspect more.</p>' : ''}
    </section>
    <section class="card" aria-labelledby="qmsChecklistTitle">
      <div class="card-header"><h2 id="qmsChecklistTitle">Review checklist</h2><span class="hint">Expand a criterion for coverage and file counts</span></div>
      <div class="qms-checklist">${CRITERIA.map(row => `<details class="qms-criterion"><summary><span class="qms-number">${Number(row.id)}</span><strong>${CR_ESC(row.title)}</strong><span class="badge ${QmsTone(row.status)}">${CR_ESC(row.status)}</span><span class="qms-match-count">${Number(row.finding_count)} match(es)</span></summary><div class="qms-criterion-body"><p>${CR_ESC(row.note)}</p><p class="hint">Coverage: ${CR_ESC(row.coverage)} · ${Object.entries(row.files).map(([status, count]) => `${Number(count)} ${CR_ESC(status.toLowerCase())}`).join(' · ')}</p><button type="button" class="btn btn-sm" data-qms-criterion="${Number(row.id)}">View findings</button></div></details>`).join('')}</div>
    </section>
    <section class="card" aria-labelledby="qmsFindingsTitle">
      <div class="card-header"><h2 id="qmsFindingsTitle">Findings</h2><span class="hint">File and line references</span></div>
      <div class="qms-filters"><div class="form-row"><label for="qmsSearch">Find file or message</label><input id="qmsSearch" type="search" placeholder="Search findings"></div><div class="form-row"><label for="qmsCriterion">Criterion</label><select id="qmsCriterion"><option value="">All criteria and parse issues</option>${CRITERIA.map(row => `<option value="${Number(row.id)}">${Number(row.id)}. ${CR_ESC(row.title)}</option>`).join('')}</select></div><div class="form-row"><label for="qmsSeverity">Severity</label><select id="qmsSeverity"><option value="">All severities</option>${['High', 'Medium', 'Low', 'Info'].map(value => `<option>${value}</option>`).join('')}</select></div></div>
      <div class="table-wrap"><table class="qms-findings-table"><thead><tr><th>File / line</th><th>Criterion</th><th>Severity</th><th>Finding</th></tr></thead><tbody id="qmsFindingsBody"></tbody></table></div>
      <div class="qms-pagination"><span id="qmsFindingsCount" class="hint" aria-live="polite"></span><button class="btn" id="qmsPrevious" type="button">Previous</button><button class="btn" id="qmsNext" type="button">Next</button></div>
    </section>
    <section class="card"><div class="card-header"><h2>Results by file</h2><span class="hint">Includes criteria with no line finding</span></div><div class="qms-file-list">${report.files.map(file => `<details><summary><strong>${CR_ESC(file.path)}</strong><span class="hint">${file.findings.length} displayed finding(s)${file.findings_limited ? ' · limited' : ''}</span></summary><div class="table-wrap"><table><thead><tr><th>Criterion</th><th>Status</th><th>Detected matches</th></tr></thead><tbody>${file.qms_checks.map(check => `<tr><td>${Number(check.id)}. ${CR_ESC(CRITERIA[check.id - 1].title)}</td><td><span class="badge ${QmsTone(check.status)}">${CR_ESC(check.status)}</span></td><td>${Number(check.finding_count)}</td></tr>`).join('')}</tbody></table></div></details>`).join('') || '<p class="empty-state">No files matched the filters.</p>'}</div></section>`;
    for (const ID of ['qmsSearch', 'qmsCriterion', 'qmsSeverity']) 
    {
        CrEl(ID).addEventListener(ID === 'qmsSearch' ? 'input' : 'change', () => 
        {
            qmsPage = 1;
            CrRenderQmsRows();
        });
    }
    CrEl('qmsPrevious').addEventListener('click', () => 
    {
        qmsPage--;
        CrRenderQmsRows();
    });
    CrEl('qmsNext').addEventListener('click', () => 
    {
        qmsPage++;
        CrRenderQmsRows();
    });
    document.querySelectorAll('[data-qms-criterion]').forEach(button => button.addEventListener('click', () => 
    {
        CrEl('qmsCriterion').value = button.dataset.qmsCriterion;
        CrEl('qmsSearch').value = '';
        CrEl('qmsSeverity').value = '';
        qmsPage = 1;
        CrRenderQmsRows();
        CrEl('qmsFindingsTitle').scrollIntoView(
        {
            behavior: 'smooth',
            block: 'start'
        });
    }));
    CrRenderQmsRows();
}
function CrRenderQmsRows() 
{
    const SEARCH = CrEl('qmsSearch').value.toLowerCase();
    const CRITERION = CrEl('qmsCriterion').value;
    const SEVERITY = CrEl('qmsSeverity').value;
    const ROWS = qmsRows.filter(row => (!SEARCH || `${row.path} ${row.message}`.toLowerCase().includes(SEARCH)) && (!CRITERION || row.criterion === Number(CRITERION)) && (!SEVERITY || row.severity === SEVERITY));
    const PAGES = Math.max(1, Math.ceil(ROWS.length / 25));
    qmsPage = Math.min(PAGES, Math.max(1, qmsPage));
    CrEl('qmsFindingsBody').innerHTML = ROWS.slice((qmsPage - 1) * 25, qmsPage * 25).map(row => `<tr><td class="qms-path">${CR_ESC(row.path)}<br><span class="hint">Line ${Number(row.line)}</span></td><td>${CR_ESC(row.rule)}</td><td><span class="badge ${row.severity === 'High' ? 'badge-red' : row.severity === 'Medium' ? 'badge-amber' : 'badge-blue'}">${CR_ESC(row.severity)}</span></td><td>${CR_ESC(row.message)}${row.review_required ? '<span class="qms-review-note">Reviewer confirmation required</span>' : ''}</td></tr>`).join('') || '<tr><td colspan="4" class="empty-state">No findings match. Check the checklist for manual-review items.</td></tr>';
    CrEl('qmsFindingsCount').textContent = `${ROWS.length} finding(s) · page ${qmsPage} of ${PAGES}`;
    CrEl('qmsPrevious').disabled = qmsPage <= 1;
    CrEl('qmsNext').disabled = qmsPage >= PAGES;
}
