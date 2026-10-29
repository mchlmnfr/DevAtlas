/**
 * Filename: applications.js
 * Purpose: Coordinate applications interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

(() => 
{
    const 
    {
        $: ELEMENT,
        esc: ESC
    }
    = WUI;
    const ROOT = ELEMENT('applicationsApp');
    const API = ROOT.dataset.api;
    const BASE = ROOT.dataset.base;
    let page = 1;
    let pages = 1;
    let catalog = null;
    let selected = 0;
    let current = null;
    let request = 0;
    const FIELDS = ['application_code', 'application_name', 'description', 'lifecycle', 'criticality', 'owner_user_id', 'technical_owner_id', 'department_id', 'repository_url', 'application_url'];
    async function LoadCatalog() 
    {
        catalog = await AT.api(API + '?action=catalog');
        for (const ID of ['applicationOwner', 'ap_owner_user_id', 'ap_technical_owner_id']) 
        {
            ELEMENT(ID).replaceChildren(new Option(ID === 'applicationOwner' ? 'All owners' : 'Not assigned', ''), ...catalog.people.map(p => new Option(p.name, p.user_id)));
        }
        ELEMENT('ap_department_id').replaceChildren(new Option('Not assigned', ''), ...catalog.departments.map(d => new Option(d.department_name, d.department_id)));
        ELEMENT('applicationAssetType').replaceChildren(...Object.entries(catalog.asset_types).map(([id, label]) => new Option(label, id)));
    }
    async function Load() 
    {
        const GENERATION = ++request;
        WUI.error('applicationError', '');
        ELEMENT('applicationRows').setAttribute('aria-busy', 'true');
        try 
        {
            const RESULT = await AT.api(API + '?action=list_applications&' + new URLSearchParams(
            {
                q: ELEMENT('applicationSearch').value,
                lifecycle: ELEMENT('applicationLifecycle').value,
                owner: ELEMENT('applicationOwner').value,
                page
            }));
            if (GENERATION !== request) 
            {
                return;
            }
            page = RESULT.page;
            pages = RESULT.pages;
            for (const [KEY, VALUE] of Object.entries(RESULT.stats)) 
            {
                ELEMENT('appStat_' + KEY).textContent = Number(VALUE).toLocaleString();
            }
            ELEMENT('applicationRows').innerHTML = RESULT.applications.map(a => '<tr><td><strong>' + ESC(a.application_name) + '</strong><small>' + ESC(a.application_code) + '</small></td><td>' + ESC(a.owner_name || 'Not assigned') + '</td><td><span class="ws-tag ' + (a.lifecycle === 'Active' ? 'green' : 'gray') + '">' + ESC(a.lifecycle) + '</span></td><td>' + ESC(a.criticality) + '</td><td>' + Number(a.asset_count) + '</td><td><button class="btn btn-sm" data-open-application="' + Number(a.application_id) + '">Open</button></td></tr>').join('') || '<tr><td colspan="6" class="ws-state"><strong>No applications found.</strong>Create an application and connect existing records.</td></tr>';
            ELEMENT('applicationCount').textContent = RESULT.total + ' applications · page ' + page + ' of ' + pages;
            ELEMENT('applicationPrevious').disabled = page <= 1;
            ELEMENT('applicationNext').disabled = page >= pages;
        }
        catch (e) 
        {
            WUI.error('applicationError', e.message);
        }
        finally 
        {
            if (GENERATION === request) 
            {
                ELEMENT('applicationRows').setAttribute('aria-busy', 'false');
            }
        }
    }
    function Editor(app = null) 
    {
        ELEMENT('applicationForm').reset();
        ELEMENT('ap_id').value = app?.application_id || '';
        ELEMENT('applicationEditorTitle').textContent = app ? 'Edit application' : 'New application';
        for (const KEY of FIELDS) 
        {
            if (app) 
            {
                ELEMENT('ap_' + KEY).value = app[KEY] ?? '';
            }
        }
        WUI.error('applicationFormError', '');
        WUI.open('applicationEditor');
    }
    async function Candidates() 
    {
        const TYPE = ELEMENT('applicationAssetType').value;
        if (!TYPE) 
        {
            return;
        }
        ELEMENT('applicationAsset').replaceChildren(new Option('Loading…', ''));
        try 
        {
            const ROWS = await AT.api(API + '?action=asset_candidates&type=' + encodeURIComponent(TYPE));
            ELEMENT('applicationAsset').replaceChildren(new Option('Choose a record', ''), ...ROWS.map(r => new Option(r.label, r.id)));
        }
        catch (e) 
        {
            WUI.error('applicationDetailError', e.message);
        }
    }
    async function Detail(id) 
    {
        selected = id;
        WUI.error('applicationDetailError', '');
        if (!ELEMENT('applicationDetail').open) 
        {
            WUI.open('applicationDetail');
        }
        try 
        {
            const APP = await AT.api(API + '?action=get_application&application_id=' + id);
            if (selected !== id) 
            {
                return;
            }
            current = APP;
            ELEMENT('applicationDetailTitle').textContent = APP.application_name;
            ELEMENT('applicationDetailSubtitle').textContent = APP.application_code + ' · ' + APP.lifecycle;
            const VALUES = [['Business owner', APP.owner_name || 'Not assigned'], ['Technical owner', APP.technical_owner_name || 'Not assigned'], ['Department', APP.department_name || 'Not assigned'], ['Criticality', APP.criticality]];
            ELEMENT('applicationDetailBody').innerHTML = '<dl class="wf-details">' + VALUES.map(([k, v]) => '<div><dt>' + ESC(k) + '</dt><dd>' + ESC(v) + '</dd></div>').join('') + '</dl><p class="wf-description">' + ESC(APP.description || 'No description provided.') + '</p>' + [['Application', APP.application_url], ['Repository', APP.repository_url]].filter(([, url]) => url && /^https?:\/\//i.test(url)).map(([label, url]) => '<p><a target="_blank" rel="noopener noreferrer" href="' + ESC(url) + '">' + label + ' ' + AT.icon('arrow-up-right') + '</a></p>').join('');
            ELEMENT('applicationAssets').innerHTML = APP.assets.map(a => '<div class="wf-link"><div><strong>' + ESC(a.label) + '</strong><small>' + ESC(catalog.asset_types[a.type] || a.type) + '</small></div><div class="ws-actions"><a class="btn btn-sm" href="' + BASE + '/modules/' + ESC(a.module) + '/index.php">Open module</a><button class="btn btn-sm" data-permission="delete" data-unlink="' + Number(a.asset_link_id) + '">Unlink</button></div></div>').join('') || '<p class="wf-muted">No linked assets yet.</p>';
            ELEMENT('applicationRelated').innerHTML = '<h4>Recent code reviews</h4>' + APP.reviews.map(r => '<div class="wf-link"><div><strong>' + ESC(r.title) + '</strong><small>' + ESC(r.engine) + ' · ' + Number(r.finding_count) + ' findings</small></div><a class="btn btn-sm" href="' + BASE + '/modules/codereview/history.php?review_id=' + Number(r.review_id) + '">Open review</a></div>').join('') + (!APP.reviews.length ? '<p class="wf-muted">No linked reviews.</p>' : '') + '<h4>Program inventory exports</h4>' + APP.inventory_runs.map(r => '<p class="wf-muted">' + ESC(new Date(r.created_at).toLocaleString()) + ' · ' + Number(r.file_count) + ' files · ' + ESC(r.origin) + ' · ' + ESC(r.file_version || 'No version') + '</p>').join('') + (!APP.inventory_runs.length ? '<p class="wf-muted">No linked inventory exports.</p>' : '');
        }
        catch (e) 
        {
            WUI.error('applicationDetailError', e.message);
        }
    }
    ELEMENT('applicationAdd').onclick = () => Editor();
    ELEMENT('applicationRefresh').onclick = Load;
    ELEMENT('applicationAssetType').onchange = Candidates;
    ELEMENT('applicationEdit').onclick = () => Editor(current);
    ELEMENT('applicationRows').onclick = e => 
    {
        const B = e.target.closest('[data-open-application]');
        if (B) 
        {
            Detail(Number(B.dataset.openApplication));
        }
    };
    ELEMENT('applicationForm').onsubmit = e => 
    {
        e.preventDefault();
        WUI.busy(ELEMENT('applicationSave'), 'Saving…', async () => 
        {
            try 
            {
                const DATA = 
                {
                    application_id: ELEMENT('ap_id').value
                };
                for (const KEY of FIELDS) 
                {
                    DATA[KEY] = ELEMENT('ap_' + KEY).value;
                }
                const RESULT = await AT.api(API + '?action=save_application', 
                {
                    method: 'POST',
                    body: DATA
                });
                WUI.close('applicationEditor');
                await Load();
                if (ELEMENT('applicationDetail').open) 
                {
                    await Detail(RESULT.application_id);
                }
                AT.toast('Application saved.');
            }
            catch (e) 
            {
                WUI.error('applicationFormError', e.message);
            }
        });
    };
    ELEMENT('applicationLink').onclick = () => WUI.busy(ELEMENT('applicationLink'), 'Linking…', async () => 
    {
        try 
        {
            await AT.api(API + '?action=link_asset', 
            {
                method: 'POST',
                body: 
                {
                    application_id: selected,
                    type: ELEMENT('applicationAssetType').value,
                    asset_id: ELEMENT('applicationAsset').value
                }
            });
            await Detail(selected);
            Load();
        }
        catch (e) 
        {
            WUI.error('applicationDetailError', e.message);
        }
    });
    ELEMENT('applicationAssets').onclick = async e => 
    {
        const B = e.target.closest('[data-unlink]');
        if (!B) 
        {
            return;
        }
        try 
        {
            await AT.api(API + '?action=remove_asset', 
            {
                method: 'POST',
                body: 
                {
                    application_id: selected,
                    asset_link_id: B.dataset.unlink
                }
            });
            await Detail(selected);
            Load();
        }
        catch (e) 
        {
            WUI.error('applicationDetailError', e.message);
        }
    };
    ELEMENT('applicationDelete').onclick = async () => 
    {
        if (!(await WUI.confirm('Delete ' + current.application_name + '?', 'This removes its catalog record and asset links. Existing inventory records and review history remain.', 'Delete application'))) 
        {
            return;
        }
        try 
        {
            await AT.api(API + '?action=delete_application', 
            {
                method: 'POST',
                body: 
                {
                    application_id: selected
                }
            });
            WUI.close('applicationDetail');
            Load();
        }
        catch (e) 
        {
            WUI.error('applicationDetailError', e.message);
        }
    };
    ELEMENT('applicationSearch').oninput = AT.debounce(() => 
    {
        page = 1;
        Load();
    }, 300);
    for (const ID of ['applicationLifecycle', 'applicationOwner']) 
    {
        ELEMENT(ID).onchange = () => 
        {
            page = 1;
            Load();
        };
    }
    ELEMENT('applicationPrevious').onclick = () => 
    {
        page--;
        Load();
    };
    ELEMENT('applicationNext').onclick = () => 
    {
        page++;
        Load();
    };
    LoadCatalog().then(() => 
    {
        Load();
        if (AT.can('edit')) 
        {
            Candidates();
        }
        const ID = Number(new URLSearchParams(location.search).get('application_id'));
        if (ID) 
        {
            Detail(ID);
        }
    }).catch(e => WUI.error('applicationError', e.message));
})();