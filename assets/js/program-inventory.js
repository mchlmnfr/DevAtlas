/**
 * Filename: program-inventory.js
 * Purpose: Coordinate program inventory interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* Folder metadata preview and export. File contents are never read or uploaded. */
(() => 
{
    const 
    {
        $: ELEMENT,
        esc: ESC,
        icon: ICON
    }
    = WUI;
    const APP = ELEMENT('inventoryApp');
    const PAGE_SIZE = 20;
    let mode = 'client';
    let picked = [];
    let included = [];
    let page = 1;
    let isExporting = false;
    document.querySelectorAll('[data-icon]').forEach(el => el.innerHTML = ICON(el.dataset.icon));
    const Excludes = () => ELEMENT('pi_exclude_folders').value.split(',').map(v => v.trim()).filter(Boolean);
    const Prefix = () => ELEMENT('pi_system_affected').value.trim().split(/\s+/).filter(Boolean).map(word => Array.from(word)[0].toUpperCase()).join('') || 'SYS';
    const Type = file => 
    {
        const DOT = file.name.lastIndexOf('.');
        return DOT >= 0 ? file.name.slice(DOT + 1).toUpperCase() || 'FILE' : 'FILE';
    };
    function Sync() 
    {
        const PROJECT = ELEMENT('pi_system_affected').value.trim();
        ELEMENT('clientModeSection').hidden = mode !== 'client';
        ELEMENT('serverModeSection').hidden = mode !== 'server';
        ELEMENT('pi_server_path').required = mode === 'server';
        document.querySelectorAll('[data-mode]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.mode === mode)));
        ELEMENT('inventoryReportSource').textContent = mode === 'client' ? 'This computer' : 'Application server';
        ELEMENT('inventoryReportCount').textContent = mode === 'client' ? included.length.toLocaleString() : 'After scan';
        ELEMENT('inventoryReportSize').textContent = mode === 'client' ? WUI.bytes(included.reduce((sum, file) => sum + file.size, 0)) : 'After scan';
        ELEMENT('inventoryReportExcluded').textContent = mode === 'client' ? (picked.length - included.length).toLocaleString() : 'From folder rules';
        ELEMENT('inventoryReportId').textContent = PROJECT ? Prefix() + '-00001' : 'Add a project name';
        ELEMENT('clientGenerateBtn').disabled = isExporting || !PROJECT || (mode === 'client' ? !included.length : !ELEMENT('pi_server_path').value.trim());
        if (!isExporting) 
        {
            ELEMENT('clientGenerateBtn').textContent = mode === 'client' ? 'Download inventory' : 'Scan & export';
        }
        ELEMENT('inventoryExportHint').textContent = isExporting ? 'Preparing your workbook. Keep this page open.' : mode === 'client' ? included.length ? `Exports all ${included.length.toLocaleString()} included files, regardless of preview filters.` : 'Choose a folder and enter the project name to continue.' : 'The server path is scanned when you export.';
        ELEMENT('inventoryPreviewSection').hidden = mode === 'server';
    }
    function Refresh() 
    {
        const EXCLUDED = Excludes();
        included = picked.filter(file => !file.relative_path.replaceAll('\\', '/').split('/').slice(0, -1).some(part => EXCLUDED.includes(part)));
        const SELECTED_TYPE = ELEMENT('inventoryType').value;
        ELEMENT('inventoryType').innerHTML = '<option value="">All types</option>' + [...new Set(included.map(Type))].sort().map(ext => `<option value="${ESC(ext)}">${ESC(ext)}</option>`).join('');
        if ([...ELEMENT('inventoryType').options].some(option => option.value === SELECTED_TYPE)) 
        {
            ELEMENT('inventoryType').value = SELECTED_TYPE;
        }
        ELEMENT('inventoryIncludedTag').textContent = included.length.toLocaleString() + ' files';
        ELEMENT('inventoryPreviewToolbar').hidden = !included.length;
        ELEMENT('inventoryClear').hidden = !picked.length;
        ELEMENT('folderPickedSummary').textContent = picked.length ? picked[0].relative_path.split('/')[0] || 'Selected folder' : 'Choose a project folder';
        ELEMENT('inventoryFolderHint').textContent = picked.length ? `${picked.length.toLocaleString()} files found · ${(picked.length - included.length).toLocaleString()} excluded` : 'Browse folders on this computer';
        ELEMENT('inventoryPreviewStatus').textContent = picked.length ? `${included.length.toLocaleString()} included files · ${WUI.bytes(included.reduce((sum, file) => sum + file.size, 0))} · Modified times in Asia/Manila` : 'Select a folder to see the included file metadata.';
        page = 1;
        Render();
        Sync();
    }
    function Render() 
    {
        const QUERY = ELEMENT('inventorySearch').value.trim().toLocaleLowerCase();
        const EXT = ELEMENT('inventoryType').value;
        const FILTERED = included.filter(file => (!QUERY || file.relative_path.toLocaleLowerCase().includes(QUERY)) && (!EXT || Type(file) === EXT));
        const PAGES = Math.max(1, Math.ceil(FILTERED.length / PAGE_SIZE));
        page = Math.min(page, PAGES);
        const START = (page - 1) * PAGE_SIZE;
        ELEMENT('inventoryPreviewEmpty').hidden = FILTERED.length > 0;
        ELEMENT('inventoryPreviewTable').hidden = !FILTERED.length;
        ELEMENT('inventoryPagination').hidden = !FILTERED.length;
        if (!FILTERED.length) 
        {
            ELEMENT('inventoryPreviewEmpty').innerHTML = ICON('file') + '<strong>' + (picked.length ? included.length ? 'No matching files.' : 'All files are excluded.' : 'No files selected yet.') + '</strong><p>' + (picked.length ? included.length ? 'Try a different name or file type.' : 'Adjust the excluded folder names or choose another folder.' : 'Choose a project folder above to preview the inventory.') + '</p>';
        }
        ELEMENT('inventoryRows').innerHTML = FILTERED.slice(START, START + PAGE_SIZE).map(file => `<tr><td><strong>${ESC(file.name)}</strong><small>${ESC(file.relative_path)}</small></td><td><span class="ws-tag gray">${ESC(Type(file))}</span></td><td>${ESC(WUI.bytes(file.size))}</td><td>${ESC(WUI.date(file.last_modified_ms))}</td></tr>`).join('');
        ELEMENT('inventoryPageSummary').textContent = `${START + 1}–${Math.min(START + PAGE_SIZE, FILTERED.length)} of ${FILTERED.length} matching files`;
        ELEMENT('inventoryPrevious').disabled = page <= 1;
        ELEMENT('inventoryNext').disabled = page >= PAGES;
    }
    ELEMENT('folderPicker').addEventListener('change', event => 
    {
        picked = Array.from(event.target.files).map(file => (
        {
            name: file.name,
            relative_path: file.webkitRelativePath || file.name,
            size: file.size,
            last_modified_ms: file.lastModified
        })).sort((a, b) => a.relative_path.localeCompare(b.relative_path, undefined, 
        {
            numeric: true
        }));
        ELEMENT('inventorySuccess').hidden = true;
        WUI.error('inventoryError', '');
        Refresh();
    });
    ELEMENT('inventoryChoose').addEventListener('click', () => ELEMENT('folderPicker').click());
    ELEMENT('inventoryClear').addEventListener('click', () => 
    {
        picked = [];
        ELEMENT('folderPicker').value = '';
        ELEMENT('inventorySearch').value = '';
        Refresh();
    });
    document.querySelectorAll('[data-mode]').forEach(button => button.addEventListener('click', () => 
    {
        mode = button.dataset.mode;
        WUI.error('inventoryError', '');
        ELEMENT('inventorySuccess').hidden = true;
        Sync();
    }));
    ELEMENT('pi_exclude_folders').addEventListener('input', AT.debounce(Refresh, 180));
    ['pi_system_affected', 'pi_server_path'].forEach(id => ELEMENT(id).addEventListener('input', Sync));
    ELEMENT('inventorySearch').addEventListener('input', () => 
    {
        page = 1;
        Render();
    });
    ELEMENT('inventoryType').addEventListener('change', () => 
    {
        page = 1;
        Render();
    });
    ELEMENT('inventoryPrevious').addEventListener('click', () => 
    {
        page--;
        Render();
    });
    ELEMENT('inventoryNext').addEventListener('click', () => 
    {
        page++;
        Render();
    });
    ELEMENT('inventoryForm').addEventListener('submit', async event => 
    {
        event.preventDefault();
        if (isExporting) 
        {
            return;
        }
        Refresh();
        if (ELEMENT('clientGenerateBtn').disabled) 
        {
            return;
        }
        const TOKEN = document.querySelector('meta[name="csrf-token"]').content;
        const SHARED = 
        {
            application_id: ELEMENT('pi_application_id').value,
            system_affected: ELEMENT('pi_system_affected').value.trim(),
            file_version: ELEMENT('pi_file_version').value.trim(),
            programmer_developer: ELEMENT('pi_programmer_developer').value.trim(),
            exclude_folders: Excludes()
        };
        isExporting = true;
        ELEMENT('inventoryFields').disabled = true;
        WUI.error('inventoryError', '');
        ELEMENT('inventorySuccess').hidden = true;
        Sync();
        ELEMENT('clientGenerateBtn').textContent = mode === 'client' ? 'Building workbook…' : 'Scanning & building…';
        try 
        {
            const RESPONSE = await fetch(mode === 'client' ? APP.dataset.clientUrl : APP.dataset.serverUrl, 
            {
                method: 'POST',
                headers: 
                {
                    'Content-Type': mode === 'client' ? 'application/json' : 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': TOKEN
                },
                body: mode === 'client' ? JSON.stringify(
                {
                    ...SHARED,
                    files: included
                }) : new URLSearchParams(
                {
                    ...SHARED,
                    exclude_folders: SHARED.exclude_folders.join(','),
                    base_path_prefix: ELEMENT('pi_server_path').value.trim(),
                    csrf_token: TOKEN
                })
            });
            if (!RESPONSE.ok || !RESPONSE.headers.get('Content-Type')?.includes('spreadsheetml')) 
            {
                let message = 'The inventory could not be generated. Check the folder and your sign-in session, then try again.';
                if (RESPONSE.headers.get('Content-Type')?.includes('application/json')) 
                {
                    const DATA = await RESPONSE.json();
                    message = DATA.error || message;
                }
                else if (!RESPONSE.ok) 
                {
                    const TEXT = await RESPONSE.text();
                    message = new DOMParser().parseFromString(TEXT, 'text/html').body.textContent.trim().slice(0, 500) || message;
                }
                throw new Error(message);
            }
            const BLOB = await RESPONSE.blob();
            const MATCH = (RESPONSE.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/);
            const LOCAL_URL = URL.createObjectURL(BLOB);
            const LINK = document.createElement('a');
            LINK.href = LOCAL_URL;
            LINK.download = MATCH ? MATCH[1] : 'ProgramInventory.xlsx';
            document.body.appendChild(LINK);
            LINK.click();
            LINK.remove();
            setTimeout(() => URL.revokeObjectURL(LOCAL_URL), 1000);
            ELEMENT('inventorySuccess').textContent = 'Your workbook is ready. Check your browser downloads.';
            ELEMENT('inventorySuccess').hidden = false;
            AT.toast('Inventory workbook downloaded.');
        }
        catch (error) 
        {
            WUI.error('inventoryError', error.message);
        }
        finally 
        {
            isExporting = false;
            ELEMENT('inventoryFields').disabled = false;
            Sync();
        }
    });
    if (AT.can('view', 'applications')) 
    {
        AT.api('../applications/api.php?action=catalog').then(c => 
        {
            ELEMENT('pi_application_id').replaceChildren(new Option('No application linked', ''), ...c.applications.map(a => new Option(a.application_code + ' · ' + a.application_name, a.application_id)));
            ELEMENT('pi_application_id').onchange = () => 
            {
                const SELECTED = c.applications.find(a => String(a.application_id) === ELEMENT('pi_application_id').value);
                if (SELECTED && !ELEMENT('pi_system_affected').value) 
                {
                    ELEMENT('pi_system_affected').value = SELECTED.application_name;
                }
                Sync();
            };
        }).catch(() => 
        {
            ELEMENT('pi_application_id').disabled = true;
        });
    }
    Refresh();
})();