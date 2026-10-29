/**
 * Filename: requirements.js
 * Purpose: Coordinate requirement records, traceability links and impact queries.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Moved module logic into an external script and retained existing API contracts.
 */
const REQ_API = document.getElementById('requirementsApp').dataset.api;
const ASSET_TYPES = 
{
    diagram: 'Diagram',
    table: 'Database table',
    column: 'Database column',
    hosted_system: 'Hosted system',
    group_system: 'Group system'
};
let selectedRequirement = null;
let selectedLinks = [];
const ById = id => document.getElementById(id);
const ESC = AT.escapeHtml;
function FillTypes() 
{
    for (const ID of ['impactType']) 
    {
        ById(ID).innerHTML = Object.entries(ASSET_TYPES).map(([value, label]) => `<option value="${value}">${label}</option>`).join('');
    }
}
async function LoadRequirements() 
{
    const PARAMS = new URLSearchParams(
    {
        action: 'list_requirements',
        q: ById('reqSearch').value,
        status: ById('reqStatus').value
    });
    const ROWS = await AT.api(REQ_API + '?' + PARAMS);
    ById('reqRows').innerHTML = ROWS.length ? ROWS.map(r => `<tr>
    <td><strong>${ESC(r.requirement_code)}</strong></td><td>${ESC(r.title)}</td>
    <td><span class="badge badge-blue">${ESC(r.status)}</span></td><td>${ESC(r.priority)}</td>
    <td>${ESC(r.owner_name || 'â€”')}</td><td>${Number(r.link_count)}</td>
    <td><button class="btn btn-sm" type="button" onclick="OpenRequirement(${Number(r.requirement_id)})">Open</button></td>
  </tr>`).join('') : '<tr><td colspan="7" class="muted">No requirements found.</td></tr>';
}
async function LoadOwners() 
{
    const OWNERS = await AT.api(REQ_API + '?action=list_owners');
    ById('r_owner').innerHTML = '<option value="">Unassigned</option>' + OWNERS.map(u => `<option value="${Number(u.user_id)}">${ESC(u.name)} (${ESC(u.employee_id)})${u.is_active === false || u.is_active === 'f' ? ' â€” disabled' : ''}</option>`).join('');
}
function NewRequirement() 
{
    selectedRequirement = null;
    ById('reqModalTitle').textContent = 'New Requirement';
    ['r_id', 'r_code', 'r_title', 'r_description', 'r_owner'].forEach(id => ById(id).value = '');
    ById('r_status').value = 'Draft';
    ById('r_priority').value = 'Medium';
    AT.openModal('reqModal');
    ById('r_code').focus();
}
function EditRequirement() 
{
    if (!selectedRequirement) 
    {
        return;
    }
    const R = selectedRequirement;
    ById('reqModalTitle').textContent = 'Edit Requirement';
    ById('r_id').value = R.requirement_id;
    ById('r_code').value = R.requirement_code;
    ById('r_title').value = R.title;
    ById('r_description').value = R.description || '';
    ById('r_status').value = R.status;
    ById('r_priority').value = R.priority;
    ById('r_owner').value = R.owner_user_id || '';
    AT.openModal('reqModal');
}
async function SaveRequirement() 
{
    const DATA = await AT.api(REQ_API + '?action=save_requirement', 
    {
        method: 'POST',
        body: 
        {
            requirement_id: ById('r_id').value || null,
            requirement_code: ById('r_code').value,
            title: ById('r_title').value,
            description: ById('r_description').value,
            status: ById('r_status').value,
            priority: ById('r_priority').value,
            owner_user_id: ById('r_owner').value
        }
    });
    AT.closeModal('reqModal');
    AT.toast('Requirement saved.');
    await LoadRequirements();
    await OpenRequirement(DATA.requirement_id);
}
async function OpenRequirement(id) 
{
    const DATA = await AT.api(REQ_API + '?action=get_requirement&id=' + encodeURIComponent(id));
    selectedRequirement = DATA.requirement;
    selectedLinks = DATA.links;
    const R = selectedRequirement;
    ById('reqDetail').innerHTML = `<div class="card-header"><h2 class="layout-38965f9b">${ESC(R.requirement_code)} Â· ${ESC(R.title)}</h2>
    <div class="table-actions"><button class="btn btn-sm" type="button" onclick="EditRequirement()">Edit</button><button class="btn btn-sm btn-danger" type="button" onclick="DeleteRequirement()">Delete</button></div></div>
    <p class="text-sm layout-834fd7b3">${ESC(R.description || 'No description.')}</p>
    <p class="text-sm muted">${ESC(R.status)} Â· ${ESC(R.priority)} priority</p>
    <h2>Linked assets</h2><div id="linkedAssets"></div>
    <div class="grid cols-2 layout-8d842990">
      <div class="form-row"><label for="linkType">Asset type</label><select id="linkType"></select></div>
      <div class="form-row"><label for="linkAsset">Asset</label><select id="linkAsset"></select></div>
    </div><button class="btn btn-primary btn-sm" type="button" onclick="AddLink()">${AT.icon('plus')} Link asset</button>`;
    RenderLinks();
    ById('linkType').innerHTML = Object.entries(ASSET_TYPES).map(([value, label]) => `<option value="${value}">${label}</option>`).join('');
    ById('linkType').addEventListener('change', () => LoadAssets('linkType', 'linkAsset'));
    await LoadAssets('linkType', 'linkAsset');
}
function RenderLinks() 
{
    ById('linkedAssets').innerHTML = selectedLinks.length ? selectedLinks.map(link => `<div class="layout-63215236">
    <span class="text-sm"><span class="badge badge-purple">${ESC(ASSET_TYPES[link.asset_type])}</span> ${ESC(link.asset_label)}</span>
    <button class="btn btn-sm btn-danger" type="button" aria-label="Remove link" onclick="RemoveLink(${Number(link.link_id)})">Remove</button></div>`).join('') : '<p class="muted text-sm">No linked assets yet.</p>';
}
async function DeleteRequirement() 
{
    if (!selectedRequirement || !(await AT.confirmAction('Delete this requirement and all its links?'))) 
    {
        return;
    }
    await AT.api(REQ_API + '?action=delete_requirement', 
    {
        method: 'POST',
        body: 
        {
            requirement_id: selectedRequirement.requirement_id
        }
    });
    selectedRequirement = null;
    ById('reqDetail').innerHTML = '<h2>Requirement details</h2><p class="muted text-sm">Select a requirement to see and manage its linked assets.</p>';
    AT.toast('Requirement deleted.');
    await LoadRequirements();
}
async function LoadAssets(typeSelectId, assetSelectId) 
{
    const TYPE = ById(typeSelectId).value;
    const SELECT = ById(assetSelectId);
    SELECT.innerHTML = '<option value="">Loading...</option>';
    const ASSETS = await AT.api(REQ_API + '?action=list_assets&type=' + encodeURIComponent(TYPE));
    if (ById(typeSelectId).value !== TYPE) 
    {
        return;
    }
    SELECT.innerHTML = '<option value="">Choose an asset</option>' + ASSETS.map(a => `<option value="${Number(a.id)}">${ESC(a.label)}</option>`).join('');
    if (assetSelectId === 'impactAsset') 
    {
        ById('impactResults').innerHTML = '';
    }
}
async function AddLink() 
{
    if (!selectedRequirement || !ById('linkAsset').value) 
    {
        AT.toast('Choose an asset.', 'error');
        return;
    }
    const ID = selectedRequirement.requirement_id;
    await AT.api(REQ_API + '?action=add_link', 
    {
        method: 'POST',
        body: 
        {
            requirement_id: ID,
            asset_type: ById('linkType').value,
            asset_id: ById('linkAsset').value
        }
    });
    AT.toast('Asset linked.');
    await OpenRequirement(ID);
    await LoadRequirements();
}
async function RemoveLink(linkId) 
{
    if (!selectedRequirement || !(await AT.confirmAction('Remove this link?'))) 
    {
        return;
    }
    const ID = selectedRequirement.requirement_id;
    await AT.api(REQ_API + '?action=remove_link', 
    {
        method: 'POST',
        body: 
        {
            requirement_id: ID,
            link_id: linkId
        }
    });
    AT.toast('Link removed.');
    await OpenRequirement(ID);
    await LoadRequirements();
}
async function LoadImpact() 
{
    const TYPE = ById('impactType').value;
    const ID = ById('impactAsset').value;
    if (!ID) 
    {
        AT.toast('Choose an asset.', 'error');
        return;
    }
    const ROWS = await AT.api(REQ_API + '?' + new URLSearchParams(
    {
        action: 'impact',
        type: TYPE,
        id: ID
    }));
    ById('impactResults').innerHTML = ROWS.length ? `<p class="text-sm"><strong>${ROWS.length} linked requirement${ROWS.length === 1 ? '' : 's'}</strong></p>` + ROWS.map(r => `<div class="layout-4154b960">
    <button class="btn btn-sm" type="button" onclick="OpenRequirement(${Number(r.requirement_id)})">${ESC(r.requirement_code)}</button>
    <strong class="text-sm">${ESC(r.title)}</strong><div class="text-sm muted">${ESC(r.status)} Â· ${ESC(r.priority)} Â· ${ESC(r.owner_name || 'Unassigned')}</div></div>`).join('') : '<p class="muted text-sm">No linked requirements found.</p>';
}
ById('reqSearch').addEventListener('input', AT.debounce(LoadRequirements, 250));
ById('reqStatus').addEventListener('change', LoadRequirements);
ById('impactType').addEventListener('change', () => LoadAssets('impactType', 'impactAsset'));
FillTypes();
LoadOwners();
LoadRequirements();
LoadAssets('impactType', 'impactAsset');