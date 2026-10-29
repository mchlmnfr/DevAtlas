/**
 * Filename: leaves.js
 * Purpose: Render department leave calendars, personal applications and section approvals.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Separate application and approval screens, date ranges and recurring department birthdays.
 */
(() =>
{
    'use strict';
    const ROOT = document.getElementById('leavesApp') || document.getElementById('leaveApprovalsApp');
    const IS_APPROVALS = ROOT.id === 'leaveApprovalsApp';
    const GetElement = id => document.getElementById(id);
    const ESC = AT.escapeHtml;
    const STATE =
    {
        data: null,
        editing: null,
        detail: null,
        minePage: 1,
        reviewPage: 1,
        isBusy: false,
        request: 0
    };
    const INITIAL = new URLSearchParams(location.search);
    const TODAY = new Intl.DateTimeFormat('en-CA',
    {
        timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'
    }).format(new Date());
    const Api = (action, body = null, query =
    {
    }) => AT.api(ROOT.dataset.api + '?' + new URLSearchParams(
    {
        action, ...query
    }), body ?
    {
        method: 'POST', body
    }
    :
    {
    });
    const Hours = value => Number(value || 0).toFixed(2);
    const Label = row => row.leave_type === 'UT' ? 'UT · Undertime' : `${row.leave_type || '—'} · ${{ AM: 'AM', PM: 'PM', WHOLE: 'Whole day' }[row.leave_period] || 'Not selected'}`;
    const Status = status => status === 'Reviewed' ? 'Approved' : status === 'Submitted' ? 'Pending approval' : status;
    const Badge = status => `<span class="ws-tag ${status === 'Reviewed' ? 'green' : status === 'Submitted' ? 'amber' : status === 'Returned' ? 'red' : 'gray'}">${ESC(Status(status))}</span>`;
    function ErrorAt(id, message = '')
    {
        GetElement(id).textContent = message;
        GetElement(id).toggleAttribute('hidden', !message);
        if (message)
        {
            GetElement(id).focus();
        }
    }
    function Busy(isBusy)
    {
        STATE.isBusy = isBusy;
        for (const ID of ['leaveSaveDraft', 'leaveSubmit', 'leaveApprove', 'leaveReturn', 'leaveDelete'])
        {
            if (GetElement(ID))
            {
                GetElement(ID).disabled = isBusy;
            }
        }
    }
    function RenderCalendar(data)
    {
        const BirthdayCards = date => (data.birthdays || []).filter(person => person.date === date).map(person => `<div class="leave-birthday"><strong>${AT.icon('calendar-days')} ${ESC(person.employee_name)}</strong><small>Birthday</small></div>`).join('');
        const EVENTS = new Map();
        for (const ROW of data.leaves)
        {
            if (!EVENTS.has(ROW.activity_date))
            {
                EVENTS.set(ROW.activity_date, []);
            }
            EVENTS.get(ROW.activity_date).push(ROW);
        }
        const DAYS = [];
        for (let date = new Date(data.from + 'T12:00:00'); date <= new Date(data.to + 'T12:00:00'); date.setDate(date.getDate() + 1))
        {
            const KEY = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
            const HOLIDAY = data.holidays[KEY]?.holiday_name;
            const IS_WORKING = date.getDay() !== 0 && date.getDay() !== 6 && !HOLIDAY;
            DAYS.push(`<div role="cell" class="leave-calendar-day ${KEY.startsWith(data.month) ? '' : 'is-outside'} ${KEY === TODAY ? 'is-today' : ''}" aria-label="${KEY}${HOLIDAY ? ', ' + ESC(HOLIDAY) : ''}"><div class="leave-calendar-date"><time datetime="${KEY}">${date.getDate()}</time>${ROOT.dataset.create === '1' && IS_WORKING ? `<button type="button" class="btn btn-sm" data-leave-date="${KEY}" aria-label="Apply for leave on ${KEY}">${AT.icon('plus')}</button>` : ''}</div>${HOLIDAY ? `<div class="leave-calendar-holiday">${ESC(HOLIDAY)}</div>` : ''}${(EVENTS.get(KEY) || []).map(row => `<button type="button" class="leave-event ${row.status === 'Reviewed' ? 'is-approved' : ''}" data-leave-view="${row.log_id}"><strong>${ESC(row.employee_name)}</strong><small>${ESC(Label(row))} · ${Hours(row.duration_hours)}h</small><small>${ESC(Status(row.status))}</small></button>`).join('')}${BirthdayCards(KEY)}</div>`);
        }
        let html = '<div role="row" class="leave-calendar-row leave-calendar-head">' + ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(day => `<div role="columnheader">${day}</div>`).join('') + '</div>';
        for (let index = 0; index < DAYS.length; index += 7)
        {
            html += '<div role="row" class="leave-calendar-row">' + DAYS.slice(index, index + 7).join('') + '</div>';
        }
        GetElement('leaveCalendar').innerHTML = html;
        GetElement('leaveCalendar').setAttribute('aria-busy', 'false');
        GetElement('leaveCalendarTitle').textContent = new Date(data.month + '-01T12:00:00').toLocaleDateString('en-PH',
        {
            month: 'long', year: 'numeric'
        }) + ' · Leaves';
        const DEPARTMENT = data.departments.find(row => Number(row.department_id) === Number(data.department_id))?.department_name || data.profile.department_name;
        GetElement('leaveScope').textContent = DEPARTMENT ? DEPARTMENT + ' · All sections in this department' : 'Your own calendar. Assign your account to a department and section to share and submit leave.';
        GetElement('leaveCalendarCount').textContent = data.leaves.length + ' submitted or approved leave entries across the displayed dates.';
    }
    function RenderList(prefix, data, isReview = false)
    {
        const BODY = GetElement(prefix + 'Rows');
        if (!BODY)
        {
            return;
        }
        BODY.innerHTML = data.rows.length ? data.rows.map(row => `<tr>${isReview ? `<td>${ESC(row.employee_name)}</td>` : ''}<td>${ESC(row.date_from)}${row.date_to !== row.date_from ? '<small>to ' + ESC(row.date_to) + '</small>' : ''}</td><td>${ESC(Label(row))}</td><td>${Hours(row.duration_hours)}</td>${isReview ? '' : `<td>${Badge(row.status)}</td>`}<td><button type="button" class="btn btn-sm" data-leave-view="${row.log_id}">${isReview ? 'Review' : 'View'}</button></td></tr>`).join('') : `<tr><td colspan="5"><div class="ws-state">${isReview ? 'No pending requests in your section.' : 'No leave applications for this month.'}</div></td></tr>`;
        GetElement(prefix + 'Count').textContent = `${data.total} requests · Page ${data.page} of ${data.pages}`;
        GetElement(prefix + 'Previous').toggleAttribute('disabled', data.page <= 1);
        GetElement(prefix + 'Next').toggleAttribute('disabled', data.page >= data.pages);
    }
    async function Load()
    {
        const REQUEST = ++STATE.request;
        ErrorAt('leaveError');
        GetElement('leaveCalendar')?.setAttribute('aria-busy', 'true');
        try
        {
            const QUERY =
            {
                month: GetElement('leaveMonth')?.value || TODAY.slice(0, 7), 'mine_page': STATE.minePage, 'review_page': STATE.reviewPage
            };
            if (GetElement('leaveDepartment')?.value)
            {
                QUERY['department_id'] = GetElement('leaveDepartment').value;
            }
            const DATA = await Api('calendar', null, QUERY);
            if (REQUEST !== STATE.request)
            {
                return;
            }
            STATE.data = DATA;
            STATE.minePage = DATA.mine.page;
            STATE.reviewPage = DATA.pending.page;
            if (GetElement('leaveDepartment'))
            {
                GetElement('leaveDepartment').replaceChildren(new Option('My calendar', ''), ...DATA.departments.map(row => new Option(row.department_name, row.department_id)));
                GetElement('leaveDepartment').value = DATA.department_id || '';
            }
            if (!IS_APPROVALS)
            {
                RenderCalendar(DATA);
                RenderList('leaveMine', DATA.mine);
            }
            RenderList('leaveReview', DATA.pending, true);
        }
        catch (error)
        {
            if (REQUEST === STATE.request)
            {
                ErrorAt('leaveError', error.message);
                GetElement('leaveCalendar')?.setAttribute('aria-busy', 'false');
            }
        }
    }
    function SyncType()
    {
        const IS_UT = GetElement('leaveType').value === 'UT';
        GetElement('leavePeriodRow').hidden = IS_UT;
        GetElement('leavePeriod').disabled = IS_UT;
        GetElement('leavePeriod').toggleAttribute('required', !IS_UT);
        GetElement('leaveHours').toggleAttribute('readonly', !IS_UT);
        if (!IS_UT)
        {
            GetElement('leaveHours').value = GetElement('leavePeriod').value === 'WHOLE' ? 8 : 4;
        }
    }
    function OpenEditor(row = null, date = TODAY)
    {
        STATE.editing = row;
        GetElement('leaveForm').reset();
        ErrorAt('leaveFormError');
        GetElement('leaveForm').elements.namedItem('log_id').value = row?.log_id || '';
        GetElement('leaveForm').elements.namedItem('version').value = row?.version || '';
        for (const FIELD of ['date_from', 'date_to', 'leave_type', 'leave_period', 'duration_hours', 'remarks'])
        {
            if (row)
            {
                GetElement('leaveForm').elements.namedItem(FIELD).value = row[FIELD] ?? '';
            }
        }
        if (!row)
        {
            GetElement('leaveDate').value = date;
            GetElement('leaveDateTo').value = date;
        }
        const IS_SUBMITTED = row?.status === 'Submitted';
        GetElement('leaveEditorTitle').textContent = row ? 'Edit Leave Application' : 'Apply for Leave';
        GetElement('leaveEditorHint').textContent = IS_SUBMITTED ? 'Changes remain submitted. Your section approver must review the latest request.' : 'Your section’s authorized approver will review this request.';
        GetElement('leaveSaveDraft').hidden = IS_SUBMITTED;
        GetElement('leaveSubmit').textContent = IS_SUBMITTED ? 'Save Changes' : 'Submit Leave';
        SyncType();
        GetElement('leaveDateTo').min = GetElement('leaveDate').value;
        GetElement('leaveEditor').showModal();
    }
    async function Save(status)
    {
        if (STATE.isBusy || !GetElement('leaveDate').reportValidity() || (status === 'Submitted' && !GetElement('leaveForm').reportValidity()))
        {
            return;
        }
        ErrorAt('leaveFormError');
        Busy(true);
        try
        {
            const BODY =
            {
                ...Object.fromEntries(new FormData(GetElement('leaveForm'))), status
            };
            await Api('save_leave', BODY);
            GetElement('leaveEditor').close();
            GetElement('leaveMonth').value = BODY.date_from.slice(0, 7);
            AT.toast(status === 'Draft' ? 'Leave saved as draft.' : 'Leave submitted for section approval.');
            await Load();
        }
        catch (error)
        {
            ErrorAt('leaveFormError', error.message);
        }
        finally
        {
            Busy(false);
        }
    }
    async function Detail(id)
    {
        try
        {
            const ROW = await Api('get_leave', null,
            {
                'log_id': id
            });
            STATE.detail = ROW;
            ErrorAt('leaveDetailError');
            const FIELDS = [['Employee', ROW.employee_name], ['Section', ROW.unit_name || 'Unassigned'], ['Date From', ROW.date_from], ['Date To', ROW.date_to], ['Working days', ROW.working_days], ['Leave', Label(ROW)], ['Total hours', Hours(ROW.total_hours)], ['Hours per working day', Hours(ROW.duration_hours)], ['Status', Status(ROW.status)]];
            if ('remarks' in ROW)
            {
                FIELDS.push(['Reason / Remarks', ROW.remarks || '—'], ['Review comment', ROW.review_comment || '—'], ['Reviewed by', ROW.reviewer_name || '—']);
            }
            GetElement('leaveDetailFields').innerHTML = FIELDS.map(([label, value]) => `<dt>${ESC(label)}</dt><dd>${ESC(value)}</dd>`).join('');
            const IS_CAN_REVIEW = IS_APPROVALS && ROW.can_review;
            GetElement('leaveReviewCommentRow').toggleAttribute('hidden', !IS_CAN_REVIEW);
            GetElement('leaveReviewComment').value = '';
            GetElement('leaveDetailActions').innerHTML = '<button type="button" class="btn" data-leave-close="leaveDetail">Close</button>' + (!IS_APPROVALS && ROW.can_delete ? '<button type="button" class="btn btn-danger" id="leaveDelete">Delete</button>' : '') + (!IS_APPROVALS && ROW.can_edit ? '<button type="button" class="btn btn-primary" id="leaveEdit">Edit</button>' : '') + (!IS_APPROVALS && ROW.can_review ? `<a class="btn" href="${ESC(ROOT.dataset.approvals)}&log_id=${ROW.log_id}">Open in Approvals</a>` : '') + (IS_CAN_REVIEW ? '<button type="button" class="btn" id="leaveReturn">' + (ROW.status === 'Reviewed' ? 'Return / Reopen' : 'Return') + '</button>' + (ROW.status === 'Submitted' ? '<button type="button" class="btn btn-primary" id="leaveApprove">Approve Leave</button>' : '') : '');
            GetElement('leaveDetail').showModal();
        }
        catch (error)
        {
            ErrorAt('leaveError', error.message);
        }
    }
    async function Decide(decision)
    {
        if (STATE.isBusy)
        {
            return;
        }
        const ROW = STATE.detail;
        const COMMENT = GetElement('leaveReviewComment').value.trim();
        if (decision === 'Returned' && !COMMENT)
        {
            ErrorAt('leaveDetailError', 'Enter a comment before returning or reopening leave.');
            return;
        }
        Busy(true);
        try
        {
            if (!await AT.confirmAction(`${ROW.employee_name} · ${ROW.date_from} to ${ROW.date_to} · ${Hours(ROW.total_hours)} hours`, decision === 'Reviewed' ? 'Approve leave?' : 'Return leave?', decision === 'Reviewed' ? 'Approve Leave' : 'Return Leave'))
            {
                return;
            }
            await Api('review_leave',
            {
                'log_id': ROW.log_id, version: ROW.version, decision, 'review_comment': COMMENT
            });
            GetElement('leaveDetail').close();
            AT.toast(decision === 'Reviewed' ? 'Leave approved.' : 'Leave returned for correction.');
            await Load();
        }
        catch (error)
        {
            ErrorAt('leaveDetailError', error.message);
        }
        finally
        {
            Busy(false);
        }
    }
    async function Delete()
    {
        if (STATE.isBusy)
        {
            return;
        }
        Busy(true);
        try
        {
            if (!await AT.confirmAction('This removes your draft or returned request.', 'Delete leave application?', 'Delete'))
            {
                return;
            }
            await Api('delete_leave',
            {
                'log_id': STATE.detail.log_id, version: STATE.detail.version
            });
            GetElement('leaveDetail').close();
            AT.toast('Leave application deleted.');
            await Load();
        }
        catch (error)
        {
            ErrorAt('leaveDetailError', error.message);
        }
        finally
        {
            Busy(false);
        }
    }
    function MoveMonth(delta)
    {
        const DATE = new Date(GetElement('leaveMonth').value + '-01T12:00:00');
        DATE.setMonth(DATE.getMonth() + delta);
        if (DATE.getFullYear() < 1900 || DATE.getFullYear() > 2100)
        {
            return;
        }
        GetElement('leaveMonth').value = `${DATE.getFullYear()}-${String(DATE.getMonth() + 1).padStart(2, '0')}`;
        STATE.minePage = 1;
        Load();
    }
    document.addEventListener('click', event =>
    {
        const BUTTON = event.target.closest('button');
        if (!BUTTON || STATE.isBusy)
        {
            return;
        }
        if (BUTTON.dataset.leaveClose)
        {
            GetElement(BUTTON.dataset.leaveClose).close();
        }
        if (BUTTON.dataset.leaveView)
        {
            Detail(BUTTON.dataset.leaveView);
        }
        if (BUTTON.dataset.leaveDate)
        {
            OpenEditor(null, BUTTON.dataset.leaveDate);
        }
        if (BUTTON.id === 'leaveEdit')
        {
            GetElement('leaveDetail').close();
            OpenEditor(STATE.detail);
        }
        if (BUTTON.id === 'leaveApprove')
        {
            Decide('Reviewed');
        }
        if (BUTTON.id === 'leaveReturn')
        {
            Decide('Returned');
        }
        if (BUTTON.id === 'leaveDelete')
        {
            Delete();
        }
    });
    if (GetElement('leaveMonth'))
    {
        GetElement('leaveMonth').value = INITIAL.get('month') || TODAY.slice(0, 7);
    }
    GetElement('leaveAdd')?.addEventListener('click', () => OpenEditor());
    GetElement('leavePrevious')?.addEventListener('click', () => MoveMonth(-1));
    GetElement('leaveNext')?.addEventListener('click', () => MoveMonth(1));
    GetElement('leaveToday')?.addEventListener('click', () =>
    {
        GetElement('leaveMonth').value = TODAY.slice(0, 7);
        STATE.minePage = 1;
        Load();
    });
    GetElement('leaveFilters')?.addEventListener('submit', event =>
    {
        event.preventDefault();
        STATE.minePage = 1;
        Load();
    });
    for (const [PREFIX, KEY] of [['leaveMine', 'minePage'], ['leaveReview', 'reviewPage']])
    {
        for (const [SUFFIX, DELTA] of [['Previous', -1], ['Next', 1]])
        {
            GetElement(PREFIX + SUFFIX)?.addEventListener('click', () =>
            {
                STATE[KEY] += DELTA;
                Load();
            });
        }
    }
    GetElement('leaveType')?.addEventListener('change', () =>
    {
        GetElement('leaveHours').value = '';
        SyncType();
    });
    GetElement('leavePeriod')?.addEventListener('change', SyncType);
    GetElement('leaveDate')?.addEventListener('change', () =>
    {
        if (GetElement('leaveDateTo').value < GetElement('leaveDate').value)
        {
            GetElement('leaveDateTo').value = GetElement('leaveDate').value;
        }
        GetElement('leaveDateTo').min = GetElement('leaveDate').value;
    });
    GetElement('leaveSaveDraft')?.addEventListener('click', () => Save('Draft'));
    GetElement('leaveForm')?.addEventListener('submit', event =>
    {
        event.preventDefault();
        Save('Submitted');
    });
    for (const ID of ['leaveEditor', 'leaveDetail'])
    {
        GetElement(ID)?.addEventListener('cancel', event =>
        {
            if (STATE.isBusy)
            {
                event.preventDefault();
            }
        });
    }
    if (IS_APPROVALS)
    {
        GetElement('leaveRefresh').addEventListener('click', Load);
    }
    Load().then(() =>
    {
        if (INITIAL.has('log_id'))
        {
            Detail(INITIAL.get('log_id'));
        }
    });
})();
