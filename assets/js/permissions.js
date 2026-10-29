/**
 * Filename: permissions.js
 * Purpose: Coordinate permissions interface actions, state and API feedback.
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
    const API = ELEMENT('permissionsApp').dataset.api;
    let catalog = null;
    let selected = 'analyst';
    let isDirty = false;
    let editing = null;
    let isLoading = false;
    let isKeyTouched = false;
    const Current = () => catalog?.roles.find(r => r.role_key === selected);
    const Post = (action, body) => AT.api(API + '?action=' + action, 
    {
        method: 'POST',
        body
    });
    function Changed(value) 
    {
        isDirty = value;
        ELEMENT('permissionStatus').textContent = value ? 'Unsaved permission changes' : '';
    }
    async function Discard() 
    {
        return !isDirty || (await WUI.confirm('Discard permission changes?', 'Your permission changes have not been saved.', 'Discard changes'));
    }
    async function Load(role = selected) 
    {
        if (isLoading) 
        {
            return;
        }
        isLoading = true;
        WUI.error('permissionsError', '');
        try 
        {
            const RESULT = await AT.api(API + '?action=matrix');
            catalog = RESULT;
            selected = catalog.roles.some(r => r.role_key === role) ? role : catalog.roles[0]?.role_key;
            ELEMENT('permissionRole').replaceChildren(...catalog.roles.map(r => new Option(r.role_name, r.role_key)));
            ELEMENT('permissionRole').disabled = false;
            ELEMENT('roleAdd').disabled = false;
            Render();
        }
        catch (e) 
        {
            WUI.error('permissionsError', e.message);
        }
        finally 
        {
            isLoading = false;
        }
    }
    function ModuleGroups() 
    {
        const GROUPS = [];
        const SEEN = new Set();
        const HOME = document.querySelector('.sidebar .brand')?.href; // The Administrator sidebar supplies the labels, sections and order for the matrix.
        for (const SECTION of document.querySelectorAll('#sidebarNav .nav-section')) 
        {
            const MODULES = [];
            for (const LINK of SECTION.querySelectorAll('a')) 
            {
                const MODULE = new URL(LINK.href).pathname.match(/\/modules\/([a-z]+)\//)?.[1] || (LINK.href === HOME ? 'dashboard' : null);
                if (MODULE === 'approvals' && catalog.capabilities.reviewlogs && !SEEN.has('reviewlogs'))
                {
                    SEEN.add('reviewlogs');
                    MODULES.push(['reviewlogs', 'Approvals · Daily Logs']);
                    continue;
                }
                if (!MODULE || !catalog.capabilities[MODULE] || SEEN.has(MODULE)) 
                {
                    continue;
                }
                SEEN.add(MODULE);
                MODULES.push([MODULE, LINK.querySelector('.nav-label')?.textContent.trim() || catalog.modules[MODULE]]);
            }
            if (MODULES.length) 
            {
                GROUPS.push(
                {
                    label: SECTION.querySelector('.nav-heading').textContent.trim(),
                    modules: MODULES
                });
            }
        }
        if (catalog.capabilities.account && !SEEN.has('account')) 
        {
            SEEN.add('account');
            GROUPS.push(
            {
                label: 'Account',
                modules: [['account', catalog.modules.account]]
            });
        }
        const HELP = ['wiki', 'updates'].filter(module => catalog.capabilities[module]);
        HELP.forEach(module => SEEN.add(module));
        if (HELP.length) 
        {
            GROUPS.push(
            {
                label: 'Help & updates',
                modules: HELP.map(module => [module, catalog.modules[module]])
            });
        }
        const REMAINING = Object.entries(catalog.modules).filter(([module]) => module !== 'authentication' && catalog.capabilities[module] && !SEEN.has(module) && !catalog.hidden_modules?.includes(module));
        if (REMAINING.length) 
        {
            GROUPS.push(
            {
                label: 'Other modules',
                modules: REMAINING
            });
        }
        return GROUPS;
    }
    function Render() 
    {
        const ROLE = Current();
        if (!ROLE) 
        {
            return;
        }
        const IS_ADMIN = selected === 'admin';
        const SYSTEM = ROLE.is_system;
        ELEMENT('permissionRole').value = selected;
        ELEMENT('permissionSave').disabled = IS_ADMIN;
        ELEMENT('roleName').textContent = ROLE.role_name;
        ELEMENT('roleKind').textContent = SYSTEM ? 'Preset role' : 'Custom role';
        ELEMENT('roleUsers').textContent = Number(ROLE.user_count).toLocaleString() + ' ' + (Number(ROLE.user_count) === 1 ? 'user' : 'users');
        ELEMENT('roleDescription').textContent = ROLE.description || (IS_ADMIN ? 'Full access, including role administration.' : SYSTEM ? 'Edit this profile’s permissions or copy it to create a custom role.' : 'No description yet.');
        ELEMENT('roleKey').textContent = ROLE.role_key;
        ELEMENT('roleEdit').hidden = SYSTEM;
        ELEMENT('roleDelete').hidden = SYSTEM;
        ELEMENT('roleCopy').disabled = IS_ADMIN;
        ELEMENT('permissionHelp').textContent = IS_ADMIN ? 'Administrator permissions are protected and always include full access.' : 'View is required before other permissions apply. Save changes to update everyone assigned to ' + ROLE.role_name + '.';
        ELEMENT('permissionHead').innerHTML = '<tr><th>Module</th>' + catalog.actions.map(a => '<th>' + ESC(a[0].toUpperCase() + a.slice(1)) + '</th>').join('') + '</tr>';
        const ModuleRow = ([module, label]) => '<tr><td><strong>' + ESC(label) + '</strong></td>' + catalog.actions.map(permission => 
        {
            if (!catalog.capabilities[module]?.includes(permission)) 
            {
                return '<td><span class="muted" aria-label="Not applicable">—</span></td>';
            }
            const IS_MANDATORY = permission === 'view' && ['directory', 'bookmarks', 'updates'].includes(module) || ['view', 'edit'].includes(permission) && ['account', 'notifications'].includes(module);
            const LOCKED = IS_ADMIN || module === 'permissions' || IS_MANDATORY;
            const ALLOWED = IS_ADMIN || IS_MANDATORY || module !== 'permissions' && catalog.permissions.some(p => p.role_key === selected && p.module === module && p.permission === permission && p.is_allowed);
            return '<td><input type="checkbox" data-module="' + ESC(module) + '" data-permission-key="' + ESC(permission) + '" aria-label="' + ESC(label + ' ' + permission) + '" ' + (ALLOWED ? 'checked ' : '') + (LOCKED ? 'disabled' : '') + '></td>';
        }).join('') + '</tr>';
        ELEMENT('permissionRows').innerHTML = ModuleGroups().map(group => '<tr class="wf-matrix-group"><th colspan="' + (catalog.actions.length + 1) + '">' + ESC(group.label) + '</th></tr>' + group.modules.map(ModuleRow).join('')).join('');
        Changed(false);
    }
    function Editor(mode) 
    {
        const ROLE = Current();
        editing = mode === 'edit' ? selected : null;
        isKeyTouched = !!editing;
        ELEMENT('roleForm').reset();
        WUI.error('roleFormError', '');
        ELEMENT('roleEditorTitle').textContent = editing ? 'Edit role details' : 'New role';
        ELEMENT('roleEditorHint').textContent = editing ? 'The role key and current permissions stay the same.' : 'Create the role, then adjust its permission matrix.';
        ELEMENT('roleSubmit').textContent = editing ? 'Save details' : 'Create role';
        ELEMENT('roleEditorName').value = editing ? ROLE.role_name : '';
        ELEMENT('roleEditorKey').value = editing ? ROLE.role_key : '';
        ELEMENT('roleEditorKey').readOnly = !!editing;
        ELEMENT('roleEditorDescription').value = editing ? ROLE.description : '';
        ELEMENT('roleSourceRow').hidden = !!editing;
        ELEMENT('roleEditorSource').replaceChildren(new Option('Minimal access (dashboard and personal tools)', ''), ...catalog.roles.filter(r => r.role_key !== 'admin').map(r => new Option('Copy ' + r.role_name, r.role_key)));
        if (mode === 'copy') 
        {
            ELEMENT('roleEditorSource').value = selected;
            ELEMENT('roleEditorName').value = (ROLE.role_name + ' copy').slice(0, 80);
            isKeyTouched = false;
            SuggestKey();
        }
        WUI.open('roleEditor');
        ELEMENT('roleEditorName').focus();
    }
    function SuggestKey() 
    {
        if (!isKeyTouched) 
        {
            ELEMENT('roleEditorKey').value = ELEMENT('roleEditorName').value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^[^a-z]+/, '').replace(/_+$/, '').slice(0, 20);
        }
    }
    ELEMENT('roleEditorName').addEventListener('input', SuggestKey);
    ELEMENT('roleEditorKey').addEventListener('input', () => isKeyTouched = true);
    ELEMENT('roleAdd').onclick = () => Editor('new');
    ELEMENT('roleEdit').onclick = () => Editor('edit');
    ELEMENT('roleCopy').onclick = () => Editor('copy');
    ELEMENT('permissionRows').addEventListener('change', () => Changed(true));
    ELEMENT('permissionRole').onchange = async () => 
    {
        const ROLE = ELEMENT('permissionRole').value;
        if (await Discard()) 
        {
            selected = ROLE;
            Render();
        }
        else 
        {
            ELEMENT('permissionRole').value = selected;
        }
    };
    ELEMENT('roleRefresh').onclick = async () => 
    {
        if (await Discard()) 
        {
            await Load();
        }
    };
    ELEMENT('roleForm').onsubmit = event => 
    {
        event.preventDefault();
        WUI.busy(ELEMENT('roleSubmit'), 'Saving…', async () => 
        {
            WUI.error('roleFormError', '');
            try 
            {
                const KEY = editing || ELEMENT('roleEditorKey').value;
                const BODY = 
                {
                    role_key: KEY,
                    role_name: ELEMENT('roleEditorName').value.trim(),
                    description: ELEMENT('roleEditorDescription').value.trim()
                };
                if (!editing) 
                {
                    BODY.copy_from = ELEMENT('roleEditorSource').value;
                }
                if (!editing && !(await Discard())) 
                {
                    return;
                }
                await Post(editing ? 'update_role' : 'create_role', BODY);
                WUI.close('roleEditor');
                AT.toast(editing ? 'Role details updated.' : 'Role created. Review its permissions, then assign it in Users.');
                if (editing && isDirty) 
                {
                    const UPDATED = await AT.api(API + '?action=matrix');
                    catalog.roles = UPDATED.roles;
                    ELEMENT('roleName').textContent = BODY.role_name;
                    ELEMENT('roleDescription').textContent = BODY.description;
                    ELEMENT('permissionRole').selectedOptions[0].textContent = BODY.role_name;
                }
                else 
                {
                    await Load(KEY);
                }
            }
            catch (e) 
            {
                WUI.error('roleFormError', e.message);
            }
        });
    };
    ELEMENT('permissionSave').onclick = () => WUI.busy(ELEMENT('permissionSave'), 'Saving…', async () => 
    {
        WUI.error('permissionsError', '');
        try 
        {
            const GRANTS = [...ELEMENT('permissionRows').querySelectorAll('input')].map(e => (
            {
                module: e.dataset.module,
                permission: e.dataset.permissionKey,
                is_allowed: e.checked
            }));
            await Post('save_permissions', 
            {
                role_key: selected,
                grants: GRANTS
            });
            await Load();
            AT.toast('Role permissions updated.');
        }
        catch (e) 
        {
            WUI.error('permissionsError', e.message);
        }
    });
    ELEMENT('roleDelete').onclick = async () => 
    {
        const ROLE = Current();
        if (!ROLE || ROLE.is_system) 
        {
            return;
        }
        WUI.error('permissionsError', '');
        if (Number(ROLE.user_count) > 0) 
        {
            WUI.error('permissionsError', 'Reassign the ' + ROLE.user_count + ' user(s) with this role in Users before deleting it.');
            return;
        }
        if (!(await WUI.confirm('Delete ' + ROLE.role_name + '?', 'This removes the role and its permissions. This cannot be undone.', 'Delete role'))) 
        {
            return;
        }
        await WUI.busy(ELEMENT('roleDelete'), 'Deleting…', async () => 
        {
            try 
            {
                await Post('delete_role', 
                {
                    role_key: selected
                });
                Changed(false);
                await Load('analyst');
                AT.toast('Custom role deleted.');
            }
            catch (e) 
            {
                WUI.error('permissionsError', e.message);
            }
        });
    };
    window.addEventListener('beforeunload', event => 
    {
        if (isDirty) 
        {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    Load();
})();
