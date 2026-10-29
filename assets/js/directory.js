/**
 * Filename: directory.js
 * Purpose: Coordinate directory interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Call Directory: public browsing and authenticated department contact maintenance. */
(() => 
{
    'use strict';

    const APP = document.getElementById('directoryApp');
    if (!APP) 
    {
        return;
    }
    const IS_CAN_MANAGE = APP.dataset.canManage === '1';
    const GetElement = id => document.getElementById(id);
    const ESCAPE = AT.escapeHtml;
    const PAGE_SIZE = 12;
    const COLLATOR = new Intl.Collator(undefined, 
    {
        numeric: true,
        sensitivity: 'base'
    });
    const INITIAL = new URLSearchParams(location.search);
    let departments = [];
    let filtered = [];
    let page = Math.max(1, Number.parseInt(INITIAL.get('page'), 10) || 1);
    let view = INITIAL.get('view') === 'list' ? 'list' : 'cards';
    let isPrinting = false;
    let isLoaded = false;
    let openDepartmentId = null;
    let editingEmailId = null;
    let editingNumberId = null;
    let isMutating = false;
    const MODAL = GetElement('contactsModal');
    const ICONS = 
    {
        copy: AT.icon('copy'),
        mail: AT.icon('mail'),
        phone: AT.icon('phone')
    };
    async function GetRequest(action, body = null) 
    {
        const LOCAL_URL = new URL(APP.dataset.api, location.origin);
        LOCAL_URL.searchParams.set('action', action);
        const OPTIONS = 
        {
            method: body ? 'POST' : 'GET',
            cache: 'no-store',
            headers: 
            {
                'Accept': 'application/json'
            }
        };
        if (body) 
        {
            OPTIONS.headers['Content-Type'] = 'application/json';
            OPTIONS.headers['X-CSRF-Token'] = document.querySelector('meta[name="csrf-token"]')?.content || '';
            OPTIONS.body = JSON.stringify(body);
        }
        const RESPONSE = await fetch(LOCAL_URL, OPTIONS);
        const DATA = await RESPONSE.json().catch(() => null);
        if (!RESPONSE.ok || DATA === null) 
        {
            throw new Error(DATA?.error || 'The directory could not be loaded. Please try again.');
        }
        return DATA;
    }
    const Normalized = value => String(value || '').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const FindDepartment = id => departments.find(dep => String(dep.department_id) === String(id));
    const Sorted = entries => entries.sort((a, b) => COLLATOR.compare(a[1], b[1]));
    function SetOptions(element, entries, label, selected = element.value) 
    {
        element.replaceChildren(new Option(label, ''));
        Sorted([...entries]).forEach(([id, name]) => element.add(new Option(name, String(id))));
        element.value = [...element.options].some(option => option.value === String(selected)) ? String(selected) : '';
    }
    function UpdateFilterOptions() 
    {
        SetOptions(GetElement('directoryGroup'), new Map(departments.map(dep => [String(dep.group_id), dep.group_name])), 'All groups');
        const GROUP = GetElement('directoryGroup').value;
        const DIVISIONS = departments.filter(dep => !GROUP || String(dep.group_id) === GROUP);
        SetOptions(GetElement('directoryDivision'), new Map(DIVISIONS.map(dep => [String(dep.division_id), dep.division_name])), 'All divisions');
    }
    function UpdateSummary() 
    {
        GetElement('directoryDepartmentTotal').textContent = departments.length.toLocaleString();
        GetElement('directoryNumberTotal').textContent = departments.reduce((total, dep) => total + dep.numbers.length, 0).toLocaleString();
        GetElement('directoryEmailTotal').textContent = departments.reduce((total, dep) => total + dep.emails.length, 0).toLocaleString();
        GetElement('directoryGroupTotal').textContent = new Set(departments.map(dep => String(dep.group_id))).size.toLocaleString();
    }
    function MatchesType(dep, type) 
    {
        if (type === 'email') 
        {
            return dep.emails.length > 0;
        }
        if (type === 'phone') 
        {
            return dep.numbers.length > 0;
        }
        if (type === 'missing') 
        {
            return dep.emails.length + dep.numbers.length === 0;
        }
        return !type || dep.numbers.some(number => Normalized(number.label) === type);
    }
    function ApplyFilters(isResetPage = true) 
    {
        if (!isLoaded) 
        {
            return;
        }
        if (isResetPage) 
        {
            page = 1;
        }
        const SEARCH = Normalized(GetElement('deptSearch').value.trim());
        const TOKENS = SEARCH.split(/\s+/).filter(Boolean);
        const IS_PHONE_SEARCH = SEARCH && /^[\d+().\s-]+$/.test(SEARCH);
        const PHONE_SEARCH = SEARCH.replace(/\D/g, '');
        const GROUP = GetElement('directoryGroup').value;
        const DIVISION = GetElement('directoryDivision').value;
        const TYPE = GetElement('directoryType').value;
        filtered = departments.filter(dep => 
        {
            if (GROUP && String(dep.group_id) !== GROUP || DIVISION && String(dep.division_id) !== DIVISION || !MatchesType(dep, TYPE)) 
            {
                return false;
            }
            const TEXT = Normalized([dep.department_name, dep.description, dep.group_name, dep.division_name, ...dep.emails.map(email => email.email), ...dep.numbers.map(number => number.phone_number + ' ' + number.label)].join(' '));
            return TOKENS.every(token => TEXT.includes(token)) || IS_PHONE_SEARCH && PHONE_SEARCH && dep.numbers.some(number => number.phone_number.replace(/\D/g, '').includes(PHONE_SEARCH));
        });
        filtered.sort((a, b) => (GetElement('directorySort').value === 'desc' ? -1 : 1) * COLLATOR.compare(a.department_name, b.department_name) || Number(a.department_id) - Number(b.department_id));
        page = Math.min(page, Math.max(1, Math.ceil(filtered.length / PAGE_SIZE)));
        Render();
        UpdateUrl();
    }
    function UpdateUrl() 
    {
        const LOCAL_URL = new URL(location.href);
        const VALUES = 
        {
            q: GetElement('deptSearch').value.trim(),
            group: GetElement('directoryGroup').value,
            division: GetElement('directoryDivision').value,
            type: GetElement('directoryType').value,
            sort: GetElement('directorySort').value === 'desc' ? 'desc' : '',
            view: view === 'list' ? 'list' : '',
            page: page > 1 ? String(page) : ''
        };
        Object.entries(VALUES).forEach(([name, value]) => value ? LOCAL_URL.searchParams.set(name, value) : LOCAL_URL.searchParams.delete(name));
        history.replaceState(null, '', LOCAL_URL);
    }
    function ContactMarkup(contact, kind, isEditable = false) 
    {
        const IS_EMAIL = kind === 'email';
        const VALUE = IS_EMAIL ? contact.email : contact.phone_number;
        const LABEL = IS_EMAIL ? 'Email' : contact.label;
        let href = '';
        if (IS_EMAIL && /^[^\s<>@]+@[^\s<>@]+$/.test(VALUE)) 
        {
            href = 'mailto:' + encodeURIComponent(VALUE);
        }
        if (!IS_EMAIL && Normalized(LABEL) !== 'local' && /\d/.test(VALUE)) 
        {
            href = 'tel:' + VALUE.replace(/[^\d+*#,;]/g, '');
        }
        const CONTENT = href ? `<a class="directory-contact-value" href="${ESCAPE(href)}">${ESCAPE(VALUE)}</a>` : `<span class="directory-contact-value">${ESCAPE(VALUE)}</span>`;
        const EDIT_BUTTONS = isEditable ? `<button type="button" class="directory-text-button" data-edit-${kind}="${Number(IS_EMAIL ? contact.email_id : contact.number_id)}">Edit</button><button type="button" class="directory-text-button directory-remove" data-delete-${kind}="${Number(IS_EMAIL ? contact.email_id : contact.number_id)}" aria-label="Remove ${ESCAPE(VALUE)}">Remove</button>` : '';
        return `<div class="directory-contact-row"><span class="directory-contact-icon">${IS_EMAIL ? ICONS.mail : ICONS.phone}</span><div class="directory-contact-detail">${CONTENT}${!IS_EMAIL ? `<small>${ESCAPE(LABEL === 'Local' ? 'Local extension' : LABEL)}</small>` : ''}</div><div class="directory-contact-actions"><button type="button" class="directory-icon-button" data-copy="${ESCAPE(VALUE)}" aria-label="Copy ${ESCAPE(VALUE)}" title="Copy">${ICONS.copy}</button>${EDIT_BUTTONS}</div></div>`;
    }
    function DepartmentMarkup(dep) 
    {
        const INITIALS = dep.department_name.trim().split(/\s+/).slice(0, 2).map(word => [...word][0]).join('');
        const COUNT = dep.emails.length + dep.numbers.length;
        return `<article class="directory-department" data-department-id="${Number(dep.department_id)}">
      <div class="directory-department-head"><span class="directory-department-avatar" aria-hidden="true">${ESCAPE(INITIALS)}</span><div><h3>${ESCAPE(dep.department_name)}</h3><p class="directory-department-path">${ESCAPE(dep.group_name)} <span aria-hidden="true">/</span> ${ESCAPE(dep.division_name)}</p></div></div>
      ${dep.description ? `<p class="directory-description">${ESCAPE(dep.description)}</p>` : ''}
      <div class="directory-department-contacts"><section><h4>Shared inbox</h4>${dep.emails.length ? dep.emails.map(email => ContactMarkup(email, 'email')).join('') : '<p class="directory-missing">No email listed</p>'}</section><section><h4>Phone &amp; extensions</h4>${dep.numbers.length ? dep.numbers.map(number => ContactMarkup(number, 'number')).join('') : '<p class="directory-missing">No number listed</p>'}</section></div>
      <div class="directory-department-footer"><span>${COUNT ? COUNT + (COUNT === 1 ? ' contact' : ' contacts') : 'Contacts not yet listed'}</span>${IS_CAN_MANAGE ? `<button type="button" class="btn btn-sm" data-manage="${Number(dep.department_id)}" aria-label="Manage contacts for ${ESCAPE(dep.department_name)}">Manage contacts</button>` : ''}</div>
    </article>`;
    }
    function Render() 
    {
        const GRID = GetElement('deptGrid');
        GRID.setAttribute('aria-busy', 'false');
        GRID.classList.toggle('is-list', view === 'list');
        document.querySelectorAll('[data-directory-view]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.directoryView === view)));
        const START = (page - 1) * PAGE_SIZE;
        const VISIBLE = isPrinting ? filtered : filtered.slice(START, START + PAGE_SIZE);
        if (!departments.length) 
        {
            GRID.innerHTML = `<div class="directory-state"><span class="directory-state-icon" aria-hidden="true">â–¥</span><h3>The directory is getting started</h3><p>Department contacts will appear here once they are added.</p>${IS_CAN_MANAGE ? `<a class="btn btn-primary btn-sm" href="${ESCAPE(new URL('../organization/index.php', location.href).pathname)}">Set up departments</a>` : ''}</div>`;
        }
        else if (!VISIBLE.length) 
        {
            GRID.innerHTML = '<div class="directory-state"><span class="directory-state-icon" aria-hidden="true">âŒ•</span><h3>No matching departments</h3><p>Try a different search or clear the filters to see all departments.</p><button class="btn btn-sm" type="button" data-clear-filters>Clear filters</button></div>';
        }
        else 
        {
            GRID.innerHTML = VISIBLE.map(DepartmentMarkup).join('');
        }
        GetElement('directoryResults').textContent = filtered.length ? `Showing ${isPrinting ? 1 : START + 1}â€“${isPrinting ? filtered.length : Math.min(START + PAGE_SIZE, filtered.length)} of ${filtered.length} departments` : '0 departments';
        GetElement('directoryPagination').hidden = filtered.length <= PAGE_SIZE || isPrinting;
        GetElement('directoryPageLabel').textContent = `Page ${page} of ${Math.max(1, Math.ceil(filtered.length / PAGE_SIZE))}`;
        GetElement('directoryPrevious').disabled = page <= 1;
        GetElement('directoryNext').disabled = page * PAGE_SIZE >= filtered.length;
        GetElement('directoryPrint').disabled = filtered.length === 0;
    }
    async function Load() 
    {
        try 
        {
            const DATA = await GetRequest('list_departments');
            if (!Array.isArray(DATA)) 
            {
                throw new Error('The directory returned an invalid response. Please try again.');
            }
            departments = DATA;
            isLoaded = true;
            UpdateFilterOptions();
            if (INITIAL.has('group')) 
            {
                GetElement('directoryGroup').value = INITIAL.get('group');
                UpdateFilterOptions();
            }
            if (INITIAL.has('division')) 
            {
                GetElement('directoryDivision').value = INITIAL.get('division');
            }
            UpdateSummary();
            ApplyFilters(false);
        }
        catch (error) 
        {
            GetElement('deptGrid').setAttribute('aria-busy', 'false');
            GetElement('deptGrid').innerHTML = `<div class="directory-state"><h3>Directory unavailable</h3><p>${ESCAPE(error.message)}</p><button class="btn btn-sm" type="button" data-retry>Try again</button></div>`;
            GetElement('directoryResults').textContent = 'Unable to load departments';
        }
    }
    function ResetFilters() 
    {
        GetElement('deptSearch').value = '';
        GetElement('directoryGroup').value = '';
        GetElement('directoryDivision').value = '';
        GetElement('directoryType').value = '';
        GetElement('directorySort').value = 'asc';
        UpdateFilterOptions();
        ApplyFilters();
    }
    function ResetContactForm(kind) 
    {
        const IS_EMAIL = kind === 'email';
        if (IS_EMAIL) 
        {
            editingEmailId = null;
        }
        else 
        {
            editingNumberId = null;
        }
        GetElement(IS_EMAIL ? 'directoryEmailForm' : 'directoryNumberForm').reset();
        GetElement(IS_EMAIL ? 'directoryEmailSave' : 'directoryNumberSave').textContent = IS_EMAIL ? 'Add email' : 'Add number';
        GetElement(IS_EMAIL ? 'directoryEmailLabel' : 'directoryNumberLabel').textContent = IS_EMAIL ? 'Add email address' : 'Add phone number';
        GetElement(IS_EMAIL ? 'directoryEmailCancel' : 'directoryNumberCancel').hidden = true;
    }
    function RenderEditor(dep) 
    {
        GetElement('contactsModalTitle').textContent = dep.department_name;
        GetElement('directoryEditorPath').textContent = dep.group_name + ' / ' + dep.division_name;
        GetElement('cm_email_list').innerHTML = dep.emails.length ? dep.emails.map(email => ContactMarkup(email, 'email', true)).join('') : '<p class="directory-missing">No shared inboxes listed.</p>';
        GetElement('cm_number_list').innerHTML = dep.numbers.length ? dep.numbers.map(number => ContactMarkup(number, 'number', true)).join('') : '<p class="directory-missing">No phone numbers listed.</p>';
    }
    async function ManageContacts(id) 
    {
        if (!IS_CAN_MANAGE) 
        {
            return;
        }
        const DEP = FindDepartment(id);
        if (!DEP) 
        {
            return;
        }
        openDepartmentId = DEP.department_id;
        ResetContactForm('email');
        ResetContactForm('number');
        RenderEditor(DEP);
        GetElement('directoryEditorStatus').textContent = '';
        GetElement('directoryEditorStatus').classList.remove('is-error');
        MODAL.showModal();
    }
    async function Mutate(action, body, kind) 
    {
        if (!IS_CAN_MANAGE || isMutating || !openDepartmentId) 
        {
            return;
        }
        isMutating = true;
        GetElement('directoryEditorFields').disabled = true;
        GetElement('directoryEditorStatus').textContent = 'Saving contact detailsâ€¦';
        GetElement('directoryEditorStatus').classList.remove('is-error');
        try 
        {
            const DATA = await GetRequest(action, 
            {
                ...body,
                department_id: openDepartmentId
            });
            const INDEX = departments.findIndex(dep => String(dep.department_id) === String(openDepartmentId));
            if (INDEX >= 0) 
            {
                departments[INDEX] = DATA.department;
            }
            RenderEditor(DATA.department);
            if (!action.startsWith('delete_') || (kind === 'email' ? editingEmailId === body.email_id : editingNumberId === body.number_id)) 
            {
                ResetContactForm(kind);
            }
            UpdateSummary();
            UpdateFilterOptions();
            ApplyFilters(false);
            GetElement('directoryEditorStatus').textContent = action.startsWith('delete_') ? 'Contact removed.' : 'Contact saved.';
            AT.toast(action.startsWith('delete_') ? 'Contact removed.' : 'Contact saved.');
        }
        catch (error) 
        {
            GetElement('directoryEditorStatus').textContent = error.message;
            GetElement('directoryEditorStatus').classList.add('is-error');
        }
        finally 
        {
            isMutating = false;
            GetElement('directoryEditorFields').disabled = false;
            GetElement(kind === 'email' ? 'cm_new_email' : 'cm_new_number').focus();
        }
    }
    APP.addEventListener('click', event => 
    {
        const COPY = event.target.closest('[data-copy]');
        if (COPY) 
        {
            AT.copyText(COPY.dataset.copy);
        }
        const MANAGE = event.target.closest('[data-manage]');
        if (MANAGE) 
        {
            ManageContacts(MANAGE.dataset.manage);
        }
        if (event.target.closest('[data-clear-filters]')) 
        {
            ResetFilters();
        }
        if (event.target.closest('[data-retry]')) 
        {
            Load();
        }
        const TOGGLE = event.target.closest('[data-directory-view]');
        if (TOGGLE) 
        {
            view = TOGGLE.dataset.directoryView;
            if (isLoaded) 
            {
                Render();
                UpdateUrl();
            }
        }
    });
    GetElement('deptSearch').value = (INITIAL.get('q') || '').slice(0, 200);
    if ([...GetElement('directoryType').options].some(option => option.value === INITIAL.get('type'))) 
    {
        GetElement('directoryType').value = INITIAL.get('type');
    }
    GetElement('directorySort').value = INITIAL.get('sort') === 'desc' ? 'desc' : 'asc';
    GetElement('deptSearch').addEventListener('input', AT.debounce(() => ApplyFilters(), 150));
    GetElement('directoryGroup').addEventListener('change', () => 
    {
        GetElement('directoryDivision').value = '';
        UpdateFilterOptions();
        ApplyFilters();
    });
    ['directoryDivision', 'directoryType', 'directorySort'].forEach(id => GetElement(id).addEventListener('change', () => ApplyFilters()));
    GetElement('directoryReset').addEventListener('click', ResetFilters);
    GetElement('directoryPrevious').addEventListener('click', () => 
    {
        page--;
        ApplyFilters(false);
        GetElement('directoryResults').scrollIntoView(
        {
            block: 'start'
        });
    });
    GetElement('directoryNext').addEventListener('click', () => 
    {
        page++;
        ApplyFilters(false);
        GetElement('directoryResults').scrollIntoView(
        {
            block: 'start'
        });
    });
    GetElement('directoryShare').addEventListener('click', () => AT.copyText(location.href));
    GetElement('directoryPrint').addEventListener('click', () => window.print());
    window.addEventListener('beforeprint', () => 
    {
        if (isLoaded) 
        {
            isPrinting = true;
            Render();
        }
    });
    window.addEventListener('afterprint', () => 
    {
        if (isLoaded) 
        {
            isPrinting = false;
            Render();
        }
    });
    if (IS_CAN_MANAGE) 
    {
        const Close = () => 
        {
            if (!isMutating) 
            {
                MODAL.close();
            }
        };
        MODAL.querySelectorAll('[data-close-editor]').forEach(button => button.addEventListener('click', Close));
        MODAL.addEventListener('cancel', event => 
        {
            if (isMutating) 
            {
                event.preventDefault();
            }
        });
        MODAL.addEventListener('click', async event => 
        {
            if (event.target === MODAL) 
            {
                const RECT = MODAL.getBoundingClientRect();
                if (event.clientX < RECT.left || event.clientX > RECT.right || event.clientY < RECT.top || event.clientY > RECT.bottom) 
                {
                    Close();
                }
            }
            const COPY = event.target.closest('[data-copy]');
            if (COPY) 
            {
                AT.copyText(COPY.dataset.copy);
            }
            if (isMutating) 
            {
                return;
            }
            const DEP = FindDepartment(openDepartmentId);
            if (!DEP) 
            {
                return;
            }
            for (const KIND of ['email', 'number']) 
            {
                const IS_EMAIL = KIND === 'email';
                const EDIT = event.target.closest(`[data-edit-${KIND}]`);
                const REMOVE = event.target.closest(`[data-delete-${KIND}]`);
                const ID = Number(EDIT?.dataset[IS_EMAIL ? 'editEmail' : 'editNumber'] || REMOVE?.dataset[IS_EMAIL ? 'deleteEmail' : 'deleteNumber']);
                if (!ID) 
                {
                    continue;
                }
                const CONTACT = (IS_EMAIL ? DEP.emails : DEP.numbers).find(row => Number(row[IS_EMAIL ? 'email_id' : 'number_id']) === ID);
                if (!CONTACT) 
                {
                    continue;
                }
                if (EDIT) 
                {
                    if (IS_EMAIL) 
                    {
                        editingEmailId = ID;
                        GetElement('cm_new_email').value = CONTACT.email;
                    }
                    else 
                    {
                        editingNumberId = ID;
                        GetElement('cm_new_number').value = CONTACT.phone_number;
                        GetElement('cm_new_label').value = CONTACT.label;
                    }
                    GetElement(IS_EMAIL ? 'directoryEmailSave' : 'directoryNumberSave').textContent = 'Save changes';
                    GetElement(IS_EMAIL ? 'directoryEmailLabel' : 'directoryNumberLabel').textContent = IS_EMAIL ? 'Edit email address' : 'Edit phone number';
                    GetElement(IS_EMAIL ? 'directoryEmailCancel' : 'directoryNumberCancel').hidden = false;
                    GetElement(IS_EMAIL ? 'cm_new_email' : 'cm_new_number').focus();
                }
                else if (REMOVE && (await AT.confirmAction('Remove ' + (IS_EMAIL ? CONTACT.email : CONTACT.phone_number) + ' from this department?'))) 
                {
                    Mutate('delete_' + KIND, 
                    {
                        [IS_EMAIL ? 'email_id' : 'number_id']: ID
                    }, KIND);
                }
            }
        });
        MODAL.addEventListener('close', () => 
        {
            if (!MODAL.open) 
            {
                openDepartmentId = null;
            }
        });
        GetElement('directoryEmailCancel').addEventListener('click', () => ResetContactForm('email'));
        GetElement('directoryNumberCancel').addEventListener('click', () => ResetContactForm('number'));
        GetElement('directoryEmailForm').addEventListener('submit', event => 
        {
            event.preventDefault();
            Mutate(editingEmailId ? 'update_email' : 'add_email', 
            {
                email_id: editingEmailId,
                email: GetElement('cm_new_email').value.trim()
            }, 'email');
        });
        GetElement('directoryNumberForm').addEventListener('submit', event => 
        {
            event.preventDefault();
            Mutate(editingNumberId ? 'update_number' : 'add_number', 
            {
                number_id: editingNumberId,
                phone_number: GetElement('cm_new_number').value.trim(),
                label: GetElement('cm_new_label').value.trim()
            }, 'number');
        });
    }
    Load();
})();