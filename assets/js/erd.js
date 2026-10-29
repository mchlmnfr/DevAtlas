/**
 * Filename: erd.js
 * Purpose: Coordinate erd interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* ERD snapshots, dictionary selection and an SVG canvas with keyboard/drag layout. */
(() => 
{
    'use strict';

    const 
    {
        $: ELEMENT,
        esc: ESC,
        open: OPEN,
        close: CLOSE,
        error: ERROR,
        busy: BUSY,
        confirm: CONFIRM
    }
    = WUI;
    const ROOT = ELEMENT('erdApp');
    const API = ROOT.dataset.api;
    const BASE = ROOT.dataset.base;
    const STATE = 
    {
        models: [],
        catalog: [],
        selection: new Set(),
        diagram: null,
        selected: null,
        zoom: 1,
        dirty: false,
        nameId: 0,
        entityKey: '',
        edgeId: 0
    };
    const Api = (action, body) => AT.api(API + '?action=' + action, body ? 
    {
        method: 'POST',
        body
    }
    : 
    {

    });
    const Meta = value => typeof value === 'string' ? JSON.parse(value || '{}') : value || 
    {

    };
    const Entities = () => STATE.diagram?.nodes.filter(n => n.node_subtype === 'entity') || [];
    const Children = key => STATE.diagram?.nodes.filter(n => n.parent_node_key === key) || [];
    const Find = key => STATE.diagram?.nodes.find(n => n.node_key === key);
    const Short = (s, n) => String(s ?? '').length > n ? String(s).slice(0, n - 1) + '…' : String(s ?? '');
    const GetDate = value => new Date(String(value).replace(' ', 'T') + '+08:00').toLocaleDateString('en-PH', 
    {
        dateStyle: 'medium'
    });
    function Dirty(isValue = true) 
    {
        STATE.dirty = isValue;
        ELEMENT('erdSaveLayout').disabled = !isValue;
        ELEMENT('erdSaveLayout').textContent = isValue ? 'Save layout' : 'Layout saved';
    }
    async function Leave() 
    {
        return !STATE.dirty || (await CONFIRM('Unsaved layout', 'Leave this ERD and discard the unsaved table positions?', 'Discard layout'));
    }
    async function Library() 
    {
        STATE.models = await Api('list_diagrams');
        RenderLibrary();
    }
    function RenderLibrary() 
    {
        const SEARCH = ELEMENT('erdSearch').value.toLowerCase();
        const ROWS = STATE.models.filter(d => (d.diagram_name + ' ' + (d.description || '')).toLowerCase().includes(SEARCH));
        ELEMENT('erdLibraryCount').textContent = ROWS.length + ' saved ERD' + (ROWS.length === 1 ? '' : 's');
        ELEMENT('erdCards').innerHTML = ROWS.map(d => '<article class="erd-card"><span class="ws-tag">ERD snapshot</span><h4>' + ESC(d.diagram_name) + '</h4><p>' + ESC(Short(d.description || 'Entity relationship model', 160)) + '</p><div class="ws-actions"><span class="ws-tag gray">' + Number(d.table_count) + ' tables</span><span class="ws-tag gray">' + Number(d.edge_count) + ' relationships</span></div><small>Updated ' + ESC(GetDate(d.updated_at)) + '</small><div class="ws-actions"><button class="btn btn-sm" data-open-erd="' + Number(d.diagram_id) + '">Open model ' + AT.icon('arrow-right') + '</button></div></article>').join('') || '<div class="ws-state"><strong>' + (SEARCH ? 'No models match.' : 'Your ERD library is ready.') + '</strong><p>' + (SEARCH ? 'Try another search.' : 'Choose Generate ERD to build your first model from documented tables.') + '</p></div>';
    }
    async function Load(id, isFit = true) 
    {
        const DIAGRAM = await Api('get_diagram&diagram_id=' + id);
        DIAGRAM.nodes.forEach(n => 
        {
            n.erd_meta = Meta(n.erd_meta);
            if (n.node_subtype === 'attribute' && !n.erd_meta.type) 
            {
                n.erd_meta.type = (n.detail || '').replace(/\[(?:PK|FK)\]|\bNOT\s+NULL\b/gi, '').trim() || 'text';
                n.erd_meta.pk = /\[PK\]/i.test(n.detail || '');
                n.erd_meta.fk = /\[FK\]/i.test(n.detail || '');
                n.erd_meta.nullable = !n.erd_meta.pk && !/\bNOT\s+NULL\b/i.test(n.detail || '');
            }
            n.pos_x = Math.max(0, Number(n.pos_x) || 0);
            n.pos_y = Math.max(0, Number(n.pos_y) || 0);
        });
        DIAGRAM.edges.forEach(e => e.erd_meta = Meta(e.erd_meta));
        DIAGRAM.generation_meta = Meta(DIAGRAM.generation_meta);
        STATE.diagram = DIAGRAM;
        STATE.selected = null;
        Dirty(false);
        ELEMENT('erdLibrary').hidden = true;
        ELEMENT('erdEditor').hidden = false;
        ELEMENT('erdTitle').textContent = DIAGRAM.diagram_name;
        ELEMENT('erdSubtitle').textContent = DIAGRAM.description || 'An editable ERD snapshot. Changes here do not update the Data Dictionary.';
        ELEMENT('erdStats').innerHTML = '<span class="ws-tag">' + Entities().length + ' tables</span><span class="ws-tag">' + DIAGRAM.nodes.filter(n => n.node_subtype === 'attribute').length + ' columns</span><span class="ws-tag">' + DIAGRAM.edges.length + ' relationships</span>';
        ELEMENT('erdGraphml').href = BASE + 'graphml_export.php?diagram_id=' + DIAGRAM.diagram_id;
        ELEMENT('erdExcel').href = BASE + 'excel_export.php?diagram_id=' + DIAGRAM.diagram_id;
        const NOTES = DIAGRAM.generation_meta;
        const ISSUES = NOTES.issues || [];
        ELEMENT('erdNotesTitle').textContent = ISSUES.length ? ISSUES.length + ' reference warning(s) · generation notes' : 'Generation notes & cardinality assumptions';
        ELEMENT('erdNotesBody').innerHTML = '<p>' + ESC(NOTES.cardinality_note || 'Review cardinalities against your database constraints.') + '</p><p>This is a saved snapshot. Generate a new ERD to include later Data Dictionary changes.</p>' + (NOTES.edited || NOTES.imported ? '<p>Local edits or an Excel import have changed this snapshot. Generation warnings describe the original generation.</p>' : '') + (ISSUES.length ? '<ul>' + ISSUES.map(i => '<li><strong>' + ESC(i.table + (i.column ? '.' + i.column : '')) + '</strong>: ' + ESC(i.message) + '</li>').join('') + '</ul>' : '');
        ELEMENT('erdNotes').open = ISSUES.length > 0;
        const LOCAL_URL = new URL(location.href);
        LOCAL_URL.searchParams.delete('table_id');
        LOCAL_URL.searchParams.set('diagram_id', DIAGRAM.diagram_id);
        history.replaceState(null, '', LOCAL_URL);
        RenderCanvas();
        RenderEdges();
        if (isFit) 
        {
            SetZoom(Math.min(1, Math.max(.8, (ELEMENT('erdViewport').clientWidth - 24) / Bounds().width)));
            ELEMENT('erdViewport').scrollTo(0, 0);
        }
    }
    function Height(n) 
    {
        return Math.max(90, 66 + Children(n.node_key).length * 24);
    }
    function Bounds() 
    {
        return (
        {
            width: Math.max(740, ...Entities().map(n => n.pos_x + 360)),
            height: Math.max(400, ...Entities().map(n => n.pos_y + Height(n) + 40))
        });
    }
    function RenderCanvas() 
    {
        const SVG = ELEMENT('erdSvg');
        const BOX = Bounds();
        const NODES = Entities();
        const IS_DARK = AT.getTheme() === 'dark';
        const PALETTE = IS_DARK ? 
        {
            card: '#20293c',
            head: '#2c3551',
            text: '#e4e8f3',
            muted: '#a8b4c9',
            line: '#53627e',
            accent: '#b2a5ff'
        }
        : 
        {
            card: '#ffffff',
            head: '#f1f0fa',
            text: '#27344e',
            muted: '#697b91',
            line: '#a5b2c7',
            accent: '#6a5fb5'
        };
        const S = ESC;
        let content = '<rect width="' + BOX.width + '" height="' + BOX.height + '" fill="' + (IS_DARK ? '#151d2c' : '#f7f9fc') + '"/>';
        for (const EDGE of STATE.diagram.edges) 
        {
            const RAW_A = Find(EDGE.source_node_key);
            const RAW_B = Find(EDGE.target_node_key);
            const A = RAW_A?.parent_node_key ? Find(RAW_A.parent_node_key) : RAW_A;
            const B = RAW_B?.parent_node_key ? Find(RAW_B.parent_node_key) : RAW_B;
            if (!A || !B) 
            {
                continue;
            }
            const RowY = (node, key) => 
            {
                const I = Children(node.node_key).findIndex(c => c.node_key === key);
                return node.pos_y + (I < 0 ? 32 : 66 + I * 24 + 12);
            };
            const IS_RIGHT = B.pos_x >= A.pos_x;
            const X1 = A.pos_x + (IS_RIGHT ? 320 : 0);
            const X2 = B.pos_x + (IS_RIGHT ? 0 : 320);
            const Y1 = RowY(A, EDGE.erd_meta.source_column_key || RAW_A?.node_key);
            const Y2 = RowY(B, EDGE.erd_meta.target_column_key || RAW_B?.node_key);
            let path;
            let tx;
            let ty;
            if (A === B) 
            {
                const X = A.pos_x + 355;
                path = 'M ' + (A.pos_x + 320) + ' ' + Y1 + ' H ' + X + ' V ' + (Y2 + 36) + ' H ' + (A.pos_x + 320);
                tx = X + 4;
                ty = (Y1 + Y2 + 36) / 2;
            }
            else 
            {
                const MIDDLE = (X1 + X2) / 2;
                const OFFSET = (IS_RIGHT ? 1 : -1) * Math.max(35, Math.abs(X2 - X1) / 2);
                path = 'M ' + X1 + ' ' + Y1 + ' C ' + (X1 + OFFSET) + ' ' + Y1 + ' ' + (X2 - OFFSET) + ' ' + Y2 + ' ' + X2 + ' ' + Y2;
                tx = MIDDLE;
                ty = (Y1 + Y2) / 2 - 7;
            }
            content += '<g><title>' + S((EDGE.label || 'Relationship') + ' [' + (EDGE.cardinality || 'unspecified') + ']') + '</title><path d="' + path + '" fill="none" stroke="' + PALETTE.line + '" stroke-width="1.8"' + (EDGE.edge_style === 'dashed' ? ' stroke-dasharray="5 4"' : '') + '/><circle cx="' + X1 + '" cy="' + Y1 + '" r="3" fill="' + PALETTE.accent + '"/><circle cx="' + X2 + '" cy="' + (A === B ? Y2 + 36 : Y2) + '" r="3" fill="' + PALETTE.accent + '"/><text x="' + tx + '" y="' + ty + '" fill="' + PALETTE.muted + '" font-size="10" text-anchor="middle" stroke="' + (IS_DARK ? '#151d2c' : '#f7f9fc') + '" stroke-width="3" paint-order="stroke">' + S(EDGE.cardinality || '') + '</text></g>';
        }
        for (const N of NODES) 
        {
            const COLS = Children(N.node_key);
            const H = Height(N);
            const IS_SELECTED = STATE.selected === N.node_key;
            content += '<g data-entity="' + S(N.node_key) + '" transform="translate(' + N.pos_x + ' ' + N.pos_y + ')"><title>' + S(N.label + (N.detail ? ' — ' + N.detail : '')) + '</title><rect width="320" height="' + H + '" rx="9" fill="' + PALETTE.card + '" stroke="' + (IS_SELECTED ? PALETTE.accent : PALETTE.line) + '" stroke-width="' + (IS_SELECTED ? 2.5 : 1) + '"/><path d="M 9 0 H 311 Q 320 0 320 9 V 55 H 0 V 9 Q 0 0 9 0" fill="' + PALETTE.head + '"/><g class="entity-header" data-drag="' + S(N.node_key) + '" role="button" tabindex="0" aria-label="' + S('Select and move table ' + N.label) + '"><rect width="320" height="55" fill="transparent"/><text x="14" y="21" fill="' + PALETTE.muted + '" font-size="9">' + S(Short(N.erd_meta.schema_name ? N.erd_meta.source_database + ' / ' + N.erd_meta.schema_name : N.erd_meta.source === 'dictionary' ? 'Dictionary table' : 'ERD table', 49)) + '</text><text x="14" y="41" fill="' + PALETTE.text + '" font-weight="600" font-size="13">' + S(Short(N.label, 37)) + '</text></g>';
            COLS.forEach((c, i) => 
            {
                const M = c.erd_meta;
                const Y = 66 + i * 24;
                content += '<g><title>' + S(c.label + ' : ' + (M.type || c.detail || '') + (M.pk ? ' · primary key' : '') + (M.fk ? ' · foreign key' : '') + (M.nullable === false ? ' · required' : '')) + '</title><text x="12" y="' + (Y + 15) + '" fill="' + PALETTE.accent + '" font-size="8" font-weight="600">' + (M.pk ? 'PK' : M.fk ? 'FK' : '') + '</text><text x="36" y="' + (Y + 15) + '" fill="' + PALETTE.text + '" font-size="11">' + S(Short(c.label, 22)) + (M.nullable === false ? ' *' : '') + '</text><text x="309" y="' + (Y + 15) + '" text-anchor="end" fill="' + PALETTE.muted + '" font-size="10">' + S(Short(M.type || c.detail || '', 17)) + '</text></g>';
            });
            if (!COLS.length) 
            {
                content += '<text x="14" y="77" fill="' + PALETTE.muted + '" font-size="11">No columns documented</text>';
            }
            content += '</g>';
        }
        if (!NODES.length) 
        {
            content += '<text x="35" y="65" fill="' + PALETTE.muted + '" font-size="15">Add a table to start this ERD.</text>';
        }
        SVG.setAttribute('viewBox', '0 0 ' + BOX.width + ' ' + BOX.height);
        SVG.setAttribute('font-family', 'Segoe UI, Arial, sans-serif');
        SVG.innerHTML = content;
        SetZoom(STATE.zoom);
        Inspector();
    }
    function SetZoom(value) 
    {
        STATE.zoom = Math.max(.1, Math.min(2, value));
        const B = Bounds();
        ELEMENT('erdSvg').setAttribute('width', B.width * STATE.zoom);
        ELEMENT('erdSvg').setAttribute('height', B.height * STATE.zoom);
        ELEMENT('erdZoomLabel').textContent = Math.round(STATE.zoom * 100) + '%';
    }
    function FitCanvas() 
    {
        const B = Bounds();
        SetZoom(Math.min(1, (ELEMENT('erdViewport').clientWidth - 24) / B.width, (ELEMENT('erdViewport').clientHeight - 24) / B.height));
        ELEMENT('erdViewport').scrollTo(0, 0);
    }
    function Inspector() 
    {
        const N = Find(STATE.selected);
        ELEMENT('erdInspector').hidden = !N;
        if (N) 
        {
            ELEMENT('erdSelectedName').textContent = N.label;
            ELEMENT('erdSelectedDescription').textContent = N.detail || 'Select Edit table to update columns in this snapshot.';
        }
    }
    function RenderEdges() 
    {
        ELEMENT('erdEdgeCount').textContent = STATE.diagram.edges.length + ' relationships';
        const Endpoint = (edge, side) => 
        {
            const N = Find(edge[side + '_node_key']);
            const C = Find(edge.erd_meta[side + '_column_key']);
            return '<strong>' + ESC(N?.label || edge[side + '_node_key']) + '</strong><small>' + ESC(C?.label || 'Table-level relationship') + '</small>';
        };
        ELEMENT('erdEdges').innerHTML = STATE.diagram.edges.map(e => '<tr><td>' + Endpoint(e, 'source') + '</td><td>' + Endpoint(e, 'target') + '</td><td>' + ESC(e.cardinality || 'Not specified') + '</td><td><span class="ws-tag gray">' + (e.erd_meta.source === 'dictionary' ? 'Dictionary' : 'Manual') + '</span></td><td><div class="ws-actions"><button class="btn btn-sm" data-edit-edge="' + Number(e.edge_id) + '">Edit</button><button class="btn btn-sm" data-delete-edge="' + Number(e.edge_id) + '">Remove</button></div></td></tr>').join('') || '<tr><td colspan="5" class="ws-state">No relationships in this model. Generate from documented foreign keys or add a relationship.</td></tr>';
    }
    async function SavePositions() 
    {
        if (!STATE.dirty) 
        {
            return;
        }
        await Api('save_layout', 
        {
            diagram_id: STATE.diagram.diagram_id,
            positions: Entities().map(n => (
            {
                node_key: n.node_key,
                pos_x: n.pos_x,
                pos_y: n.pos_y
            }))
        });
        Dirty(false);
    }
    async function Mutate(action, data) 
    {
        await SavePositions();
        await Api(action, 
        {
            diagram_id: STATE.diagram.diagram_id,
            ...data
        });
        await Load(STATE.diagram.diagram_id, false);
    }
    function VisibleTables() 
    {
        const DB = ELEMENT('erdDatabase').value;
        const Q = ELEMENT('erdTableSearch').value.toLowerCase();
        return STATE.catalog.filter(t => (!DB || t.source_database === DB) && [t.table_name, t.schema_name, t.display_name, t.source_database].join(' ').toLowerCase().includes(Q));
    }
    function Picker() 
    {
        const ROWS = VisibleTables();
        ELEMENT('erdSelectionCount').textContent = STATE.selection.size + ' of 50 tables selected';
        ELEMENT('erdTablePicker').innerHTML = ROWS.map(t => '<label class="erd-table-option"><input type="checkbox" data-table="' + Number(t.table_id) + '" ' + (STATE.selection.has(Number(t.table_id)) ? 'checked' : '') + '><span><strong>' + ESC(t.schema_name + '.' + t.table_name) + '</strong><small>' + ESC(t.source_database) + ' · ' + Number(t.column_count) + ' columns · ' + Number(t.foreign_keys) + ' foreign keys' + (!t.is_active ? ' · Inactive' : '') + '</small></span><span class="ws-tag gray">' + ESC(t.object_type) + '</span></label>').join('') || '<div class="ws-state"><strong>No documented tables found.</strong><p>Add or import tables in Data Dictionary first.</p></div>';
    }
    async function Generator(preselect) 
    {
        ERROR('erdGenerateError', '');
        OPEN('erdGenerateDialog');
        try 
        {
            STATE.catalog = await Api('dictionary_tables');
            const NAMES = [...new Set(STATE.catalog.map(t => t.source_database))];
            ELEMENT('erdDatabase').replaceChildren(new Option('All database groups', ''), ...NAMES.map(n => new Option(n, n)));
            if (preselect) 
            {
                const T = STATE.catalog.find(t => Number(t.table_id) === Number(preselect));
                if (T) 
                {
                    STATE.selection = new Set([Number(T.table_id)]);
                    ELEMENT('erdDatabase').value = T.source_database;
                    ELEMENT('erdName').value = T.table_name + ' ERD';
                }
            }
            Picker();
        }
        catch (e) 
        {
            ERROR('erdGenerateError', e.message);
            ELEMENT('erdTablePicker').textContent = 'Dictionary could not be loaded.';
        }
    }
    function NameDialog(id) 
    {
        STATE.nameId = id;
        const D = id ? STATE.diagram : null;
        ELEMENT('erdNameDialogTitle').textContent = id ? 'Edit ERD details' : 'Create blank ERD';
        ELEMENT('erdEditName').value = D?.diagram_name || '';
        ELEMENT('erdEditDescription').value = D?.description || '';
        ERROR('erdNameError', '');
        OPEN('erdNameDialog');
    }
    function ColumnRow(c) 
    {
        const M = c?.erd_meta || 
        {

        };
        const DIV = document.createElement('div');
        DIV.className = 'erd-column-editor';
        DIV.dataset.key = c?.node_key || '';
        DIV.innerHTML = '<div class="form-grid"><div class="form-row"><label>Column name<input class="ec-name" required maxlength="128" value="' + ESC(c?.label || '') + '"></label></div><div class="form-row"><label>Data type<input class="ec-type" required maxlength="500" value="' + ESC(M.type || c?.detail || 'text') + '"></label></div></div><div class="erd-column-flags"><label><input class="ec-pk" type="checkbox" ' + (M.pk ? 'checked' : '') + '>PK</label><label><input class="ec-fk" type="checkbox" ' + (M.fk ? 'checked' : '') + '>FK</label><label><input class="ec-nullable" type="checkbox" ' + (M.nullable !== false ? 'checked' : '') + '>Nullable</label><button class="ws-link-button ec-remove" type="button">Remove column</button></div>';
        DIV.querySelector('.ec-remove').onclick = () => DIV.remove();
        DIV.querySelector('.ec-pk').onchange = e => 
        {
            if (e.target.checked) 
            {
                DIV.querySelector('.ec-nullable').checked = false;
            }
        };
        ELEMENT('erdEntityColumns').append(DIV);
    }
    function EntityDialog(key = '') 
    {
        STATE.entityKey = key;
        const N = Find(key);
        ELEMENT('erdEntityTitle').textContent = N ? 'Edit table' : 'Add table';
        ELEMENT('erdEntityName').value = N?.label || '';
        ELEMENT('erdEntityDescription').value = N?.detail || '';
        ELEMENT('erdEntityColumns').replaceChildren();
        if (N) 
        {
            Children(key).forEach(ColumnRow);
        }
        else 
        {
            ColumnRow();
        }
        ERROR('erdEntityError', '');
        OPEN('erdEntityDialog');
    }
    function EdgeColumns(side, value = '') 
    {
        const SELECT = ELEMENT('erdEdge' + side + 'Column');
        SELECT.replaceChildren(new Option('Table-level (no column)', ''), ...Children(ELEMENT('erdEdge' + side).value).map(c => new Option(c.label, c.node_key)));
        SELECT.value = value;
    }
    function EdgeDialog(id = 0) 
    {
        if (!Entities().length) 
        {
            AT.toast('Add tables before creating a relationship.', 'error');
            return;
        }
        STATE.edgeId = id;
        const E = STATE.diagram.edges.find(e => Number(e.edge_id) === id);
        for (const SIDE of ['Source', 'Target']) 
        {
            ELEMENT('erdEdge' + SIDE).replaceChildren(...Entities().map(n => new Option(n.label, n.node_key)));
            if (E) 
            {
                ELEMENT('erdEdge' + SIDE).value = E[SIDE.toLowerCase() + '_node_key'];
            }
            EdgeColumns(SIDE, E?.erd_meta[SIDE.toLowerCase() + '_column_key'] || '');
        }
        ELEMENT('erdEdgeCardinality').value = E?.cardinality || '0..N:1';
        ELEMENT('erdEdgeLabel').value = E?.label || '';
        ERROR('erdEdgeError', '');
        OPEN('erdEdgeDialog');
    }
    function Handle(task) 
    {
        return async event => 
        {
            try 
            {
                await task(event);
            }
            catch (e) 
            {
                ERROR('erdError', e.message);
            }
        };
    }
    ELEMENT('erdGenerateOpen').onclick = () => Generator();
    ELEMENT('erdBlank').onclick = () => NameDialog(0);
    ELEMENT('erdRename').onclick = () => NameDialog(STATE.diagram.diagram_id);
    ELEMENT('erdSearch').oninput = RenderLibrary;
    ELEMENT('erdDatabase').onchange = Picker;
    ELEMENT('erdTableSearch').oninput = Picker;
    ELEMENT('erdTablePicker').onchange = e => 
    {
        const ID = Number(e.target.dataset.table);
        if (!ID) 
        {
            return;
        }
        if (e.target.checked && STATE.selection.size >= 50) 
        {
            e.target.checked = false;
            ERROR('erdGenerateError', 'Select at most 50 tables.');
            return;
        }
        e.target.checked ? STATE.selection.add(ID) : STATE.selection.delete(ID);
        ELEMENT('erdSelectionCount').textContent = STATE.selection.size + ' of 50 tables selected';
    };
    ELEMENT('erdSelectVisible').onclick = () => 
    {
        const IDS = VisibleTables().map(t => Number(t.table_id));
        if (new Set([...STATE.selection, ...IDS]).size > 50) 
        {
            ERROR('erdGenerateError', 'This would select more than 50 tables. Narrow the filters.');
            return;
        }
        IDS.forEach(id => STATE.selection.add(id));
        Picker();
    };
    ELEMENT('erdClear').onclick = () => 
    {
        STATE.selection.clear();
        Picker();
    };
    ELEMENT('erdCards').onclick = Handle(async e => 
    {
        const B = e.target.closest('[data-open-erd]');
        if (B && (await Leave())) 
        {
            await Load(Number(B.dataset.openErd));
        }
    });
    ELEMENT('erdGenerateForm').onsubmit = async e => 
    {
        e.preventDefault();
        ERROR('erdGenerateError', '');
        await BUSY(ELEMENT('erdGenerateSubmit'), 'Generating…', async () => 
        {
            try 
            {
                if (!(await Leave())) 
                {
                    return;
                }
                const R = await Api('generate_dictionary', 
                {
                    diagram_name: ELEMENT('erdName').value,
                    table_ids: [...STATE.selection],
                    include_related: ELEMENT('erdRelated').checked
                });
                CLOSE('erdGenerateDialog');
                await Load(R.diagram_id);
            }
            catch (err) 
            {
                ERROR('erdGenerateError', err.message);
            }
        });
    };
    ELEMENT('erdNameForm').onsubmit = async e => 
    {
        e.preventDefault();
        await BUSY(ELEMENT('erdNameSubmit'), 'Saving…', async () => 
        {
            try 
            {
                if (STATE.nameId) 
                {
                    await SavePositions();
                }
                else if (!(await Leave())) 
                {
                    return;
                }
                const R = await Api('save_diagram', 
                {
                    diagram_id: STATE.nameId,
                    diagram_name: ELEMENT('erdEditName').value,
                    description: ELEMENT('erdEditDescription').value
                });
                CLOSE('erdNameDialog');
                await Load(R.diagram_id);
            }
            catch (err) 
            {
                ERROR('erdNameError', err.message);
            }
        });
    };
    ELEMENT('erdBack').onclick = Handle(async () => 
    {
        if (!(await Leave())) 
        {
            return;
        }
        STATE.diagram = null;
        Dirty(false);
        ELEMENT('erdEditor').hidden = true;
        ELEMENT('erdLibrary').hidden = false;
        history.replaceState(null, '', location.pathname);
        await Library();
    });
    ELEMENT('erdDelete').onclick = Handle(async () => 
    {
        if (await CONFIRM('Delete this ERD?', 'This removes the saved model and its relationships. Data Dictionary tables are retained.')) 
        {
            await Api('delete_diagram', 
            {
                diagram_id: STATE.diagram.diagram_id
            });
            Dirty(false);
            ELEMENT('erdBack').click();
        }
    });
    ELEMENT('erdAddTable').onclick = () => EntityDialog();
    ELEMENT('erdEditTable').onclick = () => EntityDialog(STATE.selected);
    ELEMENT('erdColumnAdd').onclick = () => ColumnRow();
    ELEMENT('erdRemoveTable').onclick = Handle(async () => 
    {
        if (await CONFIRM('Remove table?', 'Its columns and relationships will be removed from this ERD snapshot.')) 
        {
            await Mutate('delete_entity', 
            {
                node_key: STATE.selected
            });
        }
    });
    ELEMENT('erdEntityForm').onsubmit = async e => 
    {
        e.preventDefault();
        await BUSY(ELEMENT('erdEntitySubmit'), 'Saving…', async () => 
        {
            try 
            {
                const COLUMNS = [...ELEMENT('erdEntityColumns').children].map(el => (
                {
                    node_key: el.dataset.key,
                    name: el.querySelector('.ec-name').value,
                    type: el.querySelector('.ec-type').value,
                    pk: el.querySelector('.ec-pk').checked,
                    fk: el.querySelector('.ec-fk').checked,
                    nullable: el.querySelector('.ec-nullable').checked
                }));
                await Mutate('save_entity', 
                {
                    node_key: STATE.entityKey,
                    label: ELEMENT('erdEntityName').value,
                    detail: ELEMENT('erdEntityDescription').value,
                    columns: COLUMNS
                });
                CLOSE('erdEntityDialog');
            }
            catch (err) 
            {
                ERROR('erdEntityError', err.message);
            }
        });
    };
    ELEMENT('erdAddEdge').onclick = () => EdgeDialog();
    for (const SIDE of ['Source', 'Target']) 
    {
        ELEMENT('erdEdge' + SIDE).onchange = () => EdgeColumns(SIDE);
    }
    ELEMENT('erdEdgeForm').onsubmit = async e => 
    {
        e.preventDefault();
        await BUSY(ELEMENT('erdEdgeSubmit'), 'Saving…', async () => 
        {
            try 
            {
                await Mutate('save_relationship', 
                {
                    edge_id: STATE.edgeId,
                    source_node_key: ELEMENT('erdEdgeSource').value,
                    target_node_key: ELEMENT('erdEdgeTarget').value,
                    source_column_key: ELEMENT('erdEdgeSourceColumn').value,
                    target_column_key: ELEMENT('erdEdgeTargetColumn').value,
                    cardinality: ELEMENT('erdEdgeCardinality').value,
                    label: ELEMENT('erdEdgeLabel').value
                });
                CLOSE('erdEdgeDialog');
            }
            catch (err) 
            {
                ERROR('erdEdgeError', err.message);
            }
        });
    };
    ELEMENT('erdEdges').onclick = Handle(async e => 
    {
        const EDIT = e.target.closest('[data-edit-edge]');
        const DEL = e.target.closest('[data-delete-edge]');
        if (EDIT) 
        {
            EdgeDialog(Number(EDIT.dataset.editEdge));
        }
        if (DEL && (await CONFIRM('Remove relationship?', 'The connection will be removed from this ERD.'))) 
        {
            await Mutate('delete_relationship', 
            {
                edge_id: Number(DEL.dataset.deleteEdge)
            });
        }
    });
    ELEMENT('erdSaveLayout').onclick = Handle(() => BUSY(ELEMENT('erdSaveLayout'), 'Saving…', async () => 
    {
        await SavePositions();
    }).finally(() => 
    {
        if (!STATE.dirty) 
        {
            Dirty(false);
        }
    }));
    ELEMENT('erdArrange').onclick = () => 
    {
        let y = 40;
        let max = 0;
        const PER = Math.ceil(Math.sqrt(Entities().length));
        Entities().forEach((n, i) => 
        {
            if (i && i % PER === 0) 
            {
                y += max + 100;
                max = 0;
            }
            n.pos_x = 40 + i % PER * 440;
            n.pos_y = y;
            max = Math.max(max, Height(n));
        });
        Dirty();
        RenderCanvas();
        FitCanvas();
    };
    ELEMENT('erdZoomOut').onclick = () => SetZoom(STATE.zoom / 1.2);
    ELEMENT('erdZoomIn').onclick = () => SetZoom(STATE.zoom * 1.2);
    ELEMENT('erdFit').onclick = FitCanvas;
    let drag = null;
    ELEMENT('erdSvg').addEventListener('pointerdown', e => 
    {
        const HEADER = e.target.closest('[data-drag]');
        if (!HEADER) 
        {
            return;
        }
        const N = Find(HEADER.dataset.drag);
        STATE.selected = N.node_key;
        drag = 
        {
            id: e.pointerId,
            key: N.node_key,
            x: e.clientX,
            y: e.clientY,
            ox: N.pos_x,
            oy: N.pos_y
        };
        ELEMENT('erdSvg').setPointerCapture(e.pointerId);
        RenderCanvas();
    });
    ELEMENT('erdSvg').addEventListener('pointermove', e => 
    {
        if (!drag || drag.id !== e.pointerId) 
        {
            return;
        }
        const N = Find(drag.key);
        N.pos_x = Math.max(0, Math.min(200000, Math.round(drag.ox + (e.clientX - drag.x) / STATE.zoom)));
        N.pos_y = Math.max(0, Math.min(200000, Math.round(drag.oy + (e.clientY - drag.y) / STATE.zoom)));
        if (N.pos_x !== drag.ox || N.pos_y !== drag.oy) 
        {
            Dirty();
        }
        RenderCanvas();
    });
    const EndDrag = () => 
    {
        drag = null;
    };
    ELEMENT('erdSvg').addEventListener('pointerup', EndDrag);
    ELEMENT('erdSvg').addEventListener('pointercancel', EndDrag);
    ELEMENT('erdSvg').addEventListener('click', e => 
    {
        const G = e.target.closest('[data-entity]');
        if (G) 
        {
            STATE.selected = G.dataset.entity;
            RenderCanvas();
        }
    });
    ELEMENT('erdSvg').addEventListener('keydown', e => 
    {
        const KEY = e.target.closest('[data-drag]')?.dataset.drag || STATE.selected;
        if (!KEY) 
        {
            return;
        }
        const N = Find(KEY);
        STATE.selected = KEY;
        if (e.key === 'Enter' || e.key === ' ') 
        {
            e.preventDefault();
            EntityDialog(KEY);
            return;
        }
        if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) 
        {
            return;
        }
        e.preventDefault();
        const STEP = e.shiftKey ? 50 : 10;
        N.pos_x = Math.max(0, Math.min(200000, N.pos_x + (e.key === 'ArrowLeft' ? -STEP : e.key === 'ArrowRight' ? STEP : 0)));
        N.pos_y = Math.max(0, Math.min(200000, N.pos_y + (e.key === 'ArrowUp' ? -STEP : e.key === 'ArrowDown' ? STEP : 0)));
        Dirty();
        RenderCanvas();
        ELEMENT('erdSvg').querySelector('[data-drag="' + KEY + '"]')?.focus();
    });
    ELEMENT('erdSvgExport').onclick = () => 
    {
        const SVG = ELEMENT('erdSvg').cloneNode(true);
        const B = Bounds();
        SVG.setAttribute('width', B.width);
        SVG.setAttribute('height', B.height);
        SVG.querySelectorAll('[tabindex]').forEach(n => n.removeAttribute('tabindex'));
        const BLOB = new Blob(['<?xml version="1.0" encoding="UTF-8"?>\n' + new XMLSerializer().serializeToString(SVG)], 
        {
            type: 'image/svg+xml'
        });
        const LOCAL_URL = URL.createObjectURL(BLOB);
        const A = document.createElement('a');
        A.href = LOCAL_URL;
        A.download = STATE.diagram.diagram_name.replace(/[^a-z0-9_-]/gi, '_') + '.svg';
        A.click();
        setTimeout(() => URL.revokeObjectURL(LOCAL_URL), 1000);
    };
    ELEMENT('erdImport').onclick = () => 
    {
        ELEMENT('erdImportFile').value = '';
        ELEMENT('erdImportFile').click();
    };
    ELEMENT('erdImportFile').onchange = Handle(async () => 
    {
        const FILE = ELEMENT('erdImportFile').files[0];
        if (!FILE) 
        {
            return;
        }
        if (FILE.size > 5242880) 
        {
            throw new Error('Choose an XLSX file up to 5 MB.');
        }
        if (!(await CONFIRM('Replace this ERD from Excel?', 'The workbook will replace every table, column and relationship in this model. Export the current ERD first if you need a backup.', 'Replace ERD'))) 
        {
            return;
        }
        await BUSY(ELEMENT('erdImport'), 'Importing…', async () => 
        {
            const BODY = new FormData();
            BODY.append('diagram_id', STATE.diagram.diagram_id);
            BODY.append('file', FILE);
            const RES = await fetch(BASE + 'excel_import.php', 
            {
                method: 'POST',
                headers: 
                {
                    'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content
                },
                body: BODY
            });
            const DATA = await RES.json();
            if (!RES.ok) 
            {
                throw new Error(DATA.error || 'Import failed.');
            }
            await Load(STATE.diagram.diagram_id);
            AT.toast('ERD imported.');
        });
    });
    window.addEventListener('beforeunload', e => 
    {
        if (STATE.dirty) 
        {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    new MutationObserver(() => 
    {
        if (STATE.diagram) 
        {
            RenderCanvas();
        }
    }).observe(document.documentElement, 
    {
        attributes: true,
        attributeFilter: ['data-theme']
    });
    const PARAMS = new URLSearchParams(location.search);
    Library().then(() => PARAMS.has('diagram_id') ? Load(Number(PARAMS.get('diagram_id'))) : PARAMS.has('table_id') ? Generator(PARAMS.get('table_id')) : null).catch(e => ERROR('erdError', e.message));
})();