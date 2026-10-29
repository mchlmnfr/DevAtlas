/**
 * Filename: datadict.js
 * Purpose: Coordinate data dictionary interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/* Data Dictionary catalog, editors and selective metadata import. */
(() => 
{
    'use strict';

    const El = id => document.getElementById(id);
    const ESC = AT.escapeHtml;
    const STATE = 
    {
        tables: [],
        engines: [],
        profiles: [],
        selected: null,
        detail: null,
        tab: 'columns',
        page: 1,
        columnPage: 1,
        request: 0,
        editor: null,
        importMode: 'live',
        objects: [],
        chosen: new Set(),
        document: null,
        connection: null,
        profileId: null,
        isEditingProfile: false,
        running: false,
        isStopped: false,
        working: false
    };
    const Api = (action, body) => AT.api(DD_CONFIG.api + '?action=' + action, body === undefined ? 
    {

    }
    : 
    {
        method: 'POST',
        body
    }).catch(error => 
    {
        error.isDdToasted = true;
        throw error;
    });
    const Run = fn => Promise.resolve().then(fn).catch(error => 
    {
        if (!error.isDdToasted) 
        {
            AT.toast(error.message, 'error');
        }
    });
    const EngineLabel = id => STATE.engines.find(engine => engine.id === id)?.label || id || 'Unspecified';
    const Coverage = table => table.column_count ? Math.round(table.documented_columns / table.column_count * 100) : 0;
    const Badge = (text, tone = 'gray') => `<span class="badge badge-${tone}">${ESC(text)}</span>`;
    const Type = column => 
    {
        let value = column.data_type || '';
        if (value.includes('(')) 
        {
            return value;
        }
        if (column.char_length !== null && column.char_length !== undefined) 
        {
            return `${value}(${Number(column.char_length) === -1 ? 'MAX' : column.char_length})`;
        }
        if (/^(numeric|decimal|number|decfloat)$/i.test(value) && column.numeric_precision !== null && column.numeric_precision !== undefined) 
        {
            return `${value}(${column.numeric_precision}${column.numeric_scale !== null && column.numeric_scale !== undefined ? ',' + column.numeric_scale : ''})`;
        }
        return value;
    };
    function FillSelect(id, values, label) 
    {
        const PREVIOUS = El(id).value;
        El(id).replaceChildren(new Option(label, ''));
        values.forEach(([value, text]) => El(id).add(new Option(text, value)));
        if (values.some(([value]) => value === PREVIOUS)) 
        {
            El(id).value = PREVIOUS;
        }
    }
    async function LoadCatalog() 
    {
        STATE.tables = await Api('list_tables');
        const SOURCES = [...new Set(STATE.tables.map(table => table.source_database))].sort((a, b) => a.localeCompare(b));
        FillSelect('ddSource', SOURCES.map(source => [source, source]), 'All databases');
        const ENGINES = [...new Set(STATE.tables.map(table => table.source_engine || ''))].filter(Boolean).sort();
        FillSelect('ddEngine', ENGINES.map(id => [id, EngineLabel(id)]), 'All engines');
        const COLUMNS = STATE.tables.reduce((sum, table) => sum + table.column_count, 0);
        const DOCUMENTED = STATE.tables.reduce((sum, table) => sum + table.documented_columns, 0);
        El('ddStats').innerHTML = [['Databases', SOURCES.length], ['Tables & views', STATE.tables.length], ['Documented fields', `${DOCUMENTED} / ${COLUMNS}`], ['Field coverage', `${COLUMNS ? Math.round(DOCUMENTED / COLUMNS * 100) : 0}%`]].map(([label, value]) => `<div class="stat-card"><div class="lbl">${label}</div><div class="num">${ESC(String(value))}</div></div>`).join('');
        RenderCatalog();
    }
    function FilteredTables() 
    {
        const SEARCH = El('ddSearch').value.trim().toLowerCase();
        return STATE.tables.filter(table => (!SEARCH || `${table.source_database} ${table.schema_name} ${table.table_name} ${table.display_name || ''} ${table.description || ''} ${table.business_owner || ''}`.toLowerCase().includes(SEARCH)) && (!El('ddSource').value || table.source_database === El('ddSource').value) && (!El('ddEngine').value || table.source_engine === El('ddEngine').value) && (!El('ddStatus').value || Boolean(table.is_active) === (El('ddStatus').value === 'active')) && (!El('ddCoverage').value || (El('ddCoverage').value === 'sensitive' ? table.sensitive_columns > 0 : El('ddCoverage').value === 'complete' ? table.column_count > 0 && table.documented_columns === table.column_count : !table.column_count || table.documented_columns < table.column_count)));
    }
    function RenderCatalog() 
    {
        const ROWS = FilteredTables();
        const PAGES = Math.max(1, Math.ceil(ROWS.length / 15));
        STATE.page = Math.max(1, Math.min(STATE.page, PAGES));
        El('ddCatalogCount').textContent = `${ROWS.length} object(s)`;
        El('ddTableList').innerHTML = ROWS.slice((STATE.page - 1) * 15, STATE.page * 15).map(table => `<button type="button" class="dd-table-item ${Number(table.table_id) === Number(STATE.selected) ? 'active' : ''}" data-table="${Number(table.table_id)}" aria-pressed="${Number(table.table_id) === Number(STATE.selected)}"><strong>${ESC(table.display_name || table.table_name)}</strong><small>${ESC(table.schema_name)}.${ESC(table.table_name)}</small><small>${ESC(table.source_database)}</small><span class="dd-table-meta">${Badge(table.object_type === 'view' ? 'View' : 'Table')}${!table.is_active ? Badge('Inactive') : ''}<span>${table.column_count} fields</span><span>${Coverage(table)}% described</span>${table.sensitive_columns ? Badge(`${table.sensitive_columns} sensitive`, 'amber') : ''}</span><progress class="dd-meter" value="${Coverage(table)}" max="100" aria-hidden="true"></progress></button>`).join('') || `<p class="empty-state">${STATE.tables.length ? 'No objects match the current filters.' : 'No tables documented yet. Import metadata or create your first table.'}</p>`;
        El('ddPage').textContent = `${STATE.page} / ${PAGES}`;
        El('ddPrev').disabled = STATE.page === 1;
        El('ddNext').disabled = STATE.page === PAGES;
        const SOURCE = El('ddSource').value;
        for (const [ID, LOCAL_URL] of [['ddExcel', DD_CONFIG.excel], ['ddJson', DD_CONFIG.api + '?action=metadata_export']]) 
        {
            El(ID).setAttribute('aria-disabled', String(!SOURCE));
            if (SOURCE) 
            {
                El(ID).href = LOCAL_URL + (LOCAL_URL.includes('?') ? '&' : '?') + 'source_database=' + encodeURIComponent(SOURCE);
            }
            else 
            {
                El(ID).removeAttribute('href');
            }
        }
        El('ddExportHint').textContent = SOURCE ? `Exports all documented objects in ${SOURCE}.` : 'Choose a database to export its dictionary.';
    }
    async function SelectTable(id, isScroll = false) 
    {
        const REQUEST = ++STATE.request;
        STATE.selected = Number(id);
        RenderCatalog();
        const TABLE = await AT.api(DD_CONFIG.api + '?action=get_table&table_id=' + encodeURIComponent(id));
        if (REQUEST !== STATE.request) 
        {
            return;
        }
        STATE.detail = TABLE;
        STATE.columnPage = 1;
        RenderDetail();
        if (isScroll && innerWidth < 1000) 
        {
            El('ddDetail').scrollIntoView(
            {
                behavior: 'smooth',
                block: 'start'
            });
        }
    }
    function RenderDetail() 
    {
        const TABLE = STATE.detail;
        if (!TABLE) 
        {
            return;
        }
        El('ddDetail').innerHTML = `<div class="dd-detail-header"><div>${Badge(EngineLabel(TABLE.source_engine), 'blue')} ${Badge(TABLE.object_type === 'view' ? 'View' : 'Table')} ${!TABLE.is_active ? Badge('Inactive') : ''}<h2>${ESC(TABLE.display_name || TABLE.table_name)}</h2><div class="hint">${ESC(TABLE.source_database)} / ${ESC(TABLE.schema_name)}.${ESC(TABLE.table_name)}</div></div><div class="dd-actions"><a class="btn btn-sm" href="${ESC(DD_CONFIG.api.replace(/\/datadict\/api\.php$/, '/diagrams/index.php'))}?table_id=${Number(TABLE.table_id)}">Generate ERD</a><button class="btn btn-sm" type="button" data-dd="edit-table">Edit table</button><button class="btn btn-sm btn-primary" type="button" data-dd="new-column">${AT.icon('plus')} Field</button></div></div>
      <div class="dd-tabs" role="tablist" aria-label="Table detail">${[['overview', 'Overview'], ['columns', `Columns (${TABLE.columns.length})`], ['relationships', 'Relationships']].map(([id, label]) => `<button type="button" role="tab" data-detail-tab="${id}" aria-selected="${STATE.tab === id}" class="${STATE.tab === id ? 'active' : ''}" aria-controls="ddDetailPanel">${label}</button>`).join('')}</div><div id="ddDetailPanel" role="tabpanel"></div>`;
        if (STATE.tab === 'overview') 
        {
            const DOCUMENTED = TABLE.columns.filter(column => column.description?.trim()).length;
            El('ddDetailPanel').innerHTML = `<h3>Purpose</h3><p class="dd-description ${TABLE.description ? '' : 'muted'}">${ESC(TABLE.description || 'Add a table description to explain what this data represents.')}</p><dl class="dd-overview-grid">${[['Business owner', TABLE.business_owner], ['Source system', TABLE.source_system], ['Schema', TABLE.schema_name], ['Technical name', TABLE.table_name], ['Field descriptions', `${DOCUMENTED} of ${TABLE.columns.length}`], ['Sensitive fields', TABLE.columns.filter(column => column.sensitive_flag).length], ['Created', TABLE.created_at?.slice(0, 10)], ['Updated', TABLE.updated_at?.slice(0, 10)]].map(([label, value]) => `<div><dt>${label}</dt><dd>${ESC(String(value ?? '—'))}</dd></div>`).join('')}</dl>`;
        }
        else if (STATE.tab === 'relationships') 
        {
            RenderRelationships();
        }
        else 
        {
            El('ddDetailPanel').innerHTML = `<div class="dd-column-tools"><input type="search" id="ddColumnSearch" aria-label="Search columns" placeholder="Find a field, type, description or business rule"><label class="dd-check"><input id="ddMissingDescriptions" type="checkbox">Missing descriptions</label><label class="dd-check"><input id="ddSensitiveOnly" type="checkbox">Sensitive only</label><button class="btn btn-sm" type="button" data-dd="copy-columns">Copy names</button></div><div class="table-wrap"><table class="dd-columns-table"><thead><tr><th>Field</th><th>Type / default</th><th>Constraints</th><th>Documentation</th><th></th></tr></thead><tbody id="ddColumnRows"></tbody></table></div><div class="dd-pagination"><span id="ddColumnCount" class="hint"></span><button class="btn btn-sm" type="button" data-dd="column-prev">Previous</button><button class="btn btn-sm" type="button" data-dd="column-next">Next</button></div>`;
            for (const ID of ['ddColumnSearch', 'ddMissingDescriptions', 'ddSensitiveOnly']) 
            {
                El(ID).addEventListener(ID === 'ddColumnSearch' ? 'input' : 'change', () => 
                {
                    STATE.columnPage = 1;
                    RenderColumns();
                });
            }
            RenderColumns();
        }
    }
    function FilteredColumns() 
    {
        const SEARCH = El('ddColumnSearch').value.toLowerCase();
        return STATE.detail.columns.filter(column => (!SEARCH || `${column.column_name} ${column.data_type} ${column.description || ''} ${column.business_rule || ''}`.toLowerCase().includes(SEARCH)) && (!El('ddMissingDescriptions').checked || !column.description?.trim()) && (!El('ddSensitiveOnly').checked || column.sensitive_flag));
    }
    function RenderColumns() 
    {
        const ROWS = FilteredColumns();
        const PAGES = Math.max(1, Math.ceil(ROWS.length / 25));
        STATE.columnPage = Math.min(Math.max(1, STATE.columnPage), PAGES);
        El('ddColumnRows').innerHTML = ROWS.slice((STATE.columnPage - 1) * 25, STATE.columnPage * 25).map(column => `<tr><td><strong>${ESC(column.column_name)}</strong><span class="dd-subtext">Position ${Number(column.ordinal_position) || '—'}</span>${column.sensitive_flag ? Badge('Sensitive / PII', 'amber') : ''}</td><td><code>${ESC(Type(column))}</code>${column.default_value !== null ? `<span class="dd-subtext">Default: ${ESC(column.default_value)}</span>` : ''}</td><td><div class="dd-column-flags">${column.is_primary_key ? Badge('PK', 'blue') : ''}${column.is_foreign_key ? Badge('FK', 'purple') : ''}${column.is_auto_increment ? Badge('Identity') : ''}${Badge(column.is_nullable ? 'Nullable' : 'Required', column.is_nullable ? 'gray' : 'blue')}</div>${column.is_foreign_key ? `<span class="dd-subtext">${ESC(column.fk_ref_table || '')} → ${ESC(column.fk_ref_column || '')}</span>` : ''}</td><td class="dd-column-description">${ESC(column.description || 'No description yet.')}${column.business_rule ? `<span class="dd-subtext">Rule: ${ESC(column.business_rule)}</span>` : ''}</td><td><button class="btn btn-sm" type="button" data-edit-column="${Number(column.column_id)}">Edit</button></td></tr>`).join('') || '<tr><td colspan="5" class="empty-state">No fields match. Add a field or adjust the filters.</td></tr>';
        El('ddColumnCount').textContent = `${ROWS.length} field(s) · page ${STATE.columnPage} of ${PAGES}`;
        document.querySelector('[data-dd="column-prev"]').disabled = STATE.columnPage === 1;
        document.querySelector('[data-dd="column-next"]').disabled = STATE.columnPage === PAGES;
    }
    function RenderRelationships() 
    {
        const TABLE = STATE.detail;
        const OUTGOING = TABLE.columns.filter(column => column.is_foreign_key);
        const OUTBOUND = OUTGOING.map(column => 
        {
            const MATCH = STATE.tables.find(candidate => candidate.source_database === TABLE.source_database && (candidate.schema_name === TABLE.schema_name && candidate.table_name === column.fk_ref_table || candidate.schema_name + '.' + candidate.table_name === column.fk_ref_table));
            return `<div class="dd-relationship"><strong>${ESC(column.column_name)}</strong> → ${MATCH ? `<button class="btn btn-sm" type="button" data-table="${Number(MATCH.table_id)}">${ESC(column.fk_ref_table)}</button>` : ESC(column.fk_ref_table || 'Unspecified table')}<p class="hint">Referenced field: ${ESC(column.fk_ref_column || 'Unspecified')}${MATCH ? '' : ' · Target not documented in this database.'}</p></div>`;
        }).join('');
        const INBOUND = (TABLE.referenced_by || []).map(column => `<div class="dd-relationship"><button class="btn btn-sm" type="button" data-table="${Number(column.table_id)}">${ESC(column.schema_name)}.${ESC(column.table_name)}</button><p class="hint">${ESC(column.column_name)} → ${ESC(column.fk_ref_column || 'Unspecified field')}</p></div>`).join('');
        El('ddDetailPanel').innerHTML = `<h3>References to other tables</h3>${OUTBOUND || '<p class="muted">No foreign keys documented.</p>'}<h3>Referenced by</h3>${INBOUND || '<p class="muted">No incoming references documented.</p>'}<p class="hint">Relationships reflect documented metadata. Columns with multiple foreign-key constraints show their first reference; use the source catalog for complete constraint definitions.</p>`;
    }
    const TABLE_FIELDS = [['source_database', 'Database label', 'text', 150, true], ['schema_name', 'Schema', 'text', 128, true], ['table_name', 'Technical table / view name', 'text', 128, true], ['display_name', 'Display name', 'text', 200], ['business_owner', 'Business owner', 'text', 150], ['source_system', 'Source system', 'text', 150], ['description', 'Purpose / description', 'textarea', 65535]];
    const COLUMN_FIELDS = [['column_name', 'Field name', 'text', 128, true], ['data_type', 'Data type', 'text', 8192, true], ['description', 'Description', 'textarea', 65535], ['business_rule', 'Business rule', 'textarea', 65535], ['ordinal_position', 'Position', 'number'], ['char_length', 'Character length (-1 = MAX)', 'number'], ['numeric_precision', 'Numeric precision', 'number'], ['numeric_scale', 'Numeric scale', 'number'], ['default_value', 'Default value', 'textarea', 65535], ['fk_ref_table', 'Reference table (schema.table)', 'text', 1000], ['fk_ref_column', 'Reference column', 'text', 1000]];
    function EditorField([name, label, kind, max, required], record) 
    {
        const VALUE = record[name] ?? '';
        return `<div class="form-row"><label for="dde_${name}">${label}${required ? ' *' : ''}</label>${kind === 'textarea' ? `<textarea id="dde_${name}" name="${name}" maxlength="${max}">${ESC(VALUE)}</textarea>` : `<input id="dde_${name}" name="${name}" type="${kind}" value="${ESC(String(VALUE))}" ${max ? `maxlength="${max}"` : ''} ${required ? 'required' : ''} ${kind === 'number' ? 'step="1"' : ''}>`}</div>`;
    }
    function OpenEditor(kind, record = 
    {

    }) 
    {
        El('ddEditorError').hidden = true;
        El('ddEditorError').textContent = '';
        STATE.editor = 
        {
            kind,
            record
        };
        const IS_TABLE = kind === 'table';
        const DEFAULTS = IS_TABLE ? 
        {
            source_database: El('ddSource').value || 'Manual Entry',
            schema_name: 'public',
            is_active: true,
            object_type: 'table',
            ...record
        }
        : 
        {
            ordinal_position: (STATE.detail?.columns.length || 0) + 1,
            is_nullable: true,
            ...record
        };
        El('ddEditorTitle').textContent = `${record[IS_TABLE ? 'table_id' : 'column_id'] ? 'Edit' : 'New'} ${IS_TABLE ? 'table / view' : 'field'}`;
        let html = '';
        if (IS_TABLE) 
        {
            html = TABLE_FIELDS.map(field => EditorField(field, DEFAULTS)).join('');
            const OPTIONS = [['', 'Unspecified'], ...STATE.engines.map(engine => [engine.id, engine.label]), ['other', 'Other engine']];
            if (DEFAULTS.source_engine && !OPTIONS.some(([id]) => id === DEFAULTS.source_engine)) 
            {
                OPTIONS.push([DEFAULTS.source_engine, DEFAULTS.source_engine]);
            }
            html += `<div class="form-grid"><div class="form-row"><label for="dde_source_engine">Database engine</label><select name="source_engine" id="dde_source_engine">${OPTIONS.map(([id, label]) => `<option value="${ESC(id)}" ${id === (DEFAULTS.source_engine || '') ? 'selected' : ''}>${ESC(label)}</option>`).join('')}</select></div><div class="form-row"><label for="dde_object_type">Object type</label><select name="object_type" id="dde_object_type"><option value="table">Table</option><option value="view" ${DEFAULTS.object_type === 'view' ? 'selected' : ''}>View</option></select></div></div><label class="dd-check"><input type="checkbox" name="is_active" ${DEFAULTS.is_active ? 'checked' : ''}>Active</label>`;
        }
        else 
        {
            html = COLUMN_FIELDS.slice(0, 4).map(field => EditorField(field, DEFAULTS)).join('');
            html += `<div class="dd-boolean-grid">${[['is_nullable', 'Nullable'], ['is_primary_key', 'Primary key'], ['is_foreign_key', 'Foreign key'], ['is_auto_increment', 'Identity / auto increment'], ['sensitive_flag', 'Sensitive / PII']].map(([name, label]) => `<label class="dd-check"><input type="checkbox" name="${name}" ${DEFAULTS[name] ? 'checked' : ''}>${label}</label>`).join('')}</div><details><summary>Type details, defaults &amp; reference</summary><div class="form-grid">${COLUMN_FIELDS.slice(4).map(field => EditorField(field, DEFAULTS)).join('')}</div></details>`;
        }
        El('ddEditorFields').innerHTML = html;
        El('ddEditorDelete').hidden = !record[IS_TABLE ? 'table_id' : 'column_id'];
        El('ddEditor').showModal();
    }
    async function SaveEditor(event) 
    {
        event.preventDefault();
        const 
        {
            kind: KIND,
            record: RECORD
        }
        = STATE.editor;
        const IS_TABLE = KIND === 'table';
        const DATA = Object.fromEntries(new FormData(El('ddEditorForm')));
        for (const KEY of IS_TABLE ? ['is_active'] : ['is_nullable', 'is_primary_key', 'is_foreign_key', 'is_auto_increment', 'sensitive_flag']) 
        {
            DATA[KEY] = Boolean(El('ddEditorForm').elements[KEY].checked);
        }
        if (IS_TABLE) 
        {
            DATA.table_id = RECORD.table_id || null;
        }
        else 
        {
            DATA.column_id = RECORD.column_id || null;
            DATA.table_id = STATE.selected;
        }
        El('ddEditorSave').disabled = true;
        try 
        {
            const RESULT = await Api(IS_TABLE ? 'save_table' : 'save_column', DATA);
            El('ddEditor').close();
            await LoadCatalog();
            if (!IS_TABLE) 
            {
                STATE.tab = 'columns';
            }
            await SelectTable(IS_TABLE ? RESULT.table_id : STATE.selected);
            AT.toast(IS_TABLE ? 'Table saved.' : 'Field saved.');
        }
        finally 
        {
            El('ddEditorSave').disabled = false;
        }
    }
    async function EditorWork(operation) 
    {
        El('ddEditorError').hidden = true;
        try 
        {
            await operation();
        }
        catch (error) 
        {
            El('ddEditorError').textContent = error.message;
            El('ddEditorError').hidden = false;
        }
    }
    async function DeleteEditor() 
    {
        const 
        {
            kind: KIND,
            record: RECORD
        }
        = STATE.editor;
        const IS_TABLE = KIND === 'table';
        if (!(await AT.confirmAction(IS_TABLE ? 'Delete this table and all its documented columns and requirement links?' : 'Delete this field and its requirement links?'))) 
        {
            return;
        }
        await Api(IS_TABLE ? 'delete_table' : 'delete_column', IS_TABLE ? 
        {
            table_id: RECORD.table_id
        }
        : 
        {
            column_id: RECORD.column_id,
            table_id: STATE.selected
        });
        El('ddEditor').close();
        if (IS_TABLE) 
        {
            STATE.selected = null;
            STATE.detail = null;
            STATE.request++;
            El('ddDetail').innerHTML = '<p class="empty-state">Table deleted. Select another object from the catalog.</p>';
        }
        await LoadCatalog();
        if (!IS_TABLE) 
        {
            await SelectTable(STATE.selected);
        }
        AT.toast(IS_TABLE ? 'Table deleted.' : 'Field deleted.');
    }
    function ImportStatus(message, isError = false) 
    {
        El('ddImportStatus').textContent = message;
        El('ddImportStatus').classList.toggle('error', isError);
    }
    function ClearObjects() 
    {
        El('ddImportProgress').hidden = true;
        STATE.objects = [];
        STATE.chosen.clear();
        El('ddObjectSelection').hidden = true;
        El('ddPreviewDetails').hidden = true;
        El('ddRunImport').disabled = true;
        El('ddImportFooterHint').textContent = 'Choose a source to begin.';
    }
    function InvalidateConnection() 
    {
        STATE.connection = null;
        El('ddSchemaFields').hidden = true;
        ClearObjects();
    }
    async function LoadProfiles() 
    {
        STATE.profiles = await Api('list_connections');
        FillSelect('ddConnection', STATE.profiles.map(profile => [String(profile.connection_id), `${profile.label} · ${EngineLabel(profile.db_engine)}`]), 'New connection');
    }
    async function OpenImport() 
    {
        if (!STATE.engines.length) 
        {
            STATE.engines = await Api('engines');
        }
        El('ddImportDialog').showModal();
        ImportStatus('');
        STATE.profileId = null;
        STATE.isEditingProfile = false;
        STATE.document = null;
        STATE.isStopped = false;
        ClearObjects();
        El('ddImportProgress').hidden = true;
        El('ddConnectPassword').value = '';
        El('ddMetadataFile').value = '';
        El('ddFileDatabase').value = '';
        El('ddImportResults').replaceChildren();
        await LoadProfiles();
        El('ddConnection').value = '';
        SelectProfile();
        SetImportMode('live');
    }
    function SetImportMode(mode) 
    {
        if (STATE.running || STATE.working) 
        {
            return;
        }
        STATE.importMode = mode;
        ClearObjects();
        ImportStatus('');
        El('ddLivePanel').hidden = mode !== 'live';
        El('ddFilePanel').hidden = mode !== 'file';
        for (const [ID, SELECTED] of [['ddLiveTab', mode === 'live'], ['ddFileTab', mode === 'file']]) 
        {
            El(ID).classList.toggle('active', SELECTED);
            El(ID).setAttribute('aria-selected', String(SELECTED));
        }
    }
    function EngineChanged(isReset = true) 
    {
        const ENGINE = STATE.engines.find(engine => engine.id === El('ddConnectEngine').value);
        if (!ENGINE) 
        {
            return;
        }
        const IS_SQLITE = ENGINE.id === 'sqlite';
        const IS_ODBC = ENGINE.id === 'odbc';
        if (isReset) 
        {
            El('ddConnectPort').value = ENGINE.port;
            InvalidateConnection();
        }
        El('ddHostRow').hidden = IS_SQLITE;
        El('ddPortRow').hidden = IS_SQLITE || IS_ODBC;
        El('ddCredentialRows').hidden = IS_SQLITE;
        El('ddHostLabel').textContent = IS_ODBC ? 'ODBC System DSN name' : 'Host';
        El('ddDatabaseLabel').textContent = IS_SQLITE ? 'Absolute SQLite file path on this server' : IS_ODBC ? 'Catalog / database (optional)' : ENGINE.id === 'oracle' ? 'Oracle service name' : ENGINE.id === 'firebird' ? 'Database path or alias on target server' : 'Database name';
        El('ddConnectDatabase').placeholder = IS_SQLITE ? 'C:\\data\\application.sqlite' : IS_ODBC ? 'Leave blank for the DSN default' : '';
        El('ddDriverInfo').textContent = (ENGINE.available ? 'Driver available. ' : 'Driver not installed on this server. ') + ENGINE.requirement + (IS_ODBC ? ' Compatible ODBC catalogs can cover Access, Snowflake, SAP HANA and other engines. Auto-increment metadata may be unavailable.' : '');
        El('ddTestConnection').disabled = !ENGINE.available;
        El('ddLoadSchemas').disabled = !ENGINE.available;
    }
    function SelectProfile() 
    {
        InvalidateConnection();
        ImportStatus('');
        STATE.profileId = Number(El('ddConnection').value) || null;
        STATE.isEditingProfile = false;
        El('ddConnectPassword').value = '';
        const PROFILE = STATE.profiles.find(profile => Number(profile.connection_id) === STATE.profileId);
        El('ddConnectionFields').hidden = Boolean(PROFILE);
        El('ddProfileActions').hidden = !PROFILE;
        El('ddProfileSummary').hidden = !PROFILE;
        El('ddClearPasswordRow').hidden = true;
        El('ddSaveProfile').checked = false;
        El('ddConnectPassword').placeholder = '';
        ProfileSaveToggle();
        if (PROFILE) 
        {
            El('ddProfileSummary').textContent = `${PROFILE.label} · ${EngineLabel(PROFILE.db_engine)} · ${PROFILE.host || 'Server file'}${PROFILE.port ? ':' + PROFILE.port : ''} · ${PROFILE.database_name || 'Default catalog'}`;
            El('ddConnectEngine').value = PROFILE.db_engine;
            EngineChanged(false);
        }
        else 
        {
            El('ddConnectEngine').value = 'postgresql';
            El('ddConnectHost').value = '';
            El('ddConnectDatabase').value = '';
            El('ddConnectUser').value = '';
            El('ddProfileLabel').value = '';
            EngineChanged();
        }
    }
    function ProfileSaveToggle() 
    {
        El('ddProfileLabelRow').hidden = !El('ddSaveProfile').checked;
        El('ddSaveProfileBtn').hidden = !El('ddSaveProfile').checked;
    }
    function EditProfile() 
    {
        const PROFILE = STATE.profiles.find(profile => Number(profile.connection_id) === STATE.profileId);
        if (!PROFILE) 
        {
            return;
        }
        STATE.isEditingProfile = true;
        InvalidateConnection();
        El('ddConnectionFields').hidden = false;
        El('ddProfileSummary').hidden = true;
        for (const [ID, KEY] of [['ddConnectEngine', 'db_engine'], ['ddConnectPort', 'port'], ['ddConnectHost', 'host'], ['ddConnectDatabase', 'database_name'], ['ddConnectUser', 'username'], ['ddProfileLabel', 'label']]) 
        {
            El(ID).value = PROFILE[KEY] ?? '';
        }
        El('ddConnectPassword').value = '';
        El('ddConnectPassword').placeholder = 'Leave blank to keep stored password';
        El('ddClearPasswordRow').hidden = false;
        El('ddClearPassword').checked = false;
        El('ddSaveProfile').checked = true;
        ProfileSaveToggle();
        EngineChanged(false);
    }
    function ConnectionParams() 
    {
        if (STATE.profileId && !STATE.isEditingProfile) 
        {
            return (
            {
                connection_id: STATE.profileId
            });
        }
        return (
        {
            db_engine: El('ddConnectEngine').value,
            host: El('ddConnectHost').value,
            port: El('ddConnectPort').value,
            username: El('ddConnectUser').value,
            password: El('ddConnectPassword').value,
            database_name: El('ddConnectDatabase').value
        });
    }
    async function SaveProfile() 
    {
        const BODY = 
        {
            ...ConnectionParams(),
            label: El('ddProfileLabel').value,
            connection_id: STATE.isEditingProfile ? STATE.profileId : null,
            clear_password: El('ddClearPassword').checked
        };
        const RESULT = await Api('save_connection', BODY);
        await LoadProfiles();
        El('ddConnection').value = String(RESULT.connection_id);
        SelectProfile();
        AT.toast('Connection profile saved.');
        return RESULT.connection_id;
    }
    async function TestConnection() 
    {
        if (STATE.isEditingProfile && !El('ddConnectPassword').value && !El('ddClearPassword').checked) 
        {
            ImportStatus('Save the edited profile before testing with its stored password.');
            return;
        }
        await ImportWork(async () => 
        {
            const RESULT = await Api('test_connection', ConnectionParams());
            ImportStatus('Connected. ' + RESULT.version);
        });
    }
    async function ImportWork(fn) 
    {
        if (STATE.working || STATE.running) 
        {
            return;
        }
        STATE.working = true;
        const CONTROLS = [...El('ddImportDialog').querySelectorAll('input,select,button')].filter(control => !control.disabled);
        CONTROLS.forEach(control => control.disabled = true);
        try 
        {
            await fn();
        }
        catch (error) 
        {
            ImportStatus(error.message, true);
        }
        finally 
        {
            CONTROLS.forEach(control => control.disabled = false);
            STATE.working = false;
            EngineChanged(false);
            El('ddRunImport').disabled = !STATE.chosen.size;
        }
    }
    async function Connect() 
    {
        await ImportWork(async () => 
        {
            let params = ConnectionParams();
            if (El('ddSaveProfile').checked && (!STATE.profileId || STATE.isEditingProfile)) 
            {
                params = 
                {
                    connection_id: await SaveProfile()
                };
            }
            ImportStatus('Connecting and loading schemas…');
            const SCHEMAS = await Api('introspect_schemas', params);
            STATE.connection = params;
            El('ddImportSchema').replaceChildren(...SCHEMAS.map(schema => new Option(schema, schema)));
            if (!SCHEMAS.length) 
            {
                ImportStatus('No accessible schemas found. Check metadata permissions.');
                return;
            }
            const PROFILE = STATE.profiles.find(profile => Number(profile.connection_id) === STATE.profileId);
            const ENGINE = STATE.engines.find(engine => engine.id === (PROFILE?.db_engine || params.db_engine));
            const PREFERRED = PROFILE?.default_schema || ENGINE?.schema;
            if (SCHEMAS.includes(PREFERRED)) 
            {
                El('ddImportSchema').value = PREFERRED;
            }
            El('ddImportDatabase').value = PROFILE?.label || (params.db_engine === 'sqlite' ? params.database_name.split(/[\\/]/).pop() : params.database_name) || 'Imported database';
            El('ddSchemaFields').hidden = false;
            ImportStatus('Connected. Choose a schema and load its objects.');
        });
    }
    async function LoadObjects() 
    {
        if (!STATE.connection) 
        {
            throw new Error('Connect to the source first.');
        }
        await ImportWork(async () => 
        {
            ClearObjects();
            ImportStatus('Loading tables and views…');
            STATE.objects = (await Api('introspect_tables', 
            {
                ...STATE.connection,
                schema: El('ddImportSchema').value,
                include_views: El('ddIncludeViews').checked
            })).map((object, index) => (
            {
                ...object,
                index,
                schema_name: El('ddImportSchema').value
            }));
            El('ddObjectSelection').hidden = false;
            El('ddObjectSearch').value = '';
            RenderObjects();
            ImportStatus(`${STATE.objects.length} object(s) available. Select the objects to import.`);
        });
    }
    async function PreviewFile() 
    {
        const FILE = El('ddMetadataFile').files[0];
        if (!FILE) 
        {
            throw new Error('Choose a metadata JSON file.');
        }
        if (FILE.size > 6 * 1024 * 1024) 
        {
            throw new Error('File exceeds 6 MB.');
        }
        await ImportWork(async () => 
        {
            ClearObjects();
            let document;
            try 
            {
                document = JSON.parse(await FILE.text());
            }
            catch 
            {
                throw new Error('The selected file is not valid JSON.');
            }
            const OVERRIDE = El('ddFileDatabase').value.trim();
            if (OVERRIDE && document && Array.isArray(document.tables)) 
            {
                document.source_database = OVERRIDE;
                document.tables = document.tables.map(table => (
                {
                    ...table,
                    source_database: OVERRIDE
                }));
            }
            const OBJECTS = await Api('preview_metadata', 
            {
                document
            });
            STATE.document = document;
            STATE.objects = OBJECTS.map((object, index) => (
            {
                ...object,
                index
            }));
            El('ddObjectSelection').hidden = false;
            El('ddObjectSearch').value = '';
            RenderObjects();
            ImportStatus('Metadata validated. Select the objects to import.');
        });
    }
    function VisibleObjects() 
    {
        const SEARCH = El('ddObjectSearch').value.toLowerCase();
        return STATE.objects.filter(object => `${object.source_database || ''} ${object.schema_name} ${object.table_name}`.toLowerCase().includes(SEARCH));
    }
    function RenderObjects() 
    {
        El('ddObjectList').innerHTML = VisibleObjects().map(object => `<div class="dd-object-row"><input type="checkbox" id="ddObject_${object.index}" data-object="${object.index}" ${STATE.chosen.has(object.index) ? 'checked' : ''}><label for="ddObject_${object.index}">${ESC(object.schema_name)}.${ESC(object.table_name)}${object.column_count !== undefined ? `<span class="dd-subtext">${Number(object.column_count)} fields · ${ESC(object.source_database)}</span>` : ''}</label>${Badge(object.object_type === 'view' ? 'View' : 'Table')}<button class="btn btn-sm" type="button" data-preview-object="${object.index}">Preview</button></div>`).join('') || '<p class="empty-state">No matching objects.</p>';
        UpdateSelection();
    }
    function UpdateSelection() 
    {
        El('ddSelectionCount').textContent = `${STATE.chosen.size} of ${STATE.objects.length} selected`;
        El('ddRunImport').disabled = !STATE.chosen.size || STATE.running;
        El('ddImportFooterHint').textContent = STATE.chosen.size ? `${STATE.chosen.size} object(s) selected for import.` : 'Select objects to import.';
    }
    async function PreviewObject(index) 
    {
        const OBJECT = STATE.objects[index];
        if (!OBJECT) 
        {
            return;
        }
        await ImportWork(async () => 
        {
            const COLUMNS = STATE.importMode === 'file' ? STATE.document.tables[index].columns || [] : await Api('preview_table', 
            {
                ...STATE.connection,
                schema: OBJECT.schema_name,
                table_name: OBJECT.table_name
            });
            El('ddColumnPreview').innerHTML = `<p class="hint">${ESC(OBJECT.schema_name)}.${ESC(OBJECT.table_name)} · ${COLUMNS.length} fields${COLUMNS.length > 100 ? ' · showing first 100' : ''}</p><table><thead><tr><th>Name</th><th>Type</th><th>Nullable</th><th>Key</th></tr></thead><tbody>${COLUMNS.slice(0, 100).map(column => `<tr><td>${ESC(column.column_name)}</td><td>${ESC(Type(column))}</td><td>${column.is_nullable === false ? 'No' : 'Yes'}</td><td>${column.is_primary_key ? 'PK ' : ''}${column.is_foreign_key ? 'FK' : ''}</td></tr>`).join('')}</tbody></table>`;
            El('ddPreviewDetails').hidden = false;
            El('ddPreviewDetails').open = true;
        });
    }
    async function ImportSelected() 
    {
        if (STATE.running || !STATE.chosen.size) 
        {
            return;
        }
        const SELECTED = [...STATE.chosen].map(index => STATE.objects[index]);
        const SOURCE = El('ddImportDatabase').value.trim();
        if (STATE.importMode === 'live' && !SOURCE) 
        {
            throw new Error('Enter a dictionary database label.');
        }
        STATE.running = true;
        STATE.isStopped = false;
        let completed = 0;
        let success = 0;
        const CONTROLS = [...El('ddImportDialog').querySelectorAll('input,select,button')].filter(control => !control.disabled);
        CONTROLS.forEach(control => control.disabled = true);
        El('ddStopImport').hidden = false;
        El('ddStopImport').disabled = false;
        El('ddImportProgress').hidden = false;
        El('ddImportResults').replaceChildren();
        El('ddProgressBar').max = SELECTED.length;
        El('ddProgressBar').value = 0;
        try 
        {
            for (const OBJECT of SELECTED) 
            {
                if (STATE.isStopped) 
                {
                    break;
                }
                ImportStatus(`Importing ${OBJECT.schema_name}.${OBJECT.table_name}…`);
                try 
                {
                    const RESPONSE = STATE.importMode === 'live' ? await Api('import_tables', 
                    {
                        ...STATE.connection,
                        schema: OBJECT.schema_name,
                        source_database: SOURCE,
                        tables: [OBJECT.table_name]
                    }) : await Api('import_metadata', 
                    {
                        document: 
                        {
                            ...STATE.document,
                            tables: [STATE.document.tables[OBJECT.index]]
                        }
                    });
                    const RESULT = RESPONSE.results[0];
                    if (!RESULT?.ok) 
                    {
                        throw new Error(RESULT?.error || 'Import failed.');
                    }
                    success++;
                    STATE.chosen.delete(OBJECT.index);
                    AppendResult(`${OBJECT.schema_name}.${OBJECT.table_name}: ${RESULT.created_columns} new, ${RESULT.updated_columns} refreshed field(s)${RESULT.retained_columns ? `; ${RESULT.retained_columns} missing source field(s) retained for review` : ''}.`);
                }
                catch (error) 
                {
                    AppendResult(`${OBJECT.schema_name}.${OBJECT.table_name}: ${error.message}`, true);
                }
                completed++;
                El('ddProgressBar').value = completed;
                El('ddProgressText').textContent = `${completed} of ${SELECTED.length} processed · ${success} imported`;
            }
            ImportStatus(`${STATE.isStopped ? 'Stopped. ' : ''}${success} of ${SELECTED.length} selected object(s) imported. ${STATE.chosen.size ? 'Failed or unprocessed objects remain selected for retry.' : ''}`);
            await LoadCatalog();
            if (STATE.selected) 
            {
                await SelectTable(STATE.selected);
            }
        }
        finally 
        {
            STATE.running = false;
            CONTROLS.forEach(control => control.disabled = false);
            El('ddStopImport').hidden = true;
            EngineChanged(false);
            RenderObjects();
        }
    }
    function AppendResult(text, isError = false) 
    {
        const ROW = document.createElement('div');
        ROW.className = 'dd-import-result' + (isError ? ' error' : '');
        ROW.textContent = text;
        El('ddImportResults').append(ROW);
    }
    function CloseDialog(id) 
    {
        if (id === 'ddImportDialog' && (STATE.running || STATE.working)) 
        {
            ImportStatus('Use Stop after current table to finish the current operation before closing.');
            return;
        }
        El(id).close();
    }
    document.addEventListener('click', event => 
    {
        const BUTTON = event.target.closest('button,a');
        if (!BUTTON) 
        {
            return;
        }
        if (BUTTON.dataset.close) 
        {
            CloseDialog(BUTTON.dataset.close);
        }
        if (BUTTON.dataset.table) 
        {
            Run(() => SelectTable(BUTTON.dataset.table, true));
        }
        if (BUTTON.dataset.detailTab) 
        {
            STATE.tab = BUTTON.dataset.detailTab;
            RenderDetail();
        }
        if (BUTTON.dataset.editColumn) 
        {
            OpenEditor('column', STATE.detail.columns.find(column => Number(column.column_id) === Number(BUTTON.dataset.editColumn)));
        }
        if (BUTTON.dataset.previewObject !== undefined) 
        {
            Run(() => PreviewObject(Number(BUTTON.dataset.previewObject)));
        }
        if (BUTTON.dataset.dd === 'import') 
        {
            Run(OpenImport);
        }
        if (BUTTON.dataset.dd === 'edit-table') 
        {
            OpenEditor('table', STATE.detail);
        }
        if (BUTTON.dataset.dd === 'new-column') 
        {
            OpenEditor('column');
        }
        if (BUTTON.dataset.dd === 'column-prev') 
        {
            STATE.columnPage--;
            RenderColumns();
        }
        if (BUTTON.dataset.dd === 'column-next') 
        {
            STATE.columnPage++;
            RenderColumns();
        }
        if (BUTTON.dataset.dd === 'copy-columns') 
        {
            Run(async () => 
            {
                await navigator.clipboard.writeText(FilteredColumns().map(column => column.column_name).join('\n'));
                AT.toast('Field names copied.');
            });
        }
    });
    for (const ID of ['ddSearch', 'ddSource', 'ddEngine', 'ddStatus', 'ddCoverage']) 
    {
        El(ID).addEventListener(ID === 'ddSearch' ? 'input' : 'change', () => 
        {
            STATE.page = 1;
            RenderCatalog();
        });
    }
    El('ddPrev').addEventListener('click', () => 
    {
        STATE.page--;
        RenderCatalog();
    });
    El('ddNext').addEventListener('click', () => 
    {
        STATE.page++;
        RenderCatalog();
    });
    El('ddRefresh').addEventListener('click', () => Run(async () => 
    {
        await LoadCatalog();
        if (STATE.selected) 
        {
            await SelectTable(STATE.selected);
        }
    }));
    El('ddNewTable').addEventListener('click', () => OpenEditor('table'));
    El('ddImport').addEventListener('click', () => Run(OpenImport));
    El('ddEditorForm').addEventListener('submit', event => 
    {
        event.preventDefault();
        EditorWork(() => SaveEditor(event));
    });
    El('ddEditorDelete').addEventListener('click', () => EditorWork(DeleteEditor));
    El('ddLiveTab').addEventListener('click', () => SetImportMode('live'));
    El('ddFileTab').addEventListener('click', () => SetImportMode('file'));
    El('ddConnection').addEventListener('change', SelectProfile);
    El('ddConnectEngine').addEventListener('change', () => EngineChanged());
    for (const ID of ['ddConnectHost', 'ddConnectPort', 'ddConnectDatabase', 'ddConnectUser', 'ddConnectPassword']) 
    {
        El(ID).addEventListener('input', InvalidateConnection);
    }
    for (const ID of ['ddImportSchema', 'ddIncludeViews', 'ddFileDatabase', 'ddMetadataFile']) 
    {
        El(ID).addEventListener('change', ClearObjects);
    }
    El('ddSaveProfile').addEventListener('change', ProfileSaveToggle);
    El('ddEditProfile').addEventListener('click', EditProfile);
    El('ddSaveProfileBtn').addEventListener('click', () => Run(() => ImportWork(SaveProfile)));
    El('ddDeleteProfile').addEventListener('click', () => Run(async () => 
    {
        if (!(await AT.confirmAction('Delete this saved connection profile?'))) 
        {
            return;
        }
        await ImportWork(async () => 
        {
            await Api('delete_connection', 
            {
                connection_id: STATE.profileId
            });
            await LoadProfiles();
            El('ddConnection').value = '';
            SelectProfile();
        });
    }));
    El('ddTestConnection').addEventListener('click', () => Run(TestConnection));
    El('ddLoadSchemas').addEventListener('click', () => Run(Connect));
    El('ddLoadObjects').addEventListener('click', () => Run(LoadObjects));
    El('ddPreviewFile').addEventListener('click', () => Run(PreviewFile));
    El('ddObjectSearch').addEventListener('input', RenderObjects);
    El('ddObjectList').addEventListener('change', event => 
    {
        if (event.target.dataset.object !== undefined) 
        {
            const INDEX = Number(event.target.dataset.object);
            if (event.target.checked) 
            {
                STATE.chosen.add(INDEX);
            }
            else 
            {
                STATE.chosen.delete(INDEX);
            }
            UpdateSelection();
        }
    });
    El('ddSelectVisible').addEventListener('click', () => 
    {
        VisibleObjects().forEach(object => STATE.chosen.add(object.index));
        RenderObjects();
    });
    El('ddClearSelection').addEventListener('click', () => 
    {
        STATE.chosen.clear();
        RenderObjects();
    });
    El('ddRunImport').addEventListener('click', () => Run(ImportSelected));
    El('ddStopImport').addEventListener('click', () => 
    {
        STATE.isStopped = true;
        El('ddStopImport').disabled = true;
        ImportStatus('Stopping after the current table finishes…');
    });
    El('ddImportDialog').addEventListener('cancel', event => 
    {
        if (STATE.running || STATE.working) 
        {
            event.preventDefault();
            ImportStatus('Wait for the current operation or stop after the current table.');
        }
    });
    El('ddImportDialog').addEventListener('close', () => 
    {
        El('ddConnectPassword').value = '';
        STATE.connection = null;
        STATE.document = null;
        ClearObjects();
    });
    document.addEventListener('keydown', event => 
    {
        const TABS = event.target.closest('.dd-tabs');
        if (!TABS || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) 
        {
            return;
        }
        const BUTTONS = [...TABS.querySelectorAll('[role="tab"]')];
        const CURRENT = BUTTONS.indexOf(event.target);
        if (CURRENT < 0) 
        {
            return;
        }
        event.preventDefault();
        const NEXT = event.key === 'Home' ? 0 : event.key === 'End' ? BUTTONS.length - 1 : (CURRENT + (event.key === 'ArrowRight' ? 1 : -1) + BUTTONS.length) % BUTTONS.length;
        BUTTONS[NEXT].focus();
        BUTTONS[NEXT].click();
    });
    El('ddExample').href = DD_CONFIG.api + '?action=metadata_example';
    Run(async () => 
    {
        STATE.engines = await Api('engines');
        El('ddConnectEngine').replaceChildren(...STATE.engines.map(engine => new Option(engine.label, engine.id)));
        EngineChanged();
        await LoadCatalog();
        const INITIAL = new URLSearchParams(location.search);
        if (INITIAL.has('table_id')) 
        {
            await SelectTable(Number(INITIAL.get('table_id')));
            if (INITIAL.has('column_id')) 
            {
                const COLUMN = STATE.detail?.columns.find(c => Number(c.column_id) === Number(INITIAL.get('column_id')));
                if (COLUMN) 
                {
                    STATE.tab = 'columns';
                    RenderDetail();
                    El('ddColumnSearch').value = COLUMN.column_name;
                    RenderColumns();
                    El('ddColumnSearch').focus();
                }
            }
        }
    });
})();