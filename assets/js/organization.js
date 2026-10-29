/**
 * Filename: organization.js
 * Purpose: Coordinate organization interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* Searchable organization explorer; one editor for all five hierarchy levels. */
(() => 
{
    const 
    {
        $: ELEMENT,
        esc: ESC,
        icon: ICON
    }
    = WUI;
    const API = ELEMENT('organizationApp').dataset.api;
    const LEVELS = [['group', 'Groups', 'group_name', 'divisions', 150], ['division', 'Divisions', 'division_name', 'departments', 150], ['department', 'Departments', 'department_name', 'units', 200], ['unit', 'Units', 'unit_name', 'positions', 150], ['position', 'Positions', 'position_title', null, 150]];
    let roots = [];
    let nodes = new Map();
    let selected = null;
    let editing = null;
    let search = '';
    let isLoaded = false;
    const OPENED = new Set();
    const Key = (type, id) => `${type}-${Number(id)}`;
    document.querySelectorAll('[data-icon]').forEach(el => el.innerHTML = ICON(el.dataset.icon));
    async function Load() 
    {
        ELEMENT('orgLoadError').hidden = true;
        ELEMENT('orgTree').setAttribute('aria-busy', 'true');
        try 
        {
            const DATA = await AT.api(API + '?action=tree');
            nodes = new Map();
            function Index(items, depth, parent) 
            {
                return items.map(item => 
                {
                    const [TYPE,, NAME, CHILDREN] = LEVELS[depth];
                    const NODE = 
                    {
                        ...item,
                        type: TYPE,
                        depth,
                        parent,
                        id: Number(item[TYPE + '_id']),
                        name: item[NAME]
                    };
                    NODE.key = Key(TYPE, NODE.id);
                    nodes.set(NODE.key, NODE);
                    NODE.children = CHILDREN ? Index(item[CHILDREN] || [], depth + 1, NODE) : [];
                    return NODE;
                });
            }
            roots = Index(DATA, 0, null);
            isLoaded = true;
            LEVELS.forEach(([type]) => ELEMENT('orgCount_' + type).textContent = [...nodes.values()].filter(n => n.type === type).length.toLocaleString());
            if (selected && !nodes.has(selected)) 
            {
                selected = null;
            }
            if (!selected && roots.length) 
            {
                selected = roots[0].key;
                OPENED.add(selected);
            }
            Render();
            Details();
        }
        catch (error) 
        {
            ELEMENT('orgLoadError').hidden = false;
            ELEMENT('orgTree').innerHTML = '';
            ELEMENT('orgEmpty').hidden = true;
            ELEMENT('orgResultCount').textContent = 'Structure unavailable';
        }
        finally 
        {
            ELEMENT('orgTree').setAttribute('aria-busy', 'false');
        }
    }
    function Path(node) 
    {
        const RESULT = [];
        for (let n = node; n; n = n.parent) 
        {
            RESULT.unshift(n.name);
        }
        return RESULT;
    }
    const Matches = node => !search || (node.name + ' ' + (node.description || '')).toLocaleLowerCase().includes(search);
    function Highlighted(value) 
    {
        if (!search) 
        {
            return ESC(value);
        }
        const INDEX = value.toLocaleLowerCase().indexOf(search);
        return INDEX < 0 ? ESC(value) : ESC(value.slice(0, INDEX)) + '<mark>' + ESC(value.slice(INDEX, INDEX + search.length)) + '</mark>' + ESC(value.slice(INDEX + search.length));
    }
    function Render() 
    {
        let visible = 0;
        let totalMatches = 0;
        const MATCHING = new Set();
        if (search) 
        {
            for (const NODE of nodes.values()) 
            {
                if (Matches(NODE)) 
                {
                    totalMatches++;
                    for (let n = NODE; n; n = n.parent) 
                    {
                        MATCHING.add(n.key);
                    }
                }
            }
        }
        function Branch(node) 
        {
            if (search && !MATCHING.has(node.key)) 
            {
                return '';
            }
            visible++;
            const IS_HAS_CHILDREN = node.children.length > 0;
            const IS_OPEN = search ? true : OPENED.has(node.key);
            const CHILDREN_ID = 'orgChildren_' + node.key;
            return `<li><div class="org-row ${selected === node.key ? 'is-selected' : ''}">${IS_HAS_CHILDREN ? `<button type="button" class="org-expand" data-expand="${node.key}" aria-expanded="${IS_OPEN}" aria-controls="${CHILDREN_ID}" aria-label="${IS_OPEN ? 'Collapse' : 'Expand'} ${ESC(node.name)}">${AT.icon(IS_OPEN ? 'chevron-down' : 'chevron-right')}</button>` : '<span class="org-leaf" aria-hidden="true">' + AT.icon('minus') + '</span>'}<button type="button" class="org-select" data-select="${node.key}" ${selected === node.key ? 'aria-current="true"' : ''}><span class="org-type-icon ${node.type}" aria-hidden="true">${AT.icon(node.type === 'position' ? 'user-round' : node.type === 'department' ? 'network' : 'users')}</span><span class="org-name">${Highlighted(node.name)}<small>${node.type[0].toUpperCase() + node.type.slice(1)}</small></span>${IS_HAS_CHILDREN ? `<span class="org-row-count" title="Immediate child entries">${node.children.length}</span>` : ''}</button></div>${IS_HAS_CHILDREN ? `<ul id="${CHILDREN_ID}" ${IS_OPEN ? '' : 'hidden'}>${node.children.map(Branch).join('')}</ul>` : ''}</li>`;
        }
        ELEMENT('orgTree').innerHTML = roots.map(Branch).join('');
        ELEMENT('orgResultCount').textContent = search ? `${totalMatches} matching ${totalMatches === 1 ? 'entry' : 'entries'} · parent entries included` : `${nodes.size} entries across ${roots.length} ${roots.length === 1 ? 'group' : 'groups'}`;
        ELEMENT('orgEmpty').hidden = !isLoaded || visible > 0;
        ELEMENT('orgEmptyTitle').textContent = search ? 'No matching entries.' : 'Your organization starts here.';
        ELEMENT('orgEmptyText').textContent = search ? 'Try a different name or clear the search.' : 'Add a group, then build the teams beneath it.';
        ELEMENT('orgEmptyAdd').hidden = !!search;
        ELEMENT('orgExpand').disabled = !nodes.size;
        ELEMENT('orgCollapse').disabled = !nodes.size;
    }
    function Details() 
    {
        const NODE = nodes.get(selected);
        if (!NODE) 
        {
            ELEMENT('orgDetail').innerHTML = `<div class="org-detail-placeholder">${ICON('branch')}<strong>Select an entry</strong><p>Choose a group, team or position to see its details and actions.</p></div>`;
            return;
        }
        const NEXT = LEVELS[NODE.depth + 1];
        ELEMENT('orgDetail').innerHTML = `<span class="ws-tag">${ESC(NODE.type[0].toUpperCase() + NODE.type.slice(1))}</span><h4 class="org-detail-name">${ESC(NODE.name)}</h4><p class="org-detail-description">${ESC(NODE.description || 'No description added yet.')}</p><ol class="org-breadcrumb" aria-label="Organization path">${Path(NODE).map(name => `<li>${ESC(name)}</li>`).join('')}</ol>${NEXT ? `<div class="org-detail-count"><span>${NEXT[1]} in this ${NODE.type}</span><strong>${NODE.children.length}</strong></div>` : ''}<div class="ws-actions"><button class="btn" type="button" data-edit="${NODE.key}">Edit details</button>${NEXT ? `<button class="btn btn-primary" type="button" data-add="${NODE.key}">${AT.icon('plus')} ${NEXT[0][0].toUpperCase() + NEXT[0].slice(1)}</button>` : ''}</div><div class="org-detail-footer"><button class="ws-link-button" type="button" data-delete="${NODE.key}">Delete ${NODE.type}</button><p>Entries can only be deleted when they have no child entries, assigned users or department contacts.</p></div>`;
    }
    function Editor(node = null, parent = null) 
    {
        const DEPTH = node ? node.depth : parent ? parent.depth + 1 : 0;
        const TYPE = LEVELS[DEPTH][0];
        editing = 
        {
            node,
            parent: node?.parent || parent,
            depth: DEPTH,
            type: TYPE
        };
        ELEMENT('orgEditorTitle').textContent = `${node ? 'Edit' : 'New'} ${TYPE}`;
        ELEMENT('orgEditorSubtitle').textContent = node ? 'Update the name and purpose of this entry.' : `Add a ${TYPE} ${parent ? 'within ' + parent.name : 'at the top of the structure'}.`;
        ELEMENT('orgNameLabel').textContent = (TYPE === 'position' ? 'Position title' : TYPE[0].toUpperCase() + TYPE.slice(1) + ' name') + ' *';
        ELEMENT('orgName').maxLength = LEVELS[DEPTH][4];
        ELEMENT('orgName').value = node?.name || '';
        ELEMENT('orgDescription').value = node?.description || '';
        ELEMENT('orgParentNote').hidden = !editing.parent;
        ELEMENT('orgParentNote').textContent = editing.parent ? 'Parent: ' + Path(editing.parent).join(' / ') : '';
        WUI.error('orgFormError', '');
        WUI.open('orgEditor');
    }
    ELEMENT('orgTree').addEventListener('click', event => 
    {
        const EXPAND = event.target.closest('[data-expand]');
        if (EXPAND) 
        {
            const K = EXPAND.dataset.expand;
            if (search) 
            {
                search = '';
                ELEMENT('orgSearch').value = '';
            }
            OPENED.has(K) ? OPENED.delete(K) : OPENED.add(K);
            Render();
            document.querySelector(`[data-expand="${K}"]`)?.focus();
            return;
        }
        const SELECT = event.target.closest('[data-select]');
        if (SELECT) 
        {
            selected = SELECT.dataset.select;
            Render();
            Details();
            document.querySelector(`[data-select="${selected}"]`)?.focus();
        }
    });
    ELEMENT('orgDetail').addEventListener('click', async event => 
    {
        const EDIT = event.target.closest('[data-edit]');
        const ADD = event.target.closest('[data-add]');
        const REMOVE = event.target.closest('[data-delete]');
        if (EDIT) 
        {
            Editor(nodes.get(EDIT.dataset.edit));
        }
        if (ADD) 
        {
            Editor(null, nodes.get(ADD.dataset.add));
        }
        if (REMOVE) 
        {
            const NODE = nodes.get(REMOVE.dataset.delete);
            if (!(await WUI.confirm('Delete ' + NODE.type + '?', `Remove “${NODE.name}” from the organization? Move any child entries, user assignments or contacts before deleting.`, 'Delete entry'))) 
            {
                return;
            }
            try 
            {
                await AT.api(API + '?action=delete_' + NODE.type, 
                {
                    method: 'POST',
                    body: 
                    {
                        [NODE.type + '_id']: NODE.id
                    }
                });
                selected = NODE.parent?.key || null;
                AT.toast('Organization entry deleted.');
                await Load();
            }
            catch (error) 
            {
                /* The API toast explains the failure. */
            }
        }
    });
    ELEMENT('orgForm').addEventListener('submit', event => 
    {
        event.preventDefault();
        WUI.busy(ELEMENT('orgSave'), 'Saving…', async () => 
        {
            WUI.error('orgFormError', '');
            const 
            {
                node: NODE,
                parent: PARENT,
                depth: DEPTH,
                type: TYPE
            }
            = editing;
            const BODY = 
            {
                [TYPE + '_id']: NODE?.id || null,
                [LEVELS[DEPTH][2]]: ELEMENT('orgName').value.trim(),
                description: ELEMENT('orgDescription').value
            };
            if (PARENT) 
            {
                BODY[PARENT.type + '_id'] = PARENT.id;
            }
            try 
            {
                const RESULT = await AT.api(API + '?action=save_' + TYPE, 
                {
                    method: 'POST',
                    body: BODY
                });
                selected = Key(TYPE, RESULT[TYPE + '_id']);
                if (PARENT) 
                {
                    OPENED.add(PARENT.key);
                }
                WUI.close('orgEditor');
                AT.toast(TYPE[0].toUpperCase() + TYPE.slice(1) + ' saved.');
                await Load();
            }
            catch (error) 
            {
                WUI.error('orgFormError', error.message);
            }
        });
    });
    ELEMENT('orgSearch').addEventListener('input', () => 
    {
        search = ELEMENT('orgSearch').value.trim().toLocaleLowerCase();
        Render();
    });
    ELEMENT('orgClear').addEventListener('click', () => 
    {
        ELEMENT('orgSearch').value = '';
        search = '';
        Render();
        ELEMENT('orgSearch').focus();
    });
    ELEMENT('orgExpand').addEventListener('click', () => 
    {
        for (const NODE of nodes.values()) 
        {
            if (NODE.children.length) 
            {
                OPENED.add(NODE.key);
            }
        }
        Render();
    });
    ELEMENT('orgCollapse').addEventListener('click', () => 
    {
        search = '';
        ELEMENT('orgSearch').value = '';
        OPENED.clear();
        Render();
    });
    ELEMENT('orgAddGroup').addEventListener('click', () => Editor());
    ELEMENT('orgEmptyAdd').addEventListener('click', () => Editor());
    ELEMENT('orgRetry').addEventListener('click', Load);
    Load();
})();