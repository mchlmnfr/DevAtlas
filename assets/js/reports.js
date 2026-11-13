/**
 * Filename: reports.js
 * Purpose: Coordinate reports interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

(() => 
{
    'use strict';

    const ROOT = document.getElementById('reportsApp');
    if (!ROOT) 
    {
        return;
    }
    const GetElement = id => document.getElementById(id);
    const ESC = AT.escapeHtml;
    const STATE = 
    {
        catalog: null,
        request: 0,
        isBusy: false
    };
    const Hours = n => Number(n || 0).toFixed(2);
    const DisplayDate = d => new Date(d + 'T12:00:00').toLocaleDateString('en-PH', 
    {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
    const Api = (action, body, query = 
    {

    }) => AT.api(ROOT.dataset.api + '?' + new URLSearchParams(
    {
        action,
        ...query
    }));
    function GetError(id, e) 
    {
        const EL = GetElement(id);
        EL.textContent = e.message || String(e);
        EL.hidden = false;
        EL.focus();
    }
    function ClearError(id) 
    {
        GetElement(id).hidden = true;
    }
    const SUMMARY_DIALOG = GetElement('weeklySummary');
    document.body.append(SUMMARY_DIALOG);
    let summaryUser = null;
    let summaryRequest = 0;
    async function WeeklySummary(userId = null) 
    {
        if (userId !== null || !SUMMARY_DIALOG.open) 
        {
            summaryUser = userId;
            GetElement('summaryWeek').value = GetElement('reportWeek').value || STATE.catalog?.week_start || '';
        }
        if (!GetElement('summaryWeek').reportValidity()) 
        {
            return;
        }
        const REQUEST = ++summaryRequest;
        ClearError('summaryError');
        GetElement('summaryReport').hidden = true;
        GetElement('summaryPrint').disabled = true;
        GetElement('summaryStatus').textContent = 'Loading weekly summary…';
        GetElement('summaryReport').setAttribute('aria-busy', 'true');
        if (!SUMMARY_DIALOG.open) 
        {
            SUMMARY_DIALOG.showModal();
        }
        try 
        {
            const DATA = await Api('weekly_summary', null, 
            {
                week_start: GetElement('summaryWeek').value,
                ...(summaryUser !== null ? 
                {
                    user_id: summaryUser
                }
                : 
                {

                })
            });
            if (REQUEST !== summaryRequest || !SUMMARY_DIALOG.open) 
            {
                return;
            }
            GetElement('summaryWeek').value = DATA.week_start;
            GetElement('summaryEmployee').textContent = DATA.employee.report_name;
            GetElement('summaryPeriod').textContent = `${DisplayDate(DATA.week_start)} – ${DisplayDate(DATA.week_end)} · ${DATA.employee.employee_id}${DATA.employee.unit_name ? ' · ' + DATA.employee.unit_name : ''}`;
            const Cells = r => ['SD', 'SCR', 'PROJECT', 'total'].map(key => `<td>${Hours(r[key])}</td>`).join('');
            GetElement('summaryRows').innerHTML = DATA.rows.map(r => `<tr><th scope="row"><abbr title="${ESC(r.activity_name)}">${ESC(r.activity_type)}</abbr></th>${Cells(r)}</tr>`).join('');
            GetElement('summaryTotals').innerHTML = `<tr><th scope="row">Total Hrs</th>${Cells(DATA.totals)}</tr>`;
            GetElement('summaryLeaves').innerHTML = (DATA.leaves || []).length ? DATA.leaves.map(l => `<tr><td>${ESC(DisplayDate(l.activity_date))}</td><td>${ESC(l.leave_type)}</td><td>${ESC(l.leave_period || 'Undertime')}</td><td>${Hours(l.duration_hours)}</td></tr>`).join('') : '<tr><td colspan="4">None</td></tr>';
            GetElement('summaryReport').hidden = false;
            GetElement('summaryPrint').disabled = false;
            GetElement('summaryStatus').textContent = DATA.totals.total === 0 ? 'No approved work activities for this week. All hours are 0.00.' : '';
        }
        catch (e) 
        {
            if (REQUEST === summaryRequest && SUMMARY_DIALOG.open) 
            {
                GetElement('summaryStatus').textContent = '';
                GetError('summaryError', e);
            }
        }
        finally 
        {
            if (REQUEST === summaryRequest) 
            {
                GetElement('summaryReport').removeAttribute('aria-busy');
            }
        }
    }
    GetElement('weeklySummaryOpen')?.addEventListener('click', () => WeeklySummary());
    GetElement('summaryFilters').addEventListener('submit', e => 
    {
        e.preventDefault();
        WeeklySummary();
    });
    GetElement('summaryWeek').addEventListener('input', () => 
    {
        summaryRequest++;
        GetElement('summaryReport').hidden = true;
        GetElement('summaryPrint').disabled = true;
        GetElement('summaryStatus').textContent = 'Generate the report for the selected week.';
    });
    SUMMARY_DIALOG.addEventListener('close', () => 
    {
        summaryRequest++;
        document.body.classList.remove('dl-print-summary');
    });
    GetElement('summaryPrint').addEventListener('click', () => 
    {
        document.body.classList.add('dl-print-summary');
        try 
        {
            window.print();
        }
        finally 
        {
            document.body.classList.remove('dl-print-summary');
        }
    });
    function Filters() 
    {
        return Object.fromEntries(new FormData(GetElement('reportFilters')));
    }
    function DailyReport(report)
    {
        const rows = report.logs || [];
        const body = rows.map(r => `<tr><td>${ESC(DisplayDate(r.activity_date))}</td><td>${ESC(r.entry_kind === 'leave' ? [r.leave_type, r.leave_period].filter(Boolean).join(' · ') : r.activity_type || '—')}</td><td>${ESC(r.classification || '—')}</td><td class="report-text">${ESC(r.planned_task || '—')}</td><td class="report-text">${ESC(r.actual_activity || '—')}</td><td class="report-hours">${Hours(r.duration_hours)}</td><td><span class="badge">${ESC(r.status === 'Reviewed' ? 'Approved' : r.status)}</span></td><td class="report-text">${ESC(r.remarks || '—')}</td><td class="report-text">${ESC(r.reviewer_name || '—')}${r.review_comment ? `<small>${ESC(r.review_comment)}</small>` : ''}</td></tr>`).join('');
        return `<div class="report-table-scroll" data-exp-ui="true"><table class="ws-table report-detail-table"><caption class="sr-only">Daily entries for ${ESC(report.employee.employee_name)}</caption><thead><tr><th>Date</th><th>Activity / Leave</th><th>Classification</th><th>Planned Task</th><th>Actual Activity Performed</th><th>Hours</th><th>Status</th><th>Remarks</th><th>Review Details</th></tr></thead><tbody>${body || '<tr><td colspan="9">No entries match this period and status.</td></tr>'}</tbody></table></div>`;
    }
    function WeeklyReport(report)
    {
        const cells = r => ['SD', 'SCR', 'PROJECT', 'total'].map(key => `<td class="report-hours">${Hours(r[key])}</td>`).join('');
        const rows = report.rows.map(r => `<tr><th scope="row">${ESC(r.activity_type)}<small>${ESC(r.activity_name)}</small></th>${cells(r)}</tr>`).join('');
        const leaves = (report.leaves || []).map(r => `<tr><td>${ESC(DisplayDate(r.activity_date))}</td><td>${ESC(r.leave_type)}</td><td>${ESC(r.leave_period || 'Undertime')}</td><td class="report-hours">${Hours(r.duration_hours)}</td></tr>`).join('');
        return `<div class="report-table-scroll" data-exp-ui="true"><table class="ws-table report-weekly-table"><caption class="sr-only">Weekly work summary for ${ESC(report.employee.employee_name)}</caption><thead><tr><th>Activity Type</th><th>Service Delivery</th><th>System Change Request</th><th>Project</th><th>Total Hours</th></tr></thead><tbody>${rows}</tbody><tfoot><tr><th scope="row">Total Hours</th>${cells(report.totals)}</tr></tfoot></table></div><div class="report-leave-summary"><h4>Approved leave / undertime</h4>${leaves ? `<div class="report-table-scroll" data-exp-ui="true"><table class="ws-table"><thead><tr><th>Date</th><th>Type</th><th>Period</th><th>Hours</th></tr></thead><tbody>${leaves}</tbody></table></div>` : '<p>None for this week.</p>'}<p class="wf-muted">Leave and weekends are excluded from the work-hour totals.</p></div>`;
    }
    function RenderReports(data)
    {
        const firstFilled = data.reports.findIndex(r => data.report_type === 'daily' ? r.logs.length > 0 : r.work_hours > 0 || (r.leaves || []).length > 0);
        const opened = firstFilled < 0 ? 0 : firstFilled;
        GetElement('reportDetailsRows').innerHTML = data.reports.map((r, index) => `<details class="report-employee" id="employeeReport${Number(r.employee.user_id)}"${index === opened ? ' open' : ''}><summary><span><strong>${ESC(r.employee.employee_name)}</strong><small>${ESC(r.employee.employee_id)} · ${ESC(r.employee.report_name)}</small></span><span class="report-employee-total">${Hours(r.work_hours)} work hours${data.report_type === 'daily' ? ` · ${Hours(r.leave_hours)} leave / UT hours` : ''}</span></summary><div class="report-employee-body">${data.report_type === 'daily' ? DailyReport(r) : WeeklyReport(r)}</div></details>`).join('');
        GetElement('reportDetails').hidden = false;
    }
    function SyncType() 
    {
        const IS_WEEKLY = GetElement('reportType').value === 'weekly';
        for (const [SELECTOR, ACTIVE] of [['[data-daily-field]', !IS_WEEKLY], ['[data-weekly-field]', IS_WEEKLY]]) 
        {
            for (const ROW of document.querySelectorAll(SELECTOR)) 
            {
                ROW.hidden = !ACTIVE;
                ROW.querySelectorAll('input,select').forEach(el => el.disabled = !ACTIVE);
            }
        }
        GetElement('reportHint').textContent = IS_WEEKLY ? 'Weekly Summary includes approved Monday-Friday work hours by activity type and classification. Leave and weekends are excluded.' : 'Daily Logs includes individual work and leave entries, planned tasks, actual activities, hours, remarks and review details. Private drafts are excluded.';
    }
    function Invalidate() 
    {
        STATE.request++;
        GetElement('reportCount').textContent = '';
        GetElement('reportDetails').hidden = true;
        GetElement('reportDetailsRows').replaceChildren();
        GetElement('reportRows').innerHTML = '<tr><td colspan="6"><div class="ws-state">Preview the report for the selected section and period.</div></td></tr>';
        GetElement('reportRows').removeAttribute('aria-busy');
    }
    async function Preview() 
    {
        if (!GetElement('reportFilters').reportValidity()) 
        {
            return;
        }
        const REQUEST = ++STATE.request;
        ClearError('reportsError');
        GetElement('reportDetails').hidden = true;
        GetElement('reportRows').setAttribute('aria-busy', 'true');
        try 
        {
            const DATA = await Api('preview', null, Filters());
            if (REQUEST !== STATE.request) 
            {
                return;
            }
            GetElement('reportPreviewTitle').textContent = DATA.section.unit_name + ' · ' + (DATA.report_type === 'daily' ? DisplayDate(DATA.date_from) + ' – ' + DisplayDate(DATA.date_to) : DisplayDate(DATA.week_start) + ' – ' + DisplayDate(DATA.week_end));
            if (DATA.report_type === 'weekly') 
            {
                GetElement('reportWeek').value = DATA.week_start;
            }
            GetElement('reportRows').innerHTML = DATA.reports.map(r => `<tr><td><strong>${ESC(r.employee.employee_name)}</strong><small>${ESC(r.employee.employee_id)}</small></td><td>${ESC(r.employee.report_name)}</td><td>${r.log_count ?? '—'}</td><td>${Hours(r.work_hours)}</td><td>${DATA.report_type === 'daily' ? Hours(r.leave_hours) : '—'}</td><td><button type="button" class="btn btn-sm" data-report="${Number(r.employee.user_id)}">View Report</button></td></tr>`).join('');
            GetElement('reportCount').textContent = DATA.reports.length + ' employee reports';
            RenderReports(DATA);
        }
        catch (e) 
        {
            if (REQUEST === STATE.request) 
            {
                GetError('reportsError', e);
                Invalidate();
            }
        }
        finally 
        {
            if (REQUEST === STATE.request) 
            {
                GetElement('reportRows').removeAttribute('aria-busy');
            }
        }
    }
    GetElement('reportFilters').addEventListener('submit', e => 
    {
        e.preventDefault();
        Preview();
    });
    GetElement('reportFilters').addEventListener('input', Invalidate);
    GetElement('reportFilters').addEventListener('change', Invalidate);
    GetElement('reportType').addEventListener('change', () => 
    {
        SyncType();
        Invalidate();
    });
    GetElement('reportExport')?.addEventListener('click', async () => 
    {
        if (STATE.isBusy || !GetElement('reportFilters').reportValidity()) 
        {
            return;
        }
        STATE.isBusy = true;
        GetElement('reportExport').disabled = true;
        ClearError('reportsError');
        try 
        {
            const RESPONSE = await fetch(ROOT.dataset.export + '?' + new URLSearchParams(Filters()), 
            {
                credentials: 'same-origin'
            });
            if (!RESPONSE.ok) 
            {
                const DATA = await RESPONSE.json();
                throw new Error(DATA.error || 'Unable to export the report.');
            }
            const BLOB = await RESPONSE.blob();
            const LOCAL_URL = URL.createObjectURL(BLOB);
            const LINK = document.createElement('a');
            LINK.href = LOCAL_URL;
            LINK.download = RESPONSE.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] || 'section_report.xlsx';
            document.body.append(LINK);
            LINK.click();
            LINK.remove();
            setTimeout(() => URL.revokeObjectURL(LOCAL_URL), 1000);
            AT.toast('Excel exported with a separate worksheet for every employee.');
        }
        catch (e) 
        {
            GetError('reportsError', e);
        }
        finally 
        {
            STATE.isBusy = false;
            GetElement('reportExport').disabled = false;
        }
    });
    document.addEventListener('click', e => 
    {
        const B = e.target.closest('button');
        if (!B) 
        {
            return;
        }
        if (B.dataset.close) 
        {
            GetElement(B.dataset.close).close();
        }
        if (B.dataset.summary) 
        {
            WeeklySummary(B.dataset.summary);
        }
        if (B.dataset.report)
        {
            const report = GetElement('employeeReport' + B.dataset.report);
            if (report)
            {
                report.open = true;
                const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches || document.documentElement.dataset.motion === 'off';
                report.scrollIntoView({behavior: reduced ? 'instant' : 'smooth', block: 'start'});
                report.querySelector('summary').focus({preventScroll: true});
            }
        }
    });
    async function Init() 
    {
        try 
        {
            const C = await Api('catalog');
            STATE.catalog = C;
            GetElement('reportFrom').value = C.week_start;
            GetElement('reportTo').value = C.week_end;
            GetElement('reportWeek').value = C.week_start;
            GetElement('reportUnit').innerHTML = '<option value="">Choose a section</option>' + C.units.map(u => `<option value="${u.unit_id}">${ESC(u.unit_name)}</option>`).join('');
            if (C.profile.unit_id) 
            {
                GetElement('reportUnit').value = C.profile.unit_id;
            }
            GetElement('reportsWorkspace').hidden = !C.units.length;
            GetElement('reportsAccessNote').hidden = !!C.units.length;
            SyncType();
            if (GetElement('reportUnit').value) 
            {
                await Preview();
            }
        }
        catch (e) 
        {
            GetError('reportsError', e);
        }
    }
    Init();
})();
