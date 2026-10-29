/**
 * Filename: daily-logs.js
 * Purpose: Coordinate daily logs interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Expose submitted-log editing with a save-changes action and retained review status.
 */

(() => 
{
    'use strict';

    const ROOT = document.getElementById('dailyLogsApp');
    if (!ROOT) 
    {
        return;
    }
    const GetElement = id => document.getElementById(id);
    const ESC = AT.escapeHtml;
    const IS_PERSONAL = ROOT.dataset.module === 'dailylogs';
    const IS_MONITOR = ROOT.dataset.module === 'teammonitoring';
    const IS_CAN_REVIEW = ROOT.dataset.review === '1';
    const IS_WEEKLY = ROOT.dataset.module === 'reviewlogs';
    const ACCESS = window.AT_ACCESS?.modules?.dailylogs || 
    {

    };
    const STATE = 
    {
        page: 1,
        pages: 1,
        rows: [],
        selected: new Map(),
        catalog: null,
        detail: null,
        editing: null,
        request: 0,
        isBusy: false,
        member: null,
        memberPage: 1,
        memberPages: 1,
        memberRequest: 0
    };
    let reviewerUnits = [];
    let isReviewerSaving = false;
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
    }), body ? 
    {
        method: 'POST',
        body
    }
    : 
    {

    });
    const Badge = s => `<span class="ws-tag ${s === 'Reviewed' ? 'green' : s === 'Submitted' ? 'amber' : s === 'Returned' ? 'red' : 'gray'}">${ESC(s)}</span>`;
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
    function Options(id, values) 
    {
        GetElement(id).innerHTML = '<option value="">Choose an option</option>' + Object.entries(values).map(([v, label]) => `<option value="${ESC(v)}">${ESC(v + ' - ' + label)}</option>`).join('');
    }
    function FilterData() 
    {
        return Object.fromEntries(new FormData(GetElement('logFilters')));
    }
    function Selected() 
    {
        STATE.selected.clear();
        if (GetElement('logSelectAll')) 
        {
            GetElement('logSelectAll').checked = false;
            GetElement('logSelectAll').indeterminate = false;
        }
        UpdateSelected();
    }
    function UpdateSelected() 
    {
        if (!GetElement('logSelected')) 
        {
            return;
        }
        GetElement('logSelected').textContent = STATE.selected.size + ' selected';
        GetElement('logBulkApprove').disabled = STATE.isBusy || !STATE.selected.size;
        GetElement('logBulkReturn').disabled = STATE.isBusy || !STATE.selected.size;
    }
    function Empty(message, cols) 
    {
        return `<tr><td colspan="${cols}"><div class="ws-state"><strong>${ESC(message)}</strong><p>${IS_PERSONAL ? 'Add a daily log to record work or leave.' : 'Try a different date or filter.'}</p></div></td></tr>`;
    }
    async function Load() 
    {
        const REQUEST = ++STATE.request;
        ClearError('dailyError');
        Selected();
        GetElement('logRows').setAttribute('aria-busy', 'true');
        GetElement('logRows').innerHTML = Empty('Loading…', IS_MONITOR ? 10 : 9);
        GetElement('logPrevious').disabled = true;
        GetElement('logNext').disabled = true;
        try 
        {
            const DATA = await Api(IS_MONITOR ? 'monitoring' : IS_WEEKLY ? 'list_weeks' : 'list_logs', null, 
            {
                ...FilterData(),
                page: STATE.page
            });
            if (REQUEST !== STATE.request) 
            {
                return;
            }
            STATE.page = DATA.page;
            STATE.pages = DATA.pages;
            STATE.rows = IS_MONITOR ? DATA.members : IS_WEEKLY ? DATA.weeks : DATA.logs;
            if (IS_MONITOR) 
            {
                RenderMonitoring(DATA);
            }
            else if (IS_WEEKLY) 
            {
                RenderWeeks(DATA.weeks);
            }
            else 
            {
                RenderLogs(DATA.logs);
            }
            GetElement('logCount').textContent = `${DATA.total} ${IS_MONITOR ? 'team member(s)' : IS_WEEKLY ? 'employee week(s)' : 'log(s)'} · Page ${DATA.page} of ${DATA.pages}`;
            GetElement('logPrevious').disabled = DATA.page <= 1;
            GetElement('logNext').disabled = DATA.page >= DATA.pages;
        }
        catch (e) 
        {
            if (REQUEST !== STATE.request) 
            {
                return;
            }
            GetError('dailyError', e);
            GetElement('logRows').innerHTML = Empty('Unable to load records', 10);
        }
        finally 
        {
            if (REQUEST === STATE.request) 
            {
                GetElement('logRows').removeAttribute('aria-busy');
            }
        }
    }
    function RenderLogs(rows) 
    {
        const SELECT = !IS_PERSONAL && IS_CAN_REVIEW;
        GetElement('logHead').innerHTML = '<tr>' + (SELECT ? '<th><span class="sr-only">Select</span></th>' : '') + '<th>Date</th>' + (!IS_PERSONAL ? '<th>Employee</th><th>Unit / Section</th>' : '') + '<th>Activity Type / Leave</th><th>Classification</th><th>Hours</th><th>Status</th><th>Action</th></tr>';
        GetElement('logRows').innerHTML = rows.length ? rows.map(r => `<tr>${SELECT ? `<td>${r.status === 'Submitted' ? `<input type="checkbox" data-select="${r.log_id}" aria-label="Select log ${r.log_id} for ${ESC(r.employee_name)} on ${ESC(r.activity_date)}">` : ''}</td>` : ''}<td>${ESC(DisplayDate(r.activity_date))}</td>${!IS_PERSONAL ? `<td class="dl-person"><strong>${ESC(r.employee_name)}</strong><small>${ESC(r.employee_id)}</small></td><td>${ESC(r.unit_name || 'Unassigned')}</td>` : ''}<td>${EntryLabel(r)}</td><td>${ESC(r.classification || '—')}</td><td>${Hours(r.duration_hours)}</td><td>${Badge(r.status)}</td><td><div class="ws-actions"><button type="button" class="btn btn-sm" data-view="${r.log_id}">${r.status === 'Submitted' && !IS_PERSONAL && IS_CAN_REVIEW ? 'View / Review' : 'View'}</button>${IS_PERSONAL && ['Draft', 'Submitted', 'Returned'].includes(r.status) && ACCESS.edit ? `<button type="button" class="btn btn-sm" data-edit="${r.log_id}">Edit</button>` : ''}</div></td></tr>`).join('') : Empty('No daily logs found', IS_PERSONAL ? 7 : 10);
    }
    function EntryLabel(r) 
    {
        if (r.entry_kind === 'leave') 
        {
            return ESC(r.leave_type || 'Leave') + `<small>${r.leave_type === 'UT' ? 'Undertime' : ESC({
        AM: 'AM leave',
        PM: 'PM leave',
        WHOLE: 'Whole day'
      }[r.leave_period] || 'Leave')}</small>`;
        }
        return ESC(r.activity_type || '—');
    }
    function RenderMonitoring(data) 
    {
        GetElement('weekCovered').textContent = DisplayDate(data.week_start) + ' – ' + DisplayDate(data.week_end);
        GetElement('filterWeek').value = data.week_start;
        GetElement('logHead').innerHTML = '<tr><th>Employee</th><th>Unit / Section</th>' + data.days.map(d => `<th class="dl-day">${new Date(d + 'T12:00:00').toLocaleDateString('en-PH', {
      weekday: 'short'
    })}<small>${ESC(d.slice(5))}</small></th>`).join('') + '<th>Weekly Work / Target</th><th>Status</th><th>Action</th></tr>';
        GetElement('logHead').closest('table').classList.add('dl-week-table');
        GetElement('logRows').innerHTML = data.members.length ? data.members.map(m => `<tr><td class="dl-person"><strong>${ESC(m.employee_name)}</strong><small>${ESC(m.employee_id)}</small><div class="dl-input-progress"><strong>${Hours(m.entered_hours)} hrs entered</strong><small>${Hours(m.remaining_hours)} hrs still needed</small></div></td><td>${ESC(m.unit_name || 'Unassigned')}</td>${m.days.map(d => `<td class="dl-day ${!d.working ? 'dl-nonworking' : d.remaining_hours === 0 ? 'dl-complete' : 'dl-incomplete'}"><strong>${Hours(d.entered_hours)}</strong><small>${d.holiday ? ESC(d.holiday) : Hours(d.remaining_hours) + ' remaining'}</small></td>`).join('')}<td class="dl-total"><strong>${Hours(m.activity_hours)} / ${Hours(m.target_hours)} approved work hrs</strong><small>${m.working_days} working days | ${Hours(m.leave_hours)} approved leave hrs</small><progress max="${m.working_days * 8 || 1}" value="${m.working_days ? Math.max(0, m.working_days * 8 - m.remaining_hours) : 1}" aria-label="${ESC(m.employee_name)} entered hours"></progress></td><td><span class="ws-tag ${m.complete ? 'green' : m.input_complete ? 'amber' : 'red'}">${m.complete ? 'Approved complete' : m.input_complete ? 'Ready for review' : 'Hours needed'}</span></td><td><button type="button" class="btn btn-sm" data-member="${m.user_id}">View Logs</button></td></tr>`).join('') : Empty('No team members found', 10);
    }
    function SyncActivity() 
    {
        const CODE = GetElement('entryActivity').value;
        const DESCRIPTION = STATE.catalog?.activity_descriptions?.[CODE];
        GetElement('entryActivityHelpTitle').textContent = DESCRIPTION ? CODE + ' · ' + STATE.catalog.activities[CODE] : 'Activity guide';
        GetElement('entryActivityHelpText').textContent = DESCRIPTION || 'Choose an activity type to see its description.';
    }
    function OpenEditor(row = null) 
    {
        STATE.editing = row;
        GetElement('logForm').reset();
        ClearError('logFormError');
        const FORM = GetElement('logForm');
        FORM.elements.namedItem('log_id').value = '';
        FORM.elements.namedItem('version').value = '';
        for (const [KEY, VALUE] of Object.entries(row || 
        {

        })) 
        {
            const FIELD = FORM.elements.namedItem(KEY);
            if (FIELD) 
            {
                FIELD.value = VALUE ?? '';
            }
        }
        if (!row) 
        {
            GetElement('entryDate').value = STATE.catalog.today;
        }
        const IS_SUBMITTED = row?.status === 'Submitted';
        GetElement('logEditorTitle').textContent = row ? 'Edit Daily Log' : 'Add Daily Log';
        GetElement('logEditorHint').textContent = IS_SUBMITTED ? 'Changes remain submitted for weekly approval. Reviewers must refresh the week before approving.' : 'Keep a draft until you are ready to submit for review.';
        GetElement('logSaveDraft').hidden = IS_SUBMITTED;
        GetElement('logSubmit').textContent = IS_SUBMITTED ? 'Save Changes' : 'Submit';
        SyncActivity();
        WorkingDay();
        GetElement('logEditor').showModal();
    }
    let dayRequest = 0;
    async function WorkingDay() 
    {
        const REQUEST = ++dayRequest;
        if (!GetElement('entryDate').value) 
        {
            return;
        }
        try 
        {
            const D = await Api('calendar', null, 
            {
                date: GetElement('entryDate').value
            });
            if (REQUEST === dayRequest) 
            {
                GetElement('workingDayHint').textContent = D.working ? 'Working day | 8 required hours' : (D.holiday || 'Weekend') + ' | no required hours. Work may still be recorded.';
            }
        }
        catch (e) 
        {
            if (REQUEST === dayRequest) 
            {
                GetElement('workingDayHint').textContent = e.message;
            }
        }
    }
    async function Edit(id) 
    {
        try 
        {
            const ROW = await Api('get_log', null, 
            {
                log_id: id
            });
            OpenEditor(ROW);
        }
        catch (e) 
        {
            GetError('dailyError', e);
        }
    }
    function EditorBusy(busy) 
    {
        STATE.isBusy = busy;
        for (const ID of ['logSaveDraft', 'logSubmit']) 
        {
            GetElement(ID).disabled = busy;
        }
    }
    async function Save(status) 
    {
        if (STATE.isBusy) 
        {
            return;
        }
        ClearError('logFormError');
        if (!GetElement('entryDate').reportValidity()) 
        {
            return;
        }
        if (status === 'Submitted' && !GetElement('logForm').reportValidity()) 
        {
            return;
        }
        const BODY = Object.fromEntries(new FormData(GetElement('logForm')));
        BODY.status = status;
        EditorBusy(true);
        try 
        {
            await Api('save_log', BODY);
            GetElement('logEditor').close();
            AT.toast(status === 'Draft' ? 'Daily log saved as draft.' : STATE.editing?.status === 'Submitted' ? 'Daily log updated and remains submitted for review.' : 'Daily log submitted for review.');
            await Load();
        }
        catch (e) 
        {
            GetError('logFormError', e);
        }
        finally 
        {
            EditorBusy(false);
        }
    }
    async function Detail(id) 
    {
        try 
        {
            const R = await Api('get_log', null, 
            {
                log_id: id
            });
            STATE.detail = R;
            ClearError('detailError');
            GetElement('detailSubtitle').textContent = R.employee_name + ' · ' + DisplayDate(R.activity_date);
            const FIELDS = [['Status', R.status], ['Unit / Section', R.unit_name || 'Unassigned'], ['Entry Type', R.entry_kind === 'leave' ? 'Leave / Undertime' : 'Work activity']];
            const C = STATE.catalog;
            if (R.entry_kind === 'activity') 
            {
                FIELDS.push(['Classification', R.classification ? R.classification + ' - ' + C.classifications[R.classification] : '—'], ['Activity Type', R.activity_type ? R.activity_type + ' - ' + C.activities[R.activity_type] : '—'], ['Projected Activity / Planned Task', R.planned_task || '—'], ['Actual Activity Performed', R.actual_activity || '—']);
            }
            else 
            {
                FIELDS.push(['Leave Type', R.leave_type ? R.leave_type + ' - ' + C.leaves[R.leave_type] : '—'], ['Leave Period', R.leave_type === 'UT' ? 'Undertime' : 
                {
                    AM: 'AM',
                    PM: 'PM',
                    WHOLE: 'Whole day'
                }
                [R.leave_period] || '—']);
            }
            FIELDS.push(['Duration in Hours', Hours(R.duration_hours)], ['Remarks', R.remarks || '—']);
            if (R.submitted_at) 
            {
                FIELDS.push(['Submitted', new Date(R.submitted_at).toLocaleString('en-PH', 
                {
                    timeZone: 'Asia/Manila'
                })]);
            }
            if (R.review_comment) 
            {
                FIELDS.push(['Review Comment', R.review_comment]);
            }
            if (R.reviewed_at) 
            {
                FIELDS.push(['Reviewed / Returned By', R.reviewer_name || '—'], ['Review Date', new Date(R.reviewed_at).toLocaleString('en-PH', 
                {
                    timeZone: 'Asia/Manila'
                })]);
            }
            GetElement('detailFields').innerHTML = FIELDS.map(([label, value]) => `<div><dt>${ESC(label)}</dt><dd>${ESC(value)}</dd></div>`).join('');
            GetElement('detailActions').innerHTML = '<button type="button" class="btn" data-close="logDetail">Close</button>' + (IS_PERSONAL && ['Draft', 'Submitted', 'Returned'].includes(R.status) ? (ACCESS.delete && ['Draft', 'Returned'].includes(R.status) ? '<button type="button" class="btn btn-danger" id="detailDelete">Delete</button>' : '') + (ACCESS.edit ? '<button type="button" class="btn btn-primary" id="detailEdit">Edit Log</button>' : '') : '');
            if (!GetElement('logDetail').open) 
            {
                GetElement('logDetail').showModal();
            }
        }
        catch (e) 
        {
            GetError('dailyError', e);
        }
    }
    const WeekKey = r => r.user_id + ':' + r.week_start;
    function RenderWeeks(rows) 
    {
        GetElement('logHead').innerHTML = '<tr>' + (IS_CAN_REVIEW ? '<th><span class="sr-only">Select employee weeks</span></th>' : '') + '<th>Week</th><th>Employee</th><th>Unit / Section</th><th>Entries</th><th>Hours</th><th>Status</th><th>Action</th></tr>';
        GetElement('logRows').innerHTML = rows.length ? rows.map(r => `<tr>${IS_CAN_REVIEW ? `<td>${r.status === 'Submitted' ? `<input type="checkbox" data-select="${ESC(WeekKey(r))}" aria-label="Select week for ${ESC(r.employee_name)}">` : ''}</td>` : ''}<td>${ESC(DisplayDate(r.week_start))}<small>to ${ESC(DisplayDate(r.week_end))}</small></td><td><strong>${ESC(r.employee_name)}</strong><small>${ESC(r.employee_id)}</small></td><td>${ESC(r.unit_name || 'Unassigned')}</td><td>${r.log_count}</td><td>${Hours(r.hours)}</td><td>${Badge(r.status)}</td><td><button type="button" class="btn btn-sm" data-week="${ESC(WeekKey(r))}">View Week</button></td></tr>`).join('') : Empty('No employee weeks found', 8);
    }
    async function OpenWeek(key) 
    {
        const ROW = STATE.rows.find(r => WeekKey(r) === key);
        if (!ROW) 
        {
            return;
        }
        try 
        {
            STATE.week = await Api('get_week', null, 
            {
                user_id: ROW.user_id,
                week_start: ROW.week_start
            });
            const W = STATE.week;
            ClearError('weekReviewError');
            GetElement('weekReviewTitle').textContent = W.employee.employee_name + ' - Weekly Review';
            GetElement('weekReviewSubtitle').textContent = DisplayDate(W.week_start) + ' | ' + DisplayDate(W.week_end) + ' | ' + W.logs.length + ' entries | ' + Hours(W.hours) + ' hours';
            GetElement('weekReviewPrevious').textContent = W.decision?.review_comment || '';
            GetElement('weekReviewComment').value = '';
            GetElement('weekReviewRows').innerHTML = W.logs.map(r => `<div class="wf-note"><p>${ESC(DisplayDate(r.activity_date))} | ${ESC(r.activity_type || r.leave_type)} | ${Hours(r.duration_hours)} hrs ${Badge(r.status)}</p><p>${ESC(r.actual_activity || r.remarks || '')}</p><button type="button" class="btn btn-sm" data-view="${r.log_id}">View Entry</button></div>`).join('');
            const PERMITTED = IS_CAN_REVIEW && W.can_review;
            GetElement('weekApprove').hidden = !PERMITTED || W.status !== 'Submitted';
            GetElement('weekReturn').hidden = !PERMITTED || W.status === 'Returned';
            GetElement('weekReviewCommentRow').hidden = !PERMITTED;
            GetElement('weekReview').showModal();
        }
        catch (e) 
        {
            GetError('dailyError', e);
        }
    }
    async function Review(decision, isBulk = false) 
    {
        if (STATE.isBusy) 
        {
            return;
        }
        const SOURCE = isBulk ? [...STATE.selected.values()] : [STATE.week];
        const ITEMS = SOURCE.map(r => (
        {
            user_id: r.user_id,
            week_start: r.week_start,
            token: r.token
        }));
        const REVIEW_COMMENT = GetElement(isBulk ? 'bulkComment' : 'weekReviewComment').value.trim();
        if (decision === 'Returned' && !REVIEW_COMMENT) 
        {
            GetError(isBulk ? 'dailyError' : 'weekReviewError', new Error('Enter a comment explaining what needs correcting.'));
            return;
        }
        STATE.isBusy = true;
        UpdateSelected();
        for (const ID of ['weekApprove', 'weekReturn']) 
        {
            if (GetElement(ID)) 
            {
                GetElement(ID).disabled = true;
            }
        }
        try 
        {
            const RESULT = await Api('review_weeks', 
            {
                items: ITEMS,
                decision,
                review_comment: REVIEW_COMMENT
            });
            if (!isBulk) 
            {
                GetElement('weekReview').close();
            }
            else 
            {
                GetElement('bulkComment').value = '';
            }
            AT.toast(RESULT.reviewed + ' employee week(s) ' + (decision === 'Reviewed' ? 'approved.' : 'returned.'));
            await Load();
        }
        catch (e) 
        {
            GetError(isBulk ? 'dailyError' : 'weekReviewError', e);
        }
        finally 
        {
            STATE.isBusy = false;
            UpdateSelected();
            for (const ID of ['weekApprove', 'weekReturn']) 
            {
                if (GetElement(ID)) 
                {
                    GetElement(ID).disabled = false;
                }
            }
        }
    }
    async function DeleteLog() 
    {
        if (STATE.isBusy || !(await AT.confirmAction('Delete this daily log?'))) 
        {
            return;
        }
        STATE.isBusy = true;
        try 
        {
            await Api('delete_log', 
            {
                log_id: STATE.detail.log_id,
                version: STATE.detail.version
            });
            GetElement('logDetail').close();
            AT.toast('Daily log deleted.');
            await Load();
        }
        catch (e) 
        {
            GetError('detailError', e);
        }
        finally 
        {
            STATE.isBusy = false;
        }
    }
    async function MemberLogs(id = null) 
    {
        if (id !== null) 
        {
            STATE.member = STATE.rows.find(r => String(r.user_id) === String(id));
            STATE.memberPage = 1;
        }
        const REQUEST = ++STATE.memberRequest;
        const M = STATE.member;
        if (!M) 
        {
            return;
        }
        GetElement('memberLogsTitle').textContent = M.employee_name + ' · Daily Logs';
        GetElement('memberLogRows').textContent = 'Loading…';
        if (!GetElement('memberLogs').open) 
        {
            GetElement('memberLogs').showModal();
        }
        try 
        {
            const DATA = await Api('list_logs', null, 
            {
                user_id: M.user_id,
                status: '',
                date_from: GetElement('filterWeek').value,
                date_to: STATE.rows[0]?.days[4]?.date || '',
                page: STATE.memberPage
            });
            if (REQUEST !== STATE.memberRequest) 
            {
                return;
            }
            STATE.memberPage = DATA.page;
            STATE.memberPages = DATA.pages;
            GetElement('memberLogRows').innerHTML = DATA.logs.length ? DATA.logs.map(r => `<div class="wf-note"><div><p>${ESC(DisplayDate(r.activity_date))} · ${ESC(r.activity_type || r.leave_type || '—')} · ${Hours(r.duration_hours)} hrs</p>${Badge(r.status)}</div><button type="button" class="btn btn-sm" data-view="${r.log_id}">View</button></div>`).join('') : '<div class="ws-state">No submitted logs for this week.</div>';
            GetElement('memberLogCount').textContent = `${DATA.total} log(s) · Page ${DATA.page} of ${DATA.pages}`;
            GetElement('memberPrevious').disabled = DATA.page <= 1;
            GetElement('memberNext').disabled = DATA.page >= DATA.pages;
        }
        catch (e) 
        {
            if (REQUEST === STATE.memberRequest) 
            {
                GetElement('memberLogRows').textContent = e.message;
            }
        }
    }
    async function LoadReviewers() 
    {
        ClearError('sectionReviewersError');
        GetElement('sectionReviewerRows').textContent = 'Loading…';
        GetElement('sectionReviewersRefresh').disabled = true;
        try 
        {
            const DATA = await Api('reviewer_assignments');
            reviewerUnits = DATA.units;
            GetElement('sectionReviewerRows').innerHTML = DATA.units.length ? DATA.units.map(u => 
            {
                const CANDIDATES = DATA.candidates.filter(c => String(c.unit_id) === String(u.unit_id));
                const CURRENT = u.reviewer_user_id ? String(u.reviewer_user_id) : '';
                const MISSING = CURRENT && !CANDIDATES.some(c => String(c.user_id) === CURRENT);
                return `<form class="dl-reviewer-row" data-reviewer-unit="${u.unit_id}"><div><strong>${ESC(u.unit_name)}</strong><small>${ESC(u.department_name)}</small>${MISSING ? '<small class="dl-reviewer-warning">The current reviewer is unavailable or no longer eligible. Reassign this section.</small>' : ''}</div><div><label for="sectionReviewer_${u.unit_id}">Assigned reviewer</label><select id="sectionReviewer_${u.unit_id}" name="reviewer_user_id"><option value="">Unassigned (administrator only)</option>${MISSING ? `<option value="${ESC(CURRENT)}" selected disabled>${ESC(u.reviewer_name)} — unavailable</option>` : ''}${CANDIDATES.map(c => `<option value="${c.user_id}" ${String(c.user_id) === CURRENT ? 'selected' : ''}>${ESC(c.employee_name + ' · ' + c.employee_id)}</option>`).join('')}</select></div><button type="submit" class="btn btn-primary">Save</button></form>`;
            }).join('') : '<div class="ws-state">Create a unit / section in Organization before assigning reviewers.</div>';
            GetElement('sectionReviewersStatus').textContent = DATA.units.length + ' unit(s) / section(s)';
        }
        catch (e) 
        {
            GetError('sectionReviewersError', e);
            GetElement('sectionReviewerRows').textContent = 'Unable to load reviewer assignments.';
        }
        finally 
        {
            GetElement('sectionReviewersRefresh').disabled = false;
        }
    }
    GetElement('sectionReviewersOpen')?.addEventListener('click', () => 
    {
        GetElement('sectionReviewers').showModal();
        LoadReviewers();
    });
    GetElement('sectionReviewersRefresh')?.addEventListener('click', LoadReviewers);
    GetElement('sectionReviewerRows')?.addEventListener('submit', async e => 
    {
        e.preventDefault();
        if (isReviewerSaving) 
        {
            return;
        }
        const FORM = e.target;
        const UNIT = reviewerUnits.find(u => String(u.unit_id) === FORM.dataset.reviewerUnit);
        if (!UNIT) 
        {
            return;
        }
        isReviewerSaving = true;
        ClearError('sectionReviewersError');
        GetElement('sectionReviewers').querySelectorAll('button,select').forEach(el => el.disabled = true);
        try 
        {
            await Api('save_reviewer', 
            {
                unit_id: UNIT.unit_id,
                reviewer_user_id: Number(FORM.elements.reviewer_user_id.value) || 0,
                assignment_version: UNIT.assignment_version
            });
            AT.toast('Section reviewer assignment saved.');
            await LoadReviewers();
            await Load();
        }
        catch (err) 
        {
            GetError('sectionReviewersError', err);
        }
        finally 
        {
            isReviewerSaving = false;
            GetElement('sectionReviewers').querySelectorAll('button,select').forEach(el => el.disabled = false);
        }
    });
    GetElement('logFilters').addEventListener('submit', e => 
    {
        e.preventDefault();
        STATE.page = 1;
        Load();
    });
    GetElement('logReset').addEventListener('click', () => 
    {
        GetElement('logFilters').reset();
        if (IS_MONITOR) 
        {
            GetElement('filterWeek').value = STATE.catalog.today;
        }
        STATE.page = 1;
        Load();
    });
    GetElement('logRefresh').addEventListener('click', () => Load());
    GetElement('logPrevious').addEventListener('click', () => 
    {
        STATE.page--;
        Load();
    });
    GetElement('logNext').addEventListener('click', () => 
    {
        STATE.page++;
        Load();
    });
    document.addEventListener('click', e => 
    {
        const B = e.target.closest('button');
        if (!B) 
        {
            return;
        }
        if (B.dataset.close && !STATE.isBusy) 
        {
            GetElement(B.dataset.close).close();
        }
        if (B.dataset.view) 
        {
            Detail(B.dataset.view);
        }
        if (B.dataset.edit) 
        {
            Edit(B.dataset.edit);
        }
        if (B.dataset.member) 
        {
            MemberLogs(B.dataset.member);
        }
        if (B.id === 'detailEdit') 
        {
            GetElement('logDetail').close();
            OpenEditor(STATE.detail);
        }
        if (B.id === 'detailDelete') 
        {
            DeleteLog();
        }
        if (B.dataset.week) 
        {
            OpenWeek(B.dataset.week);
        }
        if (B.id === 'weekApprove') 
        {
            Review('Reviewed');
        }
        if (B.id === 'weekReturn') 
        {
            Review('Returned');
        }
    });
    GetElement('logRows').addEventListener('change', e => 
    {
        const ID = e.target.dataset.select;
        if (!ID) 
        {
            return;
        }
        const R = STATE.rows.find(r => WeekKey(r) === ID);
        if (e.target.checked) 
        {
            STATE.selected.set(WeekKey(R), R);
        }
        else 
        {
            STATE.selected.delete(WeekKey(R));
        }
        UpdateSelected();
        const ALL = GetElement('logSelectAll');
        if (ALL) 
        {
            const BOXES = [...GetElement('logRows').querySelectorAll('[data-select]')];
            ALL.checked = BOXES.length > 0 && BOXES.every(b => b.checked);
            ALL.indeterminate = STATE.selected.size > 0 && !ALL.checked;
        }
    });
    GetElement('logSelectAll')?.addEventListener('change', e => 
    {
        GetElement('logRows').querySelectorAll('[data-select]').forEach(b => 
        {
            b.checked = e.target.checked;
            const R = STATE.rows.find(r => WeekKey(r) === b.dataset.select);
            if (b.checked) 
            {
                STATE.selected.set(WeekKey(R), R);
            }
            else 
            {
                STATE.selected.delete(WeekKey(R));
            }
        });
        UpdateSelected();
    });
    GetElement('logBulkApprove')?.addEventListener('click', () => Review('Reviewed', true));
    GetElement('logBulkReturn')?.addEventListener('click', () => Review('Returned', true));
    if (IS_PERSONAL) 
    {
        GetElement('entryActivity').addEventListener('change', SyncActivity);
        GetElement('entryDate').addEventListener('change', WorkingDay);
        GetElement('logAdd').addEventListener('click', () => OpenEditor());
        GetElement('logSaveDraft').addEventListener('click', () => Save('Draft'));
        GetElement('logForm').addEventListener('submit', e => 
        {
            e.preventDefault();
            Save('Submitted');
        });
    }
    if (IS_MONITOR) 
    {
        GetElement('memberPrevious').addEventListener('click', () => 
        {
            STATE.memberPage--;
            MemberLogs();
        });
        GetElement('memberNext').addEventListener('click', () => 
        {
            STATE.memberPage++;
            MemberLogs();
        });
    }
    for (const D of document.querySelectorAll('#logEditor,#logDetail')) 
    {
        D.addEventListener('cancel', e => 
        {
            if (STATE.isBusy) 
            {
                e.preventDefault();
            }
        });
    }
    async function Init() 
    {
        try 
        {
            STATE.catalog = await Api('catalog');
            const C = STATE.catalog;
            if (IS_PERSONAL) 
            {
                Options('entryClass', C.classifications);
                Options('entryActivity', C.activities);
            }
            else 
            {
                GetElement('filterUnit').innerHTML = '<option value="">All authorized units</option>' + C.units.map(u => `<option value="${u.unit_id}">${ESC(u.unit_name)}</option>`).join('');
                if (C.profile.unit_id) 
                {
                    GetElement('filterUnit').value = C.profile.unit_id;
                }
            }
            if (IS_MONITOR) 
            {
                GetElement('filterWeek').value = C.today;
            }
            const INITIAL = new URLSearchParams(location.search);
            for (const FIELD of GetElement('logFilters').elements) 
            {
                if (FIELD.name && INITIAL.has(FIELD.name)) 
                {
                    const VALUE = INITIAL.get(FIELD.name);
                    if (FIELD.tagName !== 'SELECT' || [...FIELD.options].some(o => o.value === VALUE)) 
                    {
                        FIELD.value = VALUE;
                    }
                }
            }
            if (IS_WEEKLY && INITIAL.has('week_start')) 
            {
                GetElement('filterFrom').value = INITIAL.get('week_start');
                GetElement('filterTo').value = INITIAL.get('week_start');
            }
            await Load();
            if (IS_PERSONAL && INITIAL.has('log_id')) 
            {
                await Detail(Number(INITIAL.get('log_id')));
            }
            if (IS_PERSONAL && INITIAL.get('new') === '1' && ACCESS.create) 
            {
                OpenEditor();
            }
            if (IS_MONITOR && INITIAL.has('user_id')) 
            {
                const ID = Number(INITIAL.get('user_id'));
                if (STATE.rows.some(r => Number(r.user_id) === ID)) 
                {
                    await MemberLogs(ID);
                }
            }
            if (IS_WEEKLY && INITIAL.has('week_start') && INITIAL.has('user_id')) 
            {
                await OpenWeek(INITIAL.get('user_id') + ':' + INITIAL.get('week_start'));
            }
        }
        catch (e) 
        {
            GetError('dailyError', e);
            GetElement('logRows').innerHTML = Empty('Unable to load daily logs', 10);
        }
    }
    Init();
})();
