/**
 * Filename: users.js
 * Purpose: Coordinate users interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Populate and save the optional personnel birthday field.
 */

/* Account catalog with local facets, pagination and native account dialogs. */
(() => 
{
    const 
    {
        $: ELEMENT,
        esc: ESC,
        icon: ICON
    }
    = WUI;
    const ROOT = ELEMENT('usersApp');
    const API = ROOT.dataset.api;
    const ORG_API = ROOT.dataset.orgApi;
    const MY_ID = Number(ROOT.dataset.currentUser);
    const Truth = value => [true, 1, '1', 't', 'true'].includes(value);
    const LEVELS = ['group', 'division', 'department', 'unit', 'position'];
    const CHILD_KEYS = ['divisions', 'departments', 'units', 'positions'];
    let users = [];
    let page = 1;
    let request = 0;
    let editingId = null;
    let actionId = null;
    let orgTree = [];
    let editorRequest = 0;
    const PAGE_SIZE = 15;
    const Name = user => [user.first_name, user.middle_name, user.last_name].filter(Boolean).join(' ');
    ELEMENT('userSearch').value = (new URLSearchParams(location.search).get('q') || '').slice(0, 200);
    document.querySelectorAll('[data-icon]').forEach(el => el.innerHTML = ICON(el.dataset.icon));
    const StatusMatch = (user, status) => status === 'all' || status === 'active' && Truth(user.is_active) && !Truth(user.is_locked) || status === 'disabled' && !Truth(user.is_active) || status === 'locked' && Truth(user.is_locked) || status === 'password' && Truth(user.must_change_password);
    async function Load() 
    {
        const GENERATION = ++request;
        ELEMENT('usersLoadError').hidden = true;
        ELEMENT('userRows').setAttribute('aria-busy', 'true');
        try 
        {
            const RESULT = await AT.api(API + '?action=list_users');
            if (GENERATION !== request) 
            {
                return;
            }
            users = RESULT;
            const DEPARTMENT = ELEMENT('userDepartment').value;
            ELEMENT('userDepartment').innerHTML = '<option value="">All departments</option><option value="__unassigned">No department</option>' + [...new Set(users.map(u => u.department_name).filter(Boolean))].sort((a, b) => a.localeCompare(b)).map(value => `<option value="${ESC(value)}">${ESC(value)}</option>`).join('');
            if ([...ELEMENT('userDepartment').options].some(o => o.value === DEPARTMENT)) 
            {
                ELEMENT('userDepartment').value = DEPARTMENT;
            }
            for (const STATUS of ['all', 'active', 'locked', 'password']) 
            {
                ELEMENT('userCount_' + STATUS).textContent = users.filter(u => StatusMatch(u, STATUS)).length.toLocaleString();
            }
            Render();
        }
        catch (error) 
        {
            if (GENERATION !== request) 
            {
                return;
            }
            ELEMENT('usersLoadError').hidden = false;
            ELEMENT('userRows').innerHTML = '';
            ELEMENT('userListStatus').textContent = 'Accounts unavailable';
            ELEMENT('userPageSummary').textContent = 'Unable to load';
            ELEMENT('userNext').disabled = true;
            ELEMENT('userPrevious').disabled = true;
        }
        finally 
        {
            if (GENERATION === request) 
            {
                ELEMENT('userRows').setAttribute('aria-busy', 'false');
            }
        }
    }
    function Render() 
    {
        const QUERY = ELEMENT('userSearch').value.trim().toLocaleLowerCase();
        const STATUS = ELEMENT('userStatus').value;
        const DEPARTMENT = ELEMENT('userDepartment').value;
        const SORT = ELEMENT('userSort').value;
        const FILTERED = users.filter(u => StatusMatch(u, STATUS) && (!DEPARTMENT || (DEPARTMENT === '__unassigned' ? !u.department_name : u.department_name === DEPARTMENT)) && (!QUERY || [Name(u), u.employee_id, u.email, u.role_name, u.role_key, u.department_name, u.unit_name, u.position_title].filter(Boolean).join(' ').toLocaleLowerCase().includes(QUERY)));
        FILTERED.sort((a, b) => String(SORT === 'id' ? a.employee_id : SORT === 'department' ? a.department_name || '' : a.last_name + ' ' + a.first_name).localeCompare(String(SORT === 'id' ? b.employee_id : SORT === 'department' ? b.department_name || '' : b.last_name + ' ' + b.first_name), undefined, 
        {
            numeric: true
        }) || Number(a.user_id) - Number(b.user_id));
        const PAGES = Math.max(1, Math.ceil(FILTERED.length / PAGE_SIZE));
        page = Math.min(page, PAGES);
        const START = (page - 1) * PAGE_SIZE;
        ELEMENT('userRows').innerHTML = FILTERED.slice(START, START + PAGE_SIZE).map(user => 
        {
            const ID = Number(user.user_id);
            const FULL_NAME = Name(user);
            const INITIALS = Array.from(user.first_name || '?')[0] + Array.from(user.last_name || '')[0];
            return `<tr><td><div class="ws-identity"><span class="ws-avatar" aria-hidden="true">${ESC(INITIALS.toUpperCase())}</span><span><strong class="users-identity-name">${ESC(FULL_NAME)}</strong><small>${ESC(user.employee_id)}${ID === MY_ID ? ' · You' : ''}</small></span></div></td><td data-label="Organization"><strong>${ESC(user.department_name || 'Not assigned')}</strong><small>${ESC([user.unit_name, user.position_title].filter(Boolean).join(' · ') || 'No unit or position')}</small></td><td data-label="Email">${user.email ? `<a href="mailto:${ESC(encodeURIComponent(user.email))}">${ESC(user.email)}</a>` : '<span class="muted">No email on file</span>'}</td><td data-label="Account status"><small>${ESC(user.role_name || user.role_key || 'Analyst')}</small><div class="users-statuses"><span class="ws-tag ${Truth(user.is_active) ? 'green' : 'gray'}"><span class="ws-status-dot"></span>${Truth(user.is_active) ? 'Enabled' : 'Disabled'}</span>${Truth(user.is_locked) ? '<span class="ws-tag red">Locked</span>' : ''}${Truth(user.must_change_password) ? '<span class="ws-tag amber">Password update</span>' : ''}</div></td><td><div class="users-row-actions"><button class="btn btn-sm" type="button" data-edit-user="${ID}" aria-label="Edit ${ESC(FULL_NAME)}">Edit</button><button class="btn btn-sm" type="button" data-user-actions="${ID}" aria-label="Account actions for ${ESC(FULL_NAME)}">•••</button></div></td></tr>`;
        }).join('') || '<tr><td colspan="5"><div class="ws-state">' + ICON('users') + '<strong>No accounts match.</strong><p>Try a different search, status or department.</p><button class="btn btn-sm" type="button" id="userClearFilters">Clear filters</button></div></td></tr>';
        ELEMENT('userListStatus').textContent = `${users.length} accounts in your workspace`;
        ELEMENT('userPageSummary').textContent = FILTERED.length ? `${START + 1}–${Math.min(START + PAGE_SIZE, FILTERED.length)} of ${FILTERED.length} accounts · Page ${page} of ${PAGES}` : '0 matching accounts';
        ELEMENT('userPrevious').disabled = page <= 1;
        ELEMENT('userNext').disabled = page >= PAGES;
        document.querySelectorAll('[data-status]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.status === STATUS)));
    }
    function Placement(start = 0, preselect = null) 
    {
        let items = orgTree;
        for (let index = 0; index < LEVELS.length; index++) 
        {
            const TYPE = LEVELS[index];
            const SELECT = ELEMENT('us_' + TYPE + '_id');
            if (index >= start) 
            {
                SELECT.innerHTML = '<option value="">' + (index && !ELEMENT('us_' + LEVELS[index - 1] + '_id').value ? 'Choose ' + LEVELS[index - 1] + ' first' : 'Not assigned') + '</option>' + items.map(item => `<option value="${Number(item[TYPE + '_id'])}">${ESC(item[TYPE === 'position' ? 'position_title' : TYPE + '_name'])}</option>`).join('');
                SELECT.disabled = index > 0 && !ELEMENT('us_' + LEVELS[index - 1] + '_id').value;
                if (preselect) 
                {
                    SELECT.value = preselect[TYPE + '_id'] || '';
                }
            }
            const CHOSEN = items.find(item => String(item[TYPE + '_id']) === SELECT.value);
            items = CHOSEN && index < CHILD_KEYS.length ? CHOSEN[CHILD_KEYS[index]] || [] : [];
        }
    }
    async function Editor(id = null) 
    {
        editingId = id;
        const GENERATION = ++editorRequest;
        ELEMENT('userForm').reset();
        ELEMENT('us_password').type = 'password';
        ELEMENT('userFields').disabled = true;
        ELEMENT('saveUserBtn').disabled = true;
        ELEMENT('userEditorRetry').hidden = true;
        WUI.error('userFormError', '');
        ELEMENT('userEditorTitle').textContent = id ? 'Edit user' : 'New user';
        ELEMENT('userEditorSubtitle').textContent = id ? 'Update account details and organization placement.' : 'Create an account and choose where this person belongs.';
        ELEMENT('saveUserBtn').textContent = id ? 'Save changes' : 'Create user';
        ELEMENT('us_password_row').hidden = !!id;
        if (!ELEMENT('userEditor').open) 
        {
            WUI.open('userEditor');
        }
        try 
        {
            const [TREE, USER, ROLES] = await Promise.all([AT.api(ORG_API + '?action=tree'), id ? AT.api(API + '?action=get_user&user_id=' + id) : Promise.resolve(null), AT.api(API + '?action=list_roles')]);
            if (GENERATION !== editorRequest || !ELEMENT('userEditor').open) 
            {
                return;
            }
            orgTree = TREE;
            ELEMENT('us_role_key').replaceChildren(...ROLES.map(role => new Option(role.role_name, role.role_key)));
            for (const FIELD of ['employee_id', 'first_name', 'last_name', 'middle_name', 'email', 'birthday']) 
            {
                ELEMENT('us_' + FIELD).value = USER?.[FIELD] || '';
            }
            ELEMENT('us_role_key').value = USER?.role_key || 'analyst';
            ELEMENT('us_is_active').checked = USER ? Truth(USER.is_active) : true;
            ELEMENT('us_is_active').disabled = Number(id) === MY_ID;
            Placement(0, USER);
            ELEMENT('userFields').disabled = false;
            ELEMENT('saveUserBtn').disabled = false;
            ELEMENT('us_employee_id').focus();
        }
        catch (error) 
        {
            if (GENERATION !== editorRequest) 
            {
                return;
            }
            WUI.error('userFormError', error.message);
            ELEMENT('userEditorRetry').hidden = false;
        }
    }
    function Actions(id) 
    {
        const USER = users.find(u => Number(u.user_id) === id);
        if (!USER) 
        {
            return;
        }
        actionId = id;
        WUI.error('userActionError', '');
        ELEMENT('userActionsTitle').textContent = Name(USER);
        ELEMENT('userActionsSubtitle').textContent = USER.employee_id + ' · Account actions';
        const IS_SELF = id === MY_ID;
        const ENTRIES = [['reset', 'Reset password', 'Set a temporary password and require an update at next sign-in.', false], ...(Truth(USER.is_locked) ? [['unlock', 'Unlock account', 'Clear the lock and failed sign-in attempts.', false]] : []), ['toggle', Truth(USER.is_active) ? 'Disable account' : 'Enable account', Truth(USER.is_active) ? 'Prevent this person from signing in.' : 'Allow this person to sign in again.', IS_SELF], ['delete', 'Delete account', 'Permanently remove this account. Accounts in use cannot be deleted.', IS_SELF]];
        ELEMENT('userActionButtons').innerHTML = ENTRIES.map(([op, title, description, disabled]) => `<button type="button" data-op="${op}" ${disabled ? 'disabled' : ''}><span><strong>${title}</strong><small>${description}</small></span><span aria-hidden="true">›</span></button>`).join('');
        ELEMENT('userSelfHint').hidden = !IS_SELF;
        WUI.open('userActions');
    }
    ELEMENT('userForm').addEventListener('submit', event => 
    {
        event.preventDefault();
        WUI.busy(ELEMENT('saveUserBtn'), 'Saving…', async () => 
        {
            WUI.error('userFormError', '');
            const BODY = 
            {
                user_id: editingId,
                is_active: ELEMENT('us_is_active').checked
            };
            if (!ELEMENT('us_role_key').disabled) 
            {
                BODY.role_key = ELEMENT('us_role_key').value;
            }
            for (const FIELD of ['employee_id', 'first_name', 'last_name', 'middle_name', 'email', 'birthday']) 
            {
                BODY[FIELD] = ELEMENT('us_' + FIELD).value.trim();
            }
            for (const TYPE of LEVELS) 
            {
                BODY[TYPE + '_id'] = ELEMENT('us_' + TYPE + '_id').value || null;
            }
            if (!editingId) 
            {
                BODY.password = ELEMENT('us_password').value;
            }
            if (BODY.password && new TextEncoder().encode(BODY.password).length > 72) 
            {
                WUI.error('userFormError', 'Temporary passwords can contain up to 72 bytes.');
                return;
            }
            try 
            {
                const RESULT = await AT.api(API + '?action=save_user', 
                {
                    method: 'POST',
                    body: BODY
                });
                WUI.close('userEditor');
                AT.toast(RESULT.email_sent === false ? 'Account created. The welcome email could not be sent; check Parameters.' : RESULT.email_sent ? 'Account created and welcome email sent.' : 'User saved.', RESULT.email_sent === false ? 'error' : 'success');
                await Load();
            }
            catch (error) 
            {
                WUI.error('userFormError', error.message);
            }
        });
    });
    ELEMENT('userActionButtons').addEventListener('click', async event => 
    {
        const BUTTON = event.target.closest('[data-op]');
        if (!BUTTON) 
        {
            return;
        }
        const USER = users.find(u => Number(u.user_id) === actionId);
        const OP = BUTTON.dataset.op;
        if (!USER) 
        {
            return;
        }
        if (OP === 'reset') 
        {
            WUI.close('userActions');
            ELEMENT('resetPasswordForm').reset();
            ELEMENT('rp_password').type = 'password';
            ELEMENT('resetPasswordSubtitle').textContent = Name(USER) + ' · ' + USER.employee_id;
            WUI.error('userResetError', '');
            WUI.open('resetPasswordModal');
            return;
        }
        const IS_ACTIVE = !Truth(USER.is_active);
        if (OP === 'delete' || OP === 'toggle' && !IS_ACTIVE) 
        {
            if (!(await WUI.confirm(OP === 'delete' ? 'Delete this account?' : 'Disable this account?', OP === 'delete' ? `Permanently delete the account for ${Name(USER)} (${USER.employee_id})? This cannot be undone.` : `${Name(USER)} will no longer be able to sign in. You can enable the account again later.`, OP === 'delete' ? 'Delete account' : 'Disable account'))) 
            {
                return;
            }
        }
        WUI.busy(BUTTON, 'Updating…', async () => 
        {
            try 
            {
                await AT.api(API + '?action=' + 
                {
                    delete: 'delete_user',
                    toggle: 'set_user_active',
                    unlock: 'unlock_user'
                }
                [OP], 
                {
                    method: 'POST',
                    body: 
                    {
                        user_id: actionId,
                        ...(OP === 'toggle' ? 
                        {
                            is_active: IS_ACTIVE
                        }
                        : 
                        {

                        })
                    }
                });
                WUI.close('userActions');
                AT.toast(OP === 'delete' ? 'Account deleted.' : OP === 'unlock' ? 'Account unlocked.' : IS_ACTIVE ? 'Account enabled.' : 'Account disabled.');
                await Load();
            }
            catch (error) 
            {
                WUI.error('userActionError', error.message);
            }
        });
    });
    ELEMENT('resetPasswordForm').addEventListener('submit', event => 
    {
        event.preventDefault();
        WUI.busy(ELEMENT('userResetSave'), 'Resetting…', async () => 
        {
            WUI.error('userResetError', '');
            const PASSWORD = ELEMENT('rp_password').value;
            if (PASSWORD !== ELEMENT('rp_confirmation').value) 
            {
                WUI.error('userResetError', 'The passwords do not match.');
                return;
            }
            if (new TextEncoder().encode(PASSWORD).length > 72) 
            {
                WUI.error('userResetError', 'Use a password with no more than 72 bytes.');
                return;
            }
            try 
            {
                await AT.api(API + '?action=reset_password', 
                {
                    method: 'POST',
                    body: 
                    {
                        user_id: actionId,
                        password: PASSWORD
                    }
                });
                WUI.close('resetPasswordModal');
                AT.toast('Temporary password set. A password update is required at next sign-in.');
                if (actionId === MY_ID) 
                {
                    location.href = ROOT.dataset.base + '/logout.php';
                    return;
                }
                await Load();
            }
            catch (error) 
            {
                WUI.error('userResetError', error.message);
            }
        });
    });
    ELEMENT('userRows').addEventListener('click', event => 
    {
        const EDIT = event.target.closest('[data-edit-user]');
        const MORE = event.target.closest('[data-user-actions]');
        if (EDIT) 
        {
            Editor(Number(EDIT.dataset.editUser));
        }
        if (MORE) 
        {
            Actions(Number(MORE.dataset.userActions));
        }
        if (event.target.closest('#userClearFilters')) 
        {
            ELEMENT('userSearch').value = '';
            ELEMENT('userStatus').value = 'all';
            ELEMENT('userDepartment').value = '';
            page = 1;
            Render();
        }
    });
    LEVELS.forEach((type, index) => ELEMENT('us_' + type + '_id').addEventListener('change', () => Placement(index + 1)));
    ['userSearch', 'userStatus', 'userDepartment', 'userSort'].forEach(id => ELEMENT(id).addEventListener(id === 'userSearch' ? 'input' : 'change', () => 
    {
        page = 1;
        Render();
    }));
    document.querySelectorAll('[data-status]').forEach(button => button.addEventListener('click', () => 
    {
        ELEMENT('userStatus').value = button.dataset.status;
        page = 1;
        Render();
    }));
    ELEMENT('userPrevious').addEventListener('click', () => 
    {
        page--;
        Render();
    });
    ELEMENT('userNext').addEventListener('click', () => 
    {
        page++;
        Render();
    });
    ELEMENT('userAdd').addEventListener('click', () => Editor());
    ELEMENT('usersRefresh').addEventListener('click', Load);
    ELEMENT('userEditorRetry').addEventListener('click', () => Editor(editingId));
    ELEMENT('userEditor').addEventListener('close', () => 
    {
        editorRequest++;
        ELEMENT('us_password').value = '';
    });
    ELEMENT('resetPasswordModal').addEventListener('close', () => ELEMENT('resetPasswordForm').reset());
    Load();
})();