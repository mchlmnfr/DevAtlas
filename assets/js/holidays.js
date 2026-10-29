/**
 * Filename: holidays.js
 * Purpose: Coordinate holidays interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

(() => 
{
    const GetElement = id => document.getElementById(id);
    const ESC = AT.escapeHtml;
    const ROOT = GetElement('holidaysApp');
    const FORM = GetElement('holidayForm');
    let rows = [];
    let isBusy = false;
    let request = 0;
    const Api = (action, body) => AT.api(ROOT.dataset.api + '?action=' + action + (body ? '' : '&year=' + encodeURIComponent(GetElement('holidayYear').value)), body ? 
    {
        method: 'POST',
        body
    }
    : 
    {

    });
    function GetError(message = '') 
    {
        GetElement('holidayError').textContent = message;
        GetElement('holidayError').hidden = !message;
    }
    function Reset() 
    {
        FORM.reset();
        FORM.elements.holiday_id.value = '';
        FORM.elements.version.value = '';
        GetElement('holidayEditorTitle').textContent = 'Add holiday';
    }
    function Lock(value) 
    {
        isBusy = value;
        ROOT.querySelectorAll('button').forEach(button => button.disabled = value);
    }
    async function Load() 
    {
        const TOKEN = ++request;
        GetError();
        GetElement('holidayCount').textContent = 'Loading...';
        try 
        {
            const DATA = await Api('holidays');
            if (TOKEN !== request) 
            {
                return;
            }
            rows = DATA.holidays;
            GetElement('holidayCount').textContent = rows.length + ' ' + (rows.length === 1 ? 'holiday' : 'holidays') + ' in ' + DATA.year;
            GetElement('holidayRows').innerHTML = rows.length ? rows.map(h => 
            {
                const DATE = new Date(h.holiday_date + 'T00:00:00Z');
                const IS_WEEKEND = [0, 6].includes(DATE.getUTCDay());
                return '<tr><td>' + ESC(DATE.toLocaleDateString('en-US', 
                {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    weekday: 'short',
                    timeZone: 'UTC'
                })) + '</td><td><strong>' + ESC(h.holiday_name) + '</strong></td><td>' + (IS_WEEKEND ? 'Weekend - no weekday target change' : '8 required hours excluded per employee') + '</td>' + (FORM ? '<td><div class="ws-actions"><button type="button" class="btn btn-sm" data-holiday-edit="' + h.holiday_id + '">Edit</button><button type="button" class="btn btn-sm" data-holiday-delete="' + h.holiday_id + '">Delete</button></div></td>' : '') + '</tr>';
            }).join('') : '<tr><td colspan="' + (FORM ? 4 : 3) + '"><div class="ws-state">No configured holidays for this year.</div></td></tr>';
        }
        catch (e) 
        {
            if (TOKEN !== request) 
            {
                return;
            }
            GetError(e.message);
            GetElement('holidayCount').textContent = 'Unable to load holidays';
            GetElement('holidayRows').innerHTML = '<tr><td colspan="4"><div class="ws-state">Refresh to try again.</div></td></tr>';
        }
        if (isBusy) 
        {
            ROOT.querySelectorAll('button').forEach(button => button.disabled = true);
        }
    }
    GetElement('holidayFilters').addEventListener('submit', e => 
    {
        e.preventDefault();
        if (!isBusy) 
        {
            Load();
        }
    });
    GetElement('holidayYear').addEventListener('change', () => 
    {
        if (!isBusy && GetElement('holidayFilters').reportValidity()) 
        {
            Load();
        }
    });
    GetElement('holidayReset')?.addEventListener('click', Reset);
    FORM?.addEventListener('submit', async e => 
    {
        e.preventDefault();
        if (isBusy) 
        {
            return;
        }
        Lock(true);
        GetError();
        try 
        {
            await Api('save_holiday', Object.fromEntries(new FormData(FORM)));
            GetElement('holidayYear').value = GetElement('holidayDate').value.slice(0, 4);
            Reset();
            await Load();
            AT.toast('Organization holiday saved.');
        }
        catch (e) 
        {
            GetError(e.message);
        }
        finally 
        {
            Lock(false);
        }
    });
    GetElement('holidayRows').addEventListener('click', async e => 
    {
        const BUTTON = e.target.closest('button');
        if (!BUTTON || isBusy || !FORM) 
        {
            return;
        }
        const ROW = rows.find(h => String(h.holiday_id) === (BUTTON.dataset.holidayEdit || BUTTON.dataset.holidayDelete));
        if (!ROW) 
        {
            return;
        }
        if (BUTTON.dataset.holidayEdit) 
        {
            for (const [KEY, VALUE] of Object.entries(ROW)) 
            {
                const INPUT = FORM.elements.namedItem(KEY);
                if (INPUT) 
                {
                    INPUT.value = VALUE ?? '';
                }
            }
            GetElement('holidayEditorTitle').textContent = 'Edit holiday';
            GetElement('holidayDate').focus();
            return;
        }
        if (!(await AT.confirmAction('Delete this organization holiday? Required hours will be recalculated.'))) 
        {
            return;
        }
        Lock(true);
        GetError();
        try 
        {
            await Api('delete_holiday', 
            {
                holiday_id: ROW.holiday_id,
                version: ROW.version
            });
            if (FORM.elements.holiday_id.value === String(ROW.holiday_id)) 
            {
                Reset();
            }
            await Load();
            AT.toast('Organization holiday deleted.');
        }
        catch (e) 
        {
            GetError(e.message);
        }
        finally 
        {
            Lock(false);
        }
    });
    Load();
})();