/* Data Management: session-owned file validation and SQL downloads. */
(() => {
    'use strict';
    const root = document.getElementById('conversionApp');
    if (!root) return;
    const byId = id => document.getElementById(id);
    const form = byId('conversionForm');
    const canCreate = root.dataset.canCreate === '1';
    const canExport = root.dataset.canExport === '1';
    let job = null;
    let busy = false;
    const url = action => root.dataset.api + '?action=' + encodeURIComponent(action);
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    function message(text, success = false) {
        const box = byId('conversionMessage');
        box.textContent = text;
        box.hidden = !text;
        box.classList.toggle('dc-message-success', success);
    }

    function setBusy(value) {
        busy = value;
        byId('conversionFields').disabled = busy || !canCreate;
        byId('conversionSheet').disabled = busy || !canCreate;
        byId('conversionClear').disabled = busy || !canCreate;
        byId('conversionDownload').disabled = busy || !canExport || !job;
        byId('conversionPreviewPanel').setAttribute('aria-busy', String(busy));
        byId('conversionValidate').textContent = busy ? 'Please wait…' : 'Validate & preview';
    }

    async function request(action, body, download = false) {
        const response = await fetch(url(action), {
            method: body ? 'POST' : 'GET', credentials: 'same-origin',
            headers: body ? { 'X-CSRF-Token': token(), ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }) } : {},
            body: body ? (body instanceof FormData ? body : JSON.stringify(body)) : undefined
        });
        if (download && response.ok && !(response.headers.get('content-type') || '').includes('json')) return response;
        const type = response.headers.get('content-type') || '';
        if (!type.includes('json')) throw new Error('The server could not complete the request. Check the PHP upload limits or sign in again.');
        const result = await response.json();
        if (!response.ok || result.error) throw new Error(result.error || 'The conversion request failed.');
        return result;
    }

    function cell(tag, value) {
        const el = document.createElement(tag);
        el.textContent = value == null || value === '' ? '—' : String(value);
        if (tag === 'th') el.scope = 'col';
        return el;
    }

    function render(next) {
        job = next;
        byId('conversionEmpty').hidden = !!job;
        byId('conversionResult').hidden = !job;
        byId('conversionReadyBadge').hidden = !job;
        byId('conversionTableHead').replaceChildren();
        byId('conversionTableBody').replaceChildren();
        byId('conversionNotes').replaceChildren();
        if (!job) { setBusy(busy); return; }
        byId('conversionRows').textContent = job.rows.toLocaleString();
        byId('conversionColumns').textContent = job.columns.length.toLocaleString();
        byId('conversionDestination').textContent = job.destination;
        byId('conversionFilename').textContent = job.filename;
        byId('conversionPreviewCount').textContent = 'First ' + job.preview.length + ' of ' + job.rows.toLocaleString() + ' rows';
        const header = document.createElement('tr');
        job.columns.forEach(column => header.append(cell('th', column)));
        byId('conversionTableHead').append(header);
        job.preview.forEach(record => {
            const row = document.createElement('tr');
            record.forEach(value => row.append(cell('td', value)));
            byId('conversionTableBody').append(row);
        });
        const selector = byId('conversionSheet');
        selector.replaceChildren(new Option('All report worksheets', '__ALL__'));
        job.sheets.forEach(sheet => selector.add(new Option(sheet, sheet)));
        selector.value = job.sheet;
        byId('conversionSheetRow').hidden = !job.sheets.length;
        const notes = job.mode === 'consol' ? [
            'TableName is derived from ReportCode. InsertedAt is supplied by the destination database default.',
            'One row per INSERT. Existing ReportCode or TableName values are skipped when the script runs.',
            'The destination trigger must honor the supplied ReportCode. The generated script does not change triggers.'
        ] : [
            'Blank values become NULL. ReportCode and TableName input columns are excluded as system-managed fields.',
            job.schema_checked ? 'Column names and identity/computed flags were checked against your schema snapshot. SQL Server validates destination data types when the script runs.' : 'No schema snapshot provided. Check destination column names and data types before running the script.',
            'Generic table scripts insert all source rows. Duplicate detection requires destination keys or trigger rules.'
        ];
        if (job.ignored.length) notes.push('Excluded input columns: ' + job.ignored.join(', ') + '.');
        notes.forEach(note => { const p = document.createElement('p'); p.textContent = note; byId('conversionNotes').append(p); });
        byId('conversionExpiry').textContent = 'Preview expires at ' + new Date(job.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + '.';
        setBusy(busy);
    }

    function modeChanged() {
        const generic = form.elements.mode.value === 'generic';
        byId('conversionGeneric').hidden = !generic;
        byId('conversionReportHelp').hidden = generic;
        byId('conversionTable').required = generic;
        byId('conversionSource').accept = generic ? '.csv' : '.csv,.xlsx';
    }
    form.querySelectorAll('[name="mode"]').forEach(input => input.addEventListener('change', modeChanged));
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !canCreate) return;
        const data = new FormData(form); // Collect before disabling the fieldset.
        setBusy(true); message('');
        try { render((await request('upload', data)).job); message('Source validated. Review the preview and download your SQL.', true); }
        catch (error) { message(error.message); }
        finally { setBusy(false); }
    });
    byId('conversionSheet').addEventListener('change', async event => {
        if (busy || !job) return;
        const selected = event.target.value;
        setBusy(true); message('');
        try { render((await request('worksheet', { job_id: job.job_id, sheet: selected })).job); }
        catch (error) { event.target.value = job.sheet; message(error.message); }
        finally { setBusy(false); }
    });
    byId('conversionClear').addEventListener('click', async () => {
        if (busy || !job) return;
        setBusy(true); message('');
        try { await request('clear', { job_id: job.job_id }); render(null); byId('conversionSource').value = ''; byId('conversionSchema').value = ''; }
        catch (error) { message(error.message); }
        finally { setBusy(false); }
    });
    byId('conversionDownload').addEventListener('click', async () => {
        if (busy || !job || !canExport) return;
        const data = new FormData(); data.set('job_id', job.job_id);
        setBusy(true); message('');
        try {
            const response = await request('download', data, true);
            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            const link = document.createElement('a'); link.href = objectUrl;
            link.download = job.mode === 'consol' ? 'consol_data.sql' : 'table_data.sql';
            document.body.append(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
            message('SQL downloaded. Review it before running it in the destination SQL Server database.', true);
        } catch (error) { message(error.message); }
        finally { setBusy(false); }
    });
    modeChanged();
    setBusy(true);
    request('state').then(result => render(result.job)).catch(error => message(error.message)).finally(() => setBusy(false));
})();
