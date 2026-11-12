/**
 * Filename: servers.js
 * Purpose: Coordinate servers interface actions, state and API feedback.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Server Inventory. One list per entity and one reusable record editor. */
(() => 
{
    'use strict';

    const ROOT = document.getElementById('serverInventory');
    if (!ROOT) 
    {
        return;
    }
    const GetElement = id => document.getElementById(id);
    const ESC = AT.escapeHtml;
    const Yes = value => [true, 1, '1', 't', 'true'].includes(value);
    const COMPARE = new Intl.Collator(undefined, 
    {
        numeric: true,
        sensitivity: 'base'
    });
    const ENVIRONMENTS = ['Dev', 'Prod', 'SIT', 'UAT', 'SAT', 'SIT & UAT', 'UAT & SAT', 'SIT, UAT & SAT'];
    const APPLICATION_ENVIRONMENTS = ['Dev', 'SIT', 'UAT', 'SAT', 'Prod', 'DR', 'Non-Prod(DR)'];
    const STATE = 
    {
        servers: [],
        groups: [],
        renewals: [],
        errors: 
        {

        },
        tab: 'servers',
        server: null,
        group: null,
        detailTab: 'overview',
        page: 1,
        protocols: null,
        credentials: null,
        epoch: 0
    };
    const EDITOR = GetElement('inventoryEditor');
    const FORM = GetElement('inventoryEditorForm');
    const SHARING = GetElement('inventoryShareDialog');
    const SECRET_TIMERS = new Map();
    let editorModel = null;
    let isEditorBusy = false;
    let isRefreshBusy = false;
    let isShareBusy = false;
    let sharedCredentialId = null;
    async function Api(action, params = 
    {

    }, body = null) 
    {
        const LOCAL_URL = new URL(ROOT.dataset.api, location.origin);
        LOCAL_URL.searchParams.set('action', action);
        Object.entries(params).forEach(([key, value]) => LOCAL_URL.searchParams.set(key, String(value)));
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
            throw new Error(DATA?.error || 'The inventory could not be loaded. Please try again.');
        }
        return DATA;
    }
    function Message(text, isError = false) 
    {
        GetElement('inventoryPageMessage').textContent = text;
        GetElement('inventoryPageMessage').classList.toggle('is-error', isError);
    }
    function SafeUrl(value) 
    {
        try 
        {
            const LOCAL_URL = new URL(value);
            return ['http:', 'https:'].includes(LOCAL_URL.protocol) ? LOCAL_URL.href : '';
        }
        catch (_) 
        {
            return '';
        }
    }
    function Link(value, label = 'Open link') 
    {
        const LOCAL_URL = SafeUrl(value);
        return LOCAL_URL ? `<a href="${ESC(LOCAL_URL)}" target="_blank" rel="noopener noreferrer">${ESC(label)} ${AT.icon('arrow-up-right')}</a>` : '<span class="muted">—</span>';
    }
    function Badge(value, tone = 'gray') 
    {
        return `<span class="badge badge-${tone}">${ESC(String(value))}</span>`;
    }
    function EnvBadge(value) 
    {
        return Badge(value, value === 'Prod' ? 'coral' : value === 'Dev' ? 'green' : 'blue');
    }
    function Envs(csv) 
    {
        return csv ? String(csv).split(',').map(value => EnvBadge(value.trim())).join(' ') : '<span class="muted">—</span>';
    }
    function GetNumber(value) 
    {
        return value === null || value === undefined || value === '' ? '—' : Number(value).toLocaleString();
    }
    function Action(label, name, id = '', extra = '') 
    {
        return `<button type="button" class="inventory-text-button" data-${name}="${ESC(String(id))}" ${extra}>${ESC(label.replace(/ \u2192$/u, ''))}${label.endsWith('\u2192') ? AT.icon('arrow-right') : ''}</button>`;
    }
    function EditButton(kind, id) 
    {
        return Action('Edit', 'edit', id, `data-kind="${kind}"`);
    }
    function Empty(title, detail = '', isRetry = false) 
    {
        return `<div class="inventory-empty"><h3>${ESC(title)}</h3>${detail ? `<p>${ESC(detail)}</p>` : ''}${isRetry ? '<button class="btn btn-sm" type="button" data-retry>Try again</button>' : ''}</div>`;
    }
    function Table(headers, rows, emptyTitle) 
    {
        return rows.length ? `<div class="table-wrap"><table class="inventory-detail-table"><thead><tr>${headers.map(header => `<th scope="col">${ESC(header)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>` : Empty(emptyTitle);
    }
    function Options(id, entries, label, extra = []) 
    {
        const SELECT = GetElement(id);
        const KEEP = SELECT.value;
        SELECT.replaceChildren(new Option(label, ''));
        [...extra, ...entries].forEach(([value, text]) => SELECT.add(new Option(text, String(value))));
        SELECT.value = [...SELECT.options].some(option => option.value === KEEP) ? KEEP : '';
    }
    function RenewalStatus(item) 
    {
        const NOW = new Date();
        const TODAY = Date.UTC(NOW.getFullYear(), NOW.getMonth(), NOW.getDate());
        const DAYS = Math.round((Date.parse(item.expiry_date + 'T00:00:00Z') - TODAY) / 86400000);
        if (DAYS < 0) 
        {
            return (
            {
                key: 'expired',
                tone: 'red',
                label: `Expired ${Math.abs(DAYS)}d ago`
            });
        }
        if (DAYS <= Number(item.alert_days_before)) 
        {
            return (
            {
                key: 'soon',
                tone: 'amber',
                label: DAYS ? `${DAYS}d left` : 'Expires today'
            });
        }
        return (
        {
            key: 'healthy',
            tone: 'green',
            label: `${DAYS}d left`
        });
    }
    function UpdateSummary() 
    {
        GetElement('inventoryServerCount').textContent = STATE.errors.servers ? '—' : STATE.servers.length;
        GetElement('inventoryGroupCount').textContent = STATE.errors.groups ? '—' : STATE.groups.length;
        GetElement('inventoryRenewalCount').textContent = STATE.errors.renewals ? '—' : STATE.renewals.length;
        const ACTIVE = STATE.servers.filter(server => Yes(server.is_active)).length;
        const DUE = STATE.renewals.filter(item => RenewalStatus(item).key !== 'healthy').length;
        GetElement('inventorySummary').innerHTML = `${STATE.errors.servers ? '<span>Server list unavailable</span>' : `<span class="inventory-status-dot"></span><strong>${ACTIVE}</strong> active <span class="inventory-summary-divider">/</span> ${STATE.servers.length - ACTIVE} inactive`}${STATE.errors.renewals ? '' : `<button class="inventory-due-button${DUE ? ' has-due' : ''}" type="button" data-show-due>${DUE} renewal${DUE === 1 ? '' : 's'} need attention</button>`}`;
        Options('serverEnvironment', [...new Set(STATE.servers.map(server => server.environment))].sort(COMPARE.compare).map(value => [value, value]), 'All environments');
        Options('serverGroupFilter', STATE.groups.map(group => [group.server_group_id, group.group_name]), 'All groups', [['ungrouped', 'Ungrouped']]);
    }
    function RenderServers(isResetPage = false) 
    {
        if (isResetPage) 
        {
            STATE.page = 1;
        }
        if (STATE.errors.servers) 
        {
            GetElement('inventoryServerRows').innerHTML = `<tr><td colspan="5">${Empty('Servers unavailable', STATE.errors.servers, true)}</td></tr>`;
            GetElement('serverResults').textContent = 'Unable to load servers';
            GetElement('serverPagination').hidden = true;
            return;
        }
        const QUERY = GetElement('serverSearch').value.trim().toLowerCase();
        const ENVIRONMENT = GetElement('serverEnvironment').value;
        const GROUP = GetElement('serverGroupFilter').value;
        const STATUS = GetElement('serverStatusFilter').value;
        const ROWS = STATE.servers.filter(server => 
        {
            if (ENVIRONMENT && server.environment !== ENVIRONMENT) 
            {
                return false;
            }
            if (GROUP === 'ungrouped' ? !!server.server_group_id : GROUP && String(server.server_group_id) !== GROUP) 
            {
                return false;
            }
            if (STATUS && Yes(server.is_active) !== (STATUS === 'active')) 
            {
                return false;
            }
            const TEXT = [server.server_name, server.ip_address, server.group_name, server.hosted_names, server.group_system_names, server.hosting_type, server.server_type, server.os_platform].join(' ').toLowerCase();
            return QUERY.split(/\s+/).every(word => TEXT.includes(word));
        }).sort((a, b) => COMPARE.compare(a.server_name, b.server_name) || COMPARE.compare(a.environment, b.environment));

        STATE.page = Math.min(STATE.page, Math.max(1, Math.ceil(ROWS.length / (window.Experience?.pageSize(15) || 15))));
        const START = (STATE.page - 1) * (window.Experience?.pageSize(15) || 15);
        GetElement('inventoryServerRows').innerHTML = ROWS.length ? ROWS.slice(START, START + (window.Experience?.pageSize(15) || 15)).map(server => 
        {
            const GROUP_INFO = STATE.groups.find(group => Number(group.server_group_id) === Number(server.server_group_id));
            const SHARED = Number(GROUP_INFO?.system_count || 0);
            const RESOURCES = [server.cpu_cores !== null ? `${GetNumber(server.cpu_cores)} cores` : '', server.memory_gb !== null ? `${GetNumber(server.memory_gb)} GB RAM` : '', server.storage_gb !== null ? `${GetNumber(server.storage_gb)} GB disk` : ''].filter(Boolean);
            return `<tr><td data-label="Server" class="inventory-server-identity">${Action(server.server_name, 'server', server.server_id)}<div class="inventory-address"><code>${ESC(server.ip_address)}</code>${Action('Copy', 'copy', server.ip_address, 'aria-label="Copy server address"')}</div>${!Yes(server.is_active) ? Badge('Inactive') : ''}</td><td data-label="Environment / group">${EnvBadge(server.environment)}<small>${ESC(server.group_name || 'Ungrouped')}</small></td><td data-label="Hosting / role">${ESC(server.hosting_type || 'Not specified')}<small>${ESC(server.server_type || 'Role not specified')}</small></td><td data-label="Resources">${RESOURCES.length ? RESOURCES.map(value => `<small>${ESC(value)}</small>`).join('') : '<span class="muted">Not recorded</span>'}</td><td data-label="Applications"><strong>${Number(server.hosted_count)}</strong> on server${SHARED ? `<small>${SHARED} shared by group</small>` : ''}</td></tr>`;
        }).join('') : `<tr><td colspan="5">${Empty(STATE.servers.length ? 'No matching servers' : 'No servers yet', STATE.servers.length ? 'Adjust the filters or search to see more servers.' : 'Add your first server to start the inventory.')}</td></tr>`;
        GetElement('serverResults').textContent = ROWS.length ? `Showing ${START + 1}–${Math.min(START + (window.Experience?.pageSize(15) || 15), ROWS.length)} of ${ROWS.length} servers` : '0 servers';
        GetElement('serverPagination').hidden = ROWS.length <= (window.Experience?.pageSize(15) || 15);
        GetElement('serverPageLabel').textContent = `Page ${STATE.page} of ${Math.max(1, Math.ceil(ROWS.length / (window.Experience?.pageSize(15) || 15)))}`;
        GetElement('serverPrevious').disabled = STATE.page <= 1;
        GetElement('serverNext').disabled = START + (window.Experience?.pageSize(15) || 15) >= ROWS.length;
    }
    function RenderGroups() 
    {
        if (STATE.errors.groups) 
        {
            GetElement('inventoryGroupCards').innerHTML = Empty('Groups unavailable', STATE.errors.groups, true);
            return;
        }
        const QUERY = GetElement('groupSearch').value.trim().toLowerCase();
        const GROUPS = STATE.groups.filter(group => [group.group_name, group.description].join(' ').toLowerCase().includes(QUERY));
        GetElement('inventoryGroupCards').innerHTML = GROUPS.length ? GROUPS.map(group => `<article class="inventory-group-card"><span class="inventory-group-icon" aria-hidden="true">${AT.icon('layers')}</span><h3>${ESC(group.group_name)}</h3><p>${ESC(group.description || 'No description added.')}</p><div class="inventory-group-totals"><span><strong>${Number(group.server_count)}</strong> servers</span><span><strong>${Number(group.system_count)}</strong> shared applications</span></div>${Action('Open group →', 'group', group.server_group_id)}</article>`).join('') : Empty(QUERY ? 'No matching groups' : 'No server groups yet', QUERY ? 'Try a different group name.' : 'Create a group when several servers share applications.');
    }
    function RenderRenewals() 
    {
        if (STATE.errors.renewals) 
        {
            GetElement('inventoryRenewalRows').innerHTML = `<tr><td colspan="5">${Empty('Renewals unavailable', STATE.errors.renewals, true)}</td></tr>`;
            return;
        }
        const QUERY = GetElement('renewalSearch').value.trim().toLowerCase();
        const TYPE = GetElement('renewalType').value;
        const STATUS = GetElement('renewalStatus').value;
        const ITEMS = STATE.renewals.filter(item => 
        {
            const KEY = RenewalStatus(item).key;
            return (!TYPE || item.item_type === TYPE) && (!STATUS || (STATUS === 'attention' ? KEY !== 'healthy' : STATUS === KEY)) && [item.item_name, item.server_name, item.hosted_system_name, item.notes].join(' ').toLowerCase().includes(QUERY);
        }).sort((a, b) => a.expiry_date.localeCompare(b.expiry_date));
        GetElement('renewalResults').textContent = `${ITEMS.length} renewal${ITEMS.length === 1 ? '' : 's'}`;
        GetElement('inventoryRenewalRows').innerHTML = ITEMS.length ? ITEMS.map(item => 
        {
            const STATUS = RenewalStatus(item);
            return `<tr><td><strong>${ESC(item.item_name)}</strong><small>${ESC(item.item_type)}</small>${item.notes ? `<small class="inventory-note">${ESC(item.notes)}</small>` : ''}</td><td>${item.server_id ? Action(item.server_name || 'View server', 'server', item.server_id) : '<span class="muted">Not linked to a server</span>'}${item.hosted_system_name ? `<small>${ESC(item.hosted_system_name)}</small>` : ''}</td><td class="inventory-nowrap">${ESC(item.expiry_date)}</td><td>${Badge(STATUS.label, STATUS.tone)}</td><td>${EditButton('expiry', item.expiry_id)}</td></tr>`;
        }).join('') : `<tr><td colspan="5">${Empty(STATE.renewals.length ? 'No matching renewals' : 'No renewals tracked', STATE.renewals.length ? 'Change the filters to see other items.' : 'Track certificates, licenses, and services that need renewal.')}</td></tr>`;
    }
    function ClearSecrets() 
    {
        SECRET_TIMERS.forEach(timer => clearTimeout(timer));
        SECRET_TIMERS.clear();
        document.querySelectorAll('[data-secret]').forEach(input => 
        {
            input.value = '';
            input.type = 'password';
            input.hidden = true;
        });
        document.querySelectorAll('[data-reveal]').forEach(button => 
        {
            button.textContent = 'Reveal';
        });
    }
    function SetMain(tab, isReset = true) 
    {
        ClearSecrets();
        STATE.epoch++;
        STATE.tab = tab;
        if (isReset || tab !== 'servers') 
        {
            STATE.server = null;
        }
        if (isReset || tab !== 'groups') 
        {
            STATE.group = null;
        }
        document.querySelectorAll('[data-inventory-tab]').forEach(button => 
        {
            const IS_SELECTED = button.dataset.inventoryTab === tab;
            button.setAttribute('aria-selected', String(IS_SELECTED));
            button.tabIndex = IS_SELECTED ? 0 : -1;
            GetElement(button.getAttribute('aria-controls')).hidden = !IS_SELECTED;
        });
        GetElement('inventoryServerList').hidden = !!STATE.server;
        GetElement('inventoryServerDetail').hidden = !STATE.server;
        GetElement('inventoryGroupList').hidden = !!STATE.group;
        GetElement('inventoryGroupDetail').hidden = !STATE.group;
        Message('');
    }
    function DetailHead(server) 
    {
        GetElement('inventoryDetailName').textContent = server.server_name;
        GetElement('inventoryDetailMeta').innerHTML = `${EnvBadge(server.environment)} ${Badge(Yes(server.is_active) ? 'Active' : 'Inactive', Yes(server.is_active) ? 'green' : 'gray')} <code>${ESC(server.ip_address)}</code>${server.group_name ? `<span>${ESC(server.group_name)}</span>` : ''}`;
    }
    async function OpenServer(id, isKeepTab = false, isFocus = true) 
    {
        const TAB = isKeepTab ? STATE.detailTab : 'overview';
        SetMain('servers', false);
        const EPOCH = STATE.epoch;
        try 
        {
            const SERVER = await Api('get_server', 
            {
                server_id: id
            });
            if (EPOCH !== STATE.epoch) 
            {
                return;
            }
            STATE.server = SERVER;
            STATE.group = null;
            STATE.protocols = null;
            STATE.credentials = null;
            GetElement('inventoryServerList').hidden = true;
            GetElement('inventoryServerDetail').hidden = false;
            DetailHead(SERVER);
            RenderOverview();
            RenderSystems();
            await SetDetailTab(TAB);
            if (isFocus) 
            {
                GetElement('inventoryDetailName').focus(
                {
                    preventScroll: true
                });
                GetElement('inventoryServerDetail').scrollIntoView(
                {
                    block: 'start',
                    behavior: 'smooth'
                });
            }
        }
        catch (error) 
        {
            Message(error.message, true);
        }
    }
    function RenderOverview() 
    {
        const SERVER = STATE.server;
        const FACTS = [['Environment', SERVER.environment], ['Status', Yes(SERVER.is_active) ? 'Active' : 'Inactive'], ['Hosting', SERVER.hosting_type], ['Server role', SERVER.server_type], ['Operating system', SERVER.os_platform]];
        const HARDWARE = [['CPU cores', GetNumber(SERVER.cpu_cores)], ['Memory', SERVER.memory_gb !== null ? `${GetNumber(SERVER.memory_gb)} GB` : '—'], ['Storage', SERVER.storage_gb !== null ? `${GetNumber(SERVER.storage_gb)} GB` : '—']];
        const RENEWALS = STATE.renewals.filter(item => Number(item.server_id) === Number(SERVER.server_id));
        GetElement('serverOverviewPanel').innerHTML = `<div class="inventory-overview-grid"><section><h3>Server information</h3><dl class="inventory-facts">${FACTS.map(([label, value]) => `<div><dt>${label}</dt><dd>${ESC(value || 'Not recorded')}</dd></div>`).join('')}<div><dt>IP address</dt><dd><code>${ESC(SERVER.ip_address)}</code> ${Action('Copy', 'copy', SERVER.ip_address)}</dd></div><div><dt>Access link</dt><dd>${Link(SERVER.server_url, 'Open server')}</dd></div><div><dt>Server group</dt><dd>${SERVER.server_group_id ? Action(SERVER.group_name || 'Open group', 'group', SERVER.server_group_id) : 'Ungrouped'}</dd></div></dl></section><section><h3>Hardware resources</h3><div class="inventory-resource-grid">${HARDWARE.map(([label, value]) => `<div><strong>${ESC(value)}</strong><span>${label}</span></div>`).join('')}</div><div class="inventory-context-note"><strong>Renewals</strong><p>${RENEWALS.length ? `${RENEWALS.length} linked item${RENEWALS.length === 1 ? '' : 's'}, ${RENEWALS.filter(item => RenewalStatus(item).key !== 'healthy').length} needing attention.` : 'No renewal items linked to this server.'}</p>${RENEWALS.length ? Action('View renewals →', 'server-renewals', SERVER.server_id) : ''}</div></section></div>${SERVER.notes ? `<section class="inventory-notes"><h3>Notes</h3><p>${ESC(SERVER.notes)}</p></section>` : ''}`;
    }
    function RenderSystems() 
    {
        const SERVER = STATE.server;
        GetElement('serverSystemsPanel').innerHTML = `<div class="inventory-section-head"><div><h3>Applications on this server</h3><p>${SERVER.hosted_systems.length} application${SERVER.hosted_systems.length === 1 ? '' : 's'}</p></div><button class="btn btn-primary btn-sm" type="button" data-new="hosted">${AT.icon('plus')} Add application</button></div>${SERVER.server_group_id ? `<div class="inventory-context-note inventory-inline-note"><span>Applications shared across ${ESC(SERVER.group_name)} are managed in its group.</span>${Action('Open group →', 'group', SERVER.server_group_id)}</div>` : ''}${Table(['Application', 'Version / port', 'Environments', 'Repository', 'Actions'], SERVER.hosted_systems.map(app => `<tr><td><strong>${ESC(app.system_name)}</strong>${app.description ? `<small class="inventory-note">${ESC(app.description)}</small>` : ''}</td><td>${ESC(app.version || '—')}<small>${app.port ? 'Port ' + ESC(app.port) : 'No port recorded'}</small></td><td>${Envs(app.current_environment)}</td><td>${Link(app.repository_url, 'Repository')}</td><td>${EditButton('hosted', app.hosted_system_id)}</td></tr>`), 'No applications recorded for this server')}`;
    }
    async function SetDetailTab(tab) 
    {
        ClearSecrets();
        STATE.detailTab = tab;
        document.querySelectorAll('[data-server-tab]').forEach(button => 
        {
            const IS_SELECTED = button.dataset.serverTab === tab;
            button.setAttribute('aria-selected', String(IS_SELECTED));
            button.tabIndex = IS_SELECTED ? 0 : -1;
            GetElement(button.getAttribute('aria-controls')).hidden = !IS_SELECTED;
        });
        if (!STATE.server || !['access', 'credentials'].includes(tab)) 
        {
            return;
        }
        const PANEL = GetElement(tab === 'access' ? 'serverAccessPanel' : 'serverCredentialsPanel');
        const ID = STATE.server.server_id;
        const EPOCH = STATE.epoch;
        PANEL.innerHTML = Empty('Loading ' + (tab === 'access' ? 'access methods…' : 'credentials…'));
        try 
        {
            const KEY = tab === 'access' ? 'protocols' : 'credentials';
            if (STATE[KEY] === null) 
            {
                const ROWS = await Api(tab === 'access' ? 'list_protocols' : 'list_credentials', 
                {
                    server_id: ID
                });
                if (EPOCH !== STATE.epoch || Number(STATE.server?.server_id) !== Number(ID)) 
                {
                    return;
                }
                STATE[KEY] = ROWS;
            }
            if (STATE.detailTab === tab) 
            {
                tab === 'access' ? RenderProtocols() : RenderCredentials();
            }
        }
        catch (error) 
        {
            if (EPOCH === STATE.epoch) 
            {
                PANEL.innerHTML = Empty('Unable to load this section', error.message, true);
            }
        }
    }
    function Connection(protocol) 
    {
        const IP = STATE.server.ip_address;
        const PORT = protocol.port;
        const ADDRESS = IP.includes(':') ? `[${IP}]` : IP;
        if (protocol.protocol === 'RDP') 
        {
            return `mstsc /v:${ADDRESS}${PORT ? ':' + PORT : ''}`;
        }
        if (protocol.protocol === 'SSH') 
        {
            return `ssh <user>@${IP}${PORT ? ' -p ' + PORT : ''}`;
        }
        if (protocol.protocol === 'FTP') 
        {
            return `ftp://${ADDRESS}${PORT ? ':' + PORT : ''}`;
        }
        if (protocol.protocol === 'WinRM') 
        {
            return `Enter-PSSession -ComputerName ${IP}${PORT ? ' -Port ' + PORT : ''}`;
        }
        return `${ADDRESS}${PORT ? ':' + PORT : ''}`;
    }
    function RenderProtocols() 
    {
        GetElement('serverAccessPanel').innerHTML = `<div class="inventory-section-head"><div><h3>Access methods</h3><p>Copy a connection command or download an RDP file.</p></div><button class="btn btn-primary btn-sm" type="button" data-new="protocol">${AT.icon('plus')} Add access method</button></div>${Table(['Protocol', 'Connection', 'Notes', 'Actions'], STATE.protocols.map(protocol => `<tr><td>${Badge(protocol.protocol, 'blue')}<small>${protocol.port ? 'Port ' + ESC(protocol.port) : 'Default port'}</small></td><td><code class="inventory-command">${ESC(Connection(protocol))}</code><div class="inventory-cell-actions">${Action('Copy command', 'copy', Connection(protocol))}${protocol.protocol === 'RDP' ? `<a href="${ESC(ROOT.dataset.rdpUrl)}?protocol_id=${Number(protocol.protocol_id)}">Download .rdp</a>` : ''}</div></td><td class="inventory-note">${ESC(protocol.notes || '—')}</td><td>${EditButton('protocol', protocol.protocol_id)}</td></tr>`), 'No access methods recorded')}`;
    }
    function RenderCredentials() 
    {
        ClearSecrets();
        const ROWS = STATE.credentials.map(credential => 
        {
            const PASSWORD = Yes(credential.can_reveal) ? `<div class="inventory-cell-actions">${Action('Reveal', 'reveal', credential.credential_id)}${Action('Copy password', 'copy-secret', credential.credential_id)}</div><input class="inventory-secret" type="password" readonly hidden data-secret="${Number(credential.credential_id)}" aria-label="Revealed password" autocomplete="off">` : Badge('Restricted');
            const MANAGE = Yes(credential.is_owner) ? Action('Manage', 'edit', credential.credential_id, 'data-kind="credential"') : `<span class="muted">${Yes(credential.can_reveal) ? 'Shared access' : 'Creator only'}</span>`;
            return `<tr><td><strong>${ESC(credential.label)}</strong><small>${ESC(String(credential.created_at).slice(0, 10))}</small></td><td><code>${ESC(credential.username)}</code> ${Action('Copy', 'copy', credential.username)}</td><td>${ESC(credential.created_by_name || 'Unknown user')}</td><td>${PASSWORD}</td><td>${MANAGE}</td></tr>`;
        });
        GetElement('serverCredentialsPanel').innerHTML = `<div class="inventory-section-head"><div><h3>Credentials</h3><p>Passwords are available to their creator and explicitly shared users.</p></div><button class="btn btn-primary btn-sm" type="button" data-new="credential">${AT.icon('plus')} Add credential</button></div>${Table(['Credential', 'Username', 'Created by', 'Password / access', 'Actions'], ROWS, 'No credentials stored for this server')}`;
    }
    async function OpenGroup(id, isFocus = true) 
    {
        SetMain('groups', false);
        const EPOCH = STATE.epoch;
        try 
        {
            const GROUP = await Api('get_server_group', 
            {
                server_group_id: id
            });
            if (EPOCH !== STATE.epoch) 
            {
                return;
            }
            STATE.group = GROUP;
            STATE.server = null;
            GetElement('inventoryGroupList').hidden = true;
            GetElement('inventoryGroupDetail').hidden = false;
            GetElement('inventoryGroupName').textContent = GROUP.group_name;
            GetElement('inventoryGroupDescription').textContent = GROUP.description || 'No description added.';
            GetElement('inventoryGroupMembers').innerHTML = `<div><strong>${GROUP.servers.length} member server${GROUP.servers.length === 1 ? '' : 's'}</strong><p>${[...new Set(GROUP.servers.map(server => server.environment))].map(value => EnvBadge(value)).join(' ') || '<span class="muted">No servers assigned</span>'}</p></div><div class="inventory-cell-actions">${Action('View member servers →', 'members', GROUP.server_group_id)}<button type="button" class="btn btn-sm" data-new="server" data-group-id="${Number(GROUP.server_group_id)}">${AT.icon('plus')} Add member</button></div>`;
            GetElement('inventoryGroupSystems').innerHTML = `<div class="inventory-section-head"><div><h3>Shared applications</h3><p>Applications that run across this group.</p></div><button type="button" class="btn btn-primary btn-sm" data-new="groupSystem">${AT.icon('plus')} Add application</button></div>${Table(['Application', 'Environments', 'Repository', 'Actions'], GROUP.systems.map(app => `<tr><td><strong>${ESC(app.system_name)}</strong>${app.description ? `<small class="inventory-note">${ESC(app.description)}</small>` : ''}</td><td>${Envs(app.current_environment)}</td><td>${Link(app.repository_url, 'Repository')}</td><td>${EditButton('groupSystem', app.group_system_id)}</td></tr>`), 'No shared applications recorded')}`;
            if (isFocus) 
            {
                GetElement('inventoryGroupName').focus(
                {
                    preventScroll: true
                });
                GetElement('inventoryGroupDetail').scrollIntoView(
                {
                    block: 'start',
                    behavior: 'smooth'
                });
            }
        }
        catch (error) 
        {
            Message(error.message, true);
        }
    }
    async function Refresh() 
    {
        if (isRefreshBusy) 
        {
            return;
        }
        isRefreshBusy = true;
        GetElement('inventoryRefresh').disabled = true;
        const EPOCH = STATE.epoch;
        try 
        {
            const KEYS = ['servers', 'groups', 'renewals'];
            const RESULTS = await Promise.allSettled([Api('list_servers'), Api('list_server_groups'), Api('list_expiry_items')]);
            RESULTS.forEach((result, index) => 
            {
                const KEY = KEYS[index];
                if (result.status === 'fulfilled' && Array.isArray(result.value)) 
                {
                    STATE[KEY] = result.value;
                    delete STATE.errors[KEY];
                }
                else 
                {
                    STATE.errors[KEY] = result.reason?.message || 'The server returned an invalid response.';
                }
            });
            UpdateSummary();
            RenderServers();
            RenderGroups();
            RenderRenewals();
            if (EPOCH === STATE.epoch) 
            {
                if (STATE.server) 
                {
                    STATE.servers.some(server => Number(server.server_id) === Number(STATE.server.server_id)) ? await OpenServer(STATE.server.server_id, true, false) : SetMain('servers');
                }
                else if (STATE.group) 
                {
                    STATE.groups.some(group => Number(group.server_group_id) === Number(STATE.group.server_group_id)) ? await OpenGroup(STATE.group.server_group_id, false) : SetMain('groups');
                }
            }
        }
        finally 
        {
            isRefreshBusy = false;
            GetElement('inventoryRefresh').disabled = false;
        }
    }

    // Field definitions are shared by create/edit, rather than duplicating a modal per record.
    function Definitions(kind, record) 
    {
        const Field = (name, label, config = 
        {

        }) => (
        {
            name,
            label,
            ...config
        });
        const Name = label => Field('system_name', label, 
        {
            required: true,
            max: 150
        });
        const APPLICATION = [Name('Application name'), Field('current_environment', 'Current environments', 
        {
            type: 'environments'
        }), Field('repository_url', 'Repository URL', 
        {
            type: 'url',
            max: 500,
            placeholder: 'https://…'
        }), Field('description', 'Description', 
        {
            type: 'textarea',
            wide: true
        })];
        const CONFIGS = 
        {
            group: 
            {
                label: 'server group',
                id: 'server_group_id',
                save: 'save_server_group',
                remove: 'delete_server_group',
                fields: [Field('group_name', 'Group name', 
                {
                    required: true,
                    max: 150
                }), Field('description', 'Description', 
                {
                    type: 'textarea',
                    wide: true
                })]
            },
            server: 
            {
                label: 'server',
                id: 'server_id',
                save: 'save_server',
                remove: 'delete_server',
                fields: [Field('server_name', 'Server name', 
                {
                    required: true,
                    max: 150
                }), Field('environment', 'Environment', 
                {
                    required: true,
                    options: ENVIRONMENTS,
                    default: 'Dev'
                }), Field('ip_address', 'IP address', 
                {
                    required: true,
                    max: 45,
                    placeholder: '10.0.1.15 or IPv6 address'
                }), Field('server_group_id', 'Server group', 
                {
                    options: [['', 'Ungrouped'], ...STATE.groups.map(group => [group.server_group_id, group.group_name])]
                }), Field('hosting_type', 'Hosting', 
                {
                    options: [['', 'Not specified'], 'AWS', 'On-Premise']
                }), Field('server_type', 'Server role', 
                {
                    options: [['', 'Not specified'], 'App Server', 'DB Server', 'App & DB Server']
                }), Field('server_url', 'Access URL', 
                {
                    type: 'url',
                    max: 500,
                    placeholder: 'https://server.example.com',
                    wide: true
                }), Field('os_platform', 'Operating system', 
                {
                    max: 100,
                    hardware: true
                }), Field('cpu_cores', 'CPU cores', 
                {
                    type: 'number',
                    min: 0,
                    step: 1,
                    hardware: true
                }), Field('memory_gb', 'Memory (GB)', 
                {
                    type: 'number',
                    min: 0,
                    step: 'any',
                    hardware: true
                }), Field('storage_gb', 'Storage (GB)', 
                {
                    type: 'number',
                    min: 0,
                    step: 'any',
                    hardware: true
                }), Field('notes', 'Notes', 
                {
                    type: 'textarea',
                    wide: true
                }), Field('is_active', 'Active server', 
                {
                    type: 'checkbox',
                    default: true,
                    wide: true
                })]
            },
            hosted: 
            {
                label: 'server application',
                id: 'hosted_system_id',
                save: 'save_hosted_system',
                remove: 'delete_hosted_system',
                fields: [APPLICATION[0], Field('version', 'Version', 
                {
                    max: 50
                }), Field('port', 'Port', 
                {
                    max: 20
                }), ...APPLICATION.slice(1)]
            },
            groupSystem: 
            {
                label: 'shared application',
                id: 'group_system_id',
                save: 'save_group_system',
                remove: 'delete_group_system',
                fields: APPLICATION
            },
            protocol: 
            {
                label: 'access method',
                id: 'protocol_id',
                save: 'save_protocol',
                remove: 'delete_protocol',
                fields: [Field('protocol', 'Protocol', 
                {
                    required: true,
                    options: ['RDP', 'SSH', 'FTP', 'WinRM', 'Other'],
                    default: 'RDP'
                }), Field('port', 'Port', 
                {
                    type: 'number',
                    min: 1,
                    maxValue: 65535,
                    step: 1,
                    placeholder: 'Default port'
                }), Field('notes', 'Notes', 
                {
                    type: 'textarea',
                    wide: true
                })]
            },
            credential: 
            {
                label: 'credential',
                id: 'credential_id',
                save: 'save_credential',
                remove: 'delete_credential',
                fields: [Field('label', 'Label', 
                {
                    required: true,
                    max: 150,
                    placeholder: 'e.g. Local administrator'
                }), Field('username', 'Username', 
                {
                    required: true,
                    max: 150
                }), Field('password', record.credential_id ? 'New password' : 'Password', 
                {
                    type: 'password',
                    required: !record.credential_id,
                    wide: true,
                    help: record.credential_id ? 'Leave blank to keep the current password.' : 'Stored encrypted. Access is limited to you and people you share it with.'
                })]
            },
            expiry: 
            {
                label: 'renewal',
                id: 'expiry_id',
                save: 'save_expiry_item',
                remove: 'delete_expiry_item',
                fields: [Field('item_name', 'Renewal name', 
                {
                    required: true,
                    max: 200
                }), Field('item_type', 'Type', 
                {
                    required: true,
                    options: ['SSL Certificate', 'License', 'Other Service']
                }), Field('server_id', 'Server', 
                {
                    options: [['', 'Not linked'], ...STATE.servers.map(server => [server.server_id, server.server_name + ' · ' + server.environment])]
                }), Field('hosted_system_id', 'Server application', 
                {
                    options: [['', 'Choose a server first']]
                }), Field('issued_date', 'Issued date', 
                {
                    type: 'date'
                }), Field('expiry_date', 'Expiry date', 
                {
                    type: 'date',
                    required: true
                }), Field('alert_days_before', 'Alert days before expiry', 
                {
                    type: 'number',
                    min: 0,
                    step: 1,
                    default: 30
                }), Field('notes', 'Notes', 
                {
                    type: 'textarea',
                    wide: true
                })]
            }
        };
        return CONFIGS[kind];
    }
    function FieldMarkup(field, record) 
    {
        const VALUE = record[field.name] ?? field.default ?? '';
        const ID = 'inventoryField_' + field.name;
        const ATTRIBUTES = `id="${ID}" name="${field.name}"${field.required ? ' required' : ''}${field.max ? ` maxlength="${field.max}"` : ''}${field.min !== undefined ? ` min="${field.min}"` : ''}${field.maxValue ? ` max="${field.maxValue}"` : ''}${field.step ? ` step="${field.step}"` : ''}${field.placeholder ? ` placeholder="${ESC(field.placeholder)}"` : ''}`;
        if (field.type === 'checkbox') 
        {
            return `<div class="inventory-form-field inventory-form-wide"><label class="inventory-checkbox"><input type="checkbox" ${ATTRIBUTES} ${Yes(VALUE) ? 'checked' : ''}>${field.label}</label></div>`;
        }
        if (field.type === 'environments') 
        {
            const SELECTED = String(VALUE).split(',').map(value => value.trim());
            const VALUES = [...new Set([...APPLICATION_ENVIRONMENTS, ...SELECTED.filter(Boolean)])];
            return `<fieldset class="inventory-environment-field inventory-form-wide"><legend>${field.label}</legend><div class="env-checkbox-group">${VALUES.map(env => `<label><input type="checkbox" name="${field.name}" value="${ESC(env)}" ${SELECTED.includes(env) ? 'checked' : ''}>${ESC(env)}</label>`).join('')}</div></fieldset>`;
        }
        let input;
        if (field.options) 
        {
            const CHOICES = field.options.map(option => Array.isArray(option) ? option : [option, option]);
            if (VALUE !== '' && !CHOICES.some(([option]) => String(option) === String(VALUE))) 
            {
                CHOICES.push([VALUE, VALUE]);
            }
            input = `<select ${ATTRIBUTES}>${CHOICES.map(([option, text]) => `<option value="${ESC(String(option))}" ${String(option) === String(VALUE) ? 'selected' : ''}>${ESC(String(text))}</option>`).join('')}</select>`;
        }
        else if (field.type === 'textarea') 
        {
            input = `<textarea ${ATTRIBUTES} rows="3">${ESC(VALUE)}</textarea>`;
        }
        else 
        {
            input = `<input ${ATTRIBUTES} type="${field.type || 'text'}" value="${ESC(field.type === 'password' ? '' : String(VALUE))}" ${field.type === 'password' ? 'autocomplete="new-password"' : ''}>`;
        }
        return `<div class="inventory-form-field${field.wide ? ' inventory-form-wide' : ''}"><label for="${ID}">${field.label}${field.required ? ' <span aria-hidden="true">*</span>' : ''}</label>${input}${field.help ? `<small>${ESC(field.help)}</small>` : ''}</div>`;
    }
    function FindRecord(kind, id) 
    {
        const RECORDS = 
        {
            server: STATE.servers,
            group: STATE.groups,
            hosted: STATE.server?.hosted_systems || [],
            groupSystem: STATE.group?.systems || [],
            protocol: STATE.protocols || [],
            credential: STATE.credentials || [],
            expiry: STATE.renewals
        };
        const DEFINITION = Definitions(kind, 
        {

        });
        return RECORDS[kind]?.find(record => Number(record[DEFINITION.id]) === Number(id));
    }
    async function OpenEditor(kind, record = 
    {

    }, preset = 
    {

    }) 
    {
        if (isEditorBusy) 
        {
            return;
        }
        ClearSecrets();
        if (kind === 'credential' && record.credential_id && !Yes(record.is_owner)) 
        {
            Message('Only the creator can edit this credential.', true);
            return;
        }
        const DEFINITION = Definitions(kind, record);
        editorModel = 
        {
            kind,
            record,
            definition: DEFINITION,
            context: 
            {
                ...preset
            }
        };
        if (['hosted', 'protocol', 'credential'].includes(kind)) 
        {
            editorModel.context.server_id = record.server_id || STATE.server?.server_id;
        }
        if (kind === 'groupSystem') 
        {
            editorModel.context.server_group_id = record.server_group_id || STATE.group?.server_group_id;
        }
        GetElement('inventoryEditorTitle').textContent = `${record[DEFINITION.id] ? 'Edit' : 'Add'} ${DEFINITION.label}`;
        GetElement('inventoryEditorContext').textContent = ['hosted', 'protocol', 'credential'].includes(kind) ? STATE.server?.server_name || 'Server Inventory' : kind === 'groupSystem' ? STATE.group?.group_name || 'Server group' : 'Server Inventory';
        const VALUES = 
        {
            ...preset,
            ...record
        };
        const MAIN_FIELDS = DEFINITION.fields.filter(field => !field.hardware);
        const HARDWARE = DEFINITION.fields.filter(field => field.hardware);
        GetElement('inventoryEditorFields').innerHTML = `<div class="inventory-form-grid">${MAIN_FIELDS.map(field => FieldMarkup(field, VALUES)).join('')}</div>${HARDWARE.length ? `<details class="inventory-optional-fields" ${HARDWARE.some(field => VALUES[field.name] !== null && VALUES[field.name] !== undefined && VALUES[field.name] !== '') ? 'open' : ''}><summary>Operating system &amp; hardware <span>Optional</span></summary><div class="inventory-form-grid">${HARDWARE.map(field => FieldMarkup(field, VALUES)).join('')}</div></details>` : ''}`;
        GetElement('inventoryEditorMessage').textContent = '';
        GetElement('inventoryEditorMessage').classList.remove('is-error');
        GetElement('inventoryEditorDelete').hidden = !record[DEFINITION.id];
        GetElement('inventoryEditorShare').hidden = kind !== 'credential' || !record.credential_id;
        GetElement('inventoryEditorSave').textContent = record[DEFINITION.id] ? 'Save changes' : 'Create ' + DEFINITION.label;
        EDITOR.showModal();
        if (kind === 'expiry') 
        {
            await ExpiryApplications(VALUES.hosted_system_id);
        }
    }
    async function ExpiryApplications(selected = '') 
    {
        const SERVER_SELECT = GetElement('inventoryField_server_id');
        const SELECT = GetElement('inventoryField_hosted_system_id');
        if (!SERVER_SELECT || !SELECT) 
        {
            return;
        }
        const ID = SERVER_SELECT.value;
        SELECT.replaceChildren(new Option(ID ? 'Loading applications…' : 'Choose a server first', ''));
        SELECT.disabled = true;
        if (!ID) 
        {
            return;
        }
        try 
        {
            const SERVER = Number(STATE.server?.server_id) === Number(ID) ? STATE.server : await Api('get_server', 
            {
                server_id: ID
            });
            if (!EDITOR.open || SERVER_SELECT !== GetElement('inventoryField_server_id') || SERVER_SELECT.value !== ID) 
            {
                return;
            }
            SELECT.replaceChildren(new Option('Not linked to an application', ''));
            SERVER.hosted_systems.forEach(app => SELECT.add(new Option(app.system_name, String(app.hosted_system_id))));
            SELECT.value = selected ? String(selected) : '';
            SELECT.disabled = isEditorBusy;
        }
        catch (error) 
        {
            if (EDITOR.open && SERVER_SELECT === GetElement('inventoryField_server_id') && SERVER_SELECT.value === ID) 
            {
                EditorError(error.message);
            }
        }
    }
    function EditorError(text) 
    {
        GetElement('inventoryEditorMessage').textContent = text;
        GetElement('inventoryEditorMessage').classList.add('is-error');
    }
    function SetEditorBusy(busy) 
    {
        isEditorBusy = busy;
        GetElement('inventoryEditorFields').disabled = busy;
        ['inventoryEditorSave', 'inventoryEditorDelete', 'inventoryEditorShare'].forEach(id => GetElement(id).disabled = busy);
    }
    async function SaveEditor(event) 
    {
        event.preventDefault();
        if (!editorModel || isEditorBusy) 
        {
            return;
        }
        const 
        {
            definition: DEFINITION,
            record: RECORD,
            context: CONTEXT,
            kind: KIND
        }
        = editorModel;
        const DATA = new FormData(FORM);
        const PAYLOAD = 
        {
            ...CONTEXT,
            [DEFINITION.id]: RECORD[DEFINITION.id] || null
        };
        DEFINITION.fields.forEach(field => 
        {
            PAYLOAD[field.name] = field.type === 'environments' ? DATA.getAll(field.name) : field.type === 'checkbox' ? FORM.elements.namedItem(field.name).checked : field.type === 'password' ? String(DATA.get(field.name) ?? '') : String(DATA.get(field.name) ?? '').trim();
        });
        if (KIND === 'server') 
        {
            PAYLOAD.server_group_id = PAYLOAD.server_group_id || null;
        }
        if (KIND === 'expiry') 
        {
            if (PAYLOAD.server_id && GetElement('inventoryField_hosted_system_id').disabled) 
            {
                EditorError('Wait for the server applications to load before saving.');
                return;
            }
            PAYLOAD.server_id = PAYLOAD.server_id || null;
            PAYLOAD.hosted_system_id = PAYLOAD.hosted_system_id || null;
            if (PAYLOAD.issued_date && PAYLOAD.issued_date > PAYLOAD.expiry_date) 
            {
                EditorError('The expiry date must be on or after the issued date.');
                return;
            }
        }
        SetEditorBusy(true);
        GetElement('inventoryEditorMessage').textContent = 'Saving…';
        GetElement('inventoryEditorMessage').classList.remove('is-error');
        try 
        {
            await Api(DEFINITION.save, 
            {

            }, PAYLOAD);
            EDITOR.close();
            FORM.reset();
            AT.toast('Saved.');
            await Refresh();
        }
        catch (error) 
        {
            EditorError(error.message);
        }
        finally 
        {
            SetEditorBusy(false);
        }
    }
    async function DeleteEditor() 
    {
        if (!editorModel || isEditorBusy) 
        {
            return;
        }
        const 
        {
            definition: DEFINITION,
            record: RECORD,
            kind: KIND
        }
        = editorModel;
        const EXPLANATIONS = 
        {
            server: 'Its application, access, and credential records will also be removed. Renewal records are kept.',
            group: 'Servers will be kept without this group. Shared application records will be removed.'
        };
        if (!(await AT.confirmAction(`Delete this ${DEFINITION.label}? ${EXPLANATIONS[KIND] || 'This cannot be undone.'}`))) 
        {
            return;
        }
        SetEditorBusy(true);
        GetElement('inventoryEditorMessage').textContent = 'Deleting…';
        try 
        {
            await Api(DEFINITION.remove, 
            {

            }, 
            {
                [DEFINITION.id]: RECORD[DEFINITION.id]
            });
            EDITOR.close();
            FORM.reset();
            AT.toast('Deleted.');
            await Refresh();
        }
        catch (error) 
        {
            EditorError(error.message);
        }
        finally 
        {
            SetEditorBusy(false);
        }
    }
    async function RevealCredential(id) 
    {
        const INPUT = document.querySelector(`[data-secret="${Number(id)}"]`);
        const BUTTON = document.querySelector(`[data-reveal="${Number(id)}"]`);
        if (!INPUT || !BUTTON) 
        {
            return;
        }
        if (!INPUT.hidden) 
        {
            HideCredential(id);
            return;
        }
        const EPOCH = STATE.epoch;
        BUTTON.disabled = true;
        try 
        {
            const RESULT = await Api('reveal_credential', 
            {
                credential_id: id
            });
            if (EPOCH !== STATE.epoch || STATE.detailTab !== 'credentials' || !INPUT.isConnected) 
            {
                return;
            }
            INPUT.value = RESULT.password;
            INPUT.type = 'text';
            INPUT.hidden = false;
            BUTTON.textContent = 'Hide';
            SECRET_TIMERS.set(Number(id), setTimeout(() => HideCredential(id), 30000));
        }
        catch (error) 
        {
            Message(error.message, true);
        }
        finally 
        {
            BUTTON.disabled = false;
        }
    }
    function HideCredential(id) 
    {
        clearTimeout(SECRET_TIMERS.get(Number(id)));
        SECRET_TIMERS.delete(Number(id));
        const INPUT = document.querySelector(`[data-secret="${Number(id)}"]`);
        const BUTTON = document.querySelector(`[data-reveal="${Number(id)}"]`);
        if (INPUT) 
        {
            INPUT.value = '';
            INPUT.type = 'password';
            INPUT.hidden = true;
        }
        if (BUTTON) 
        {
            BUTTON.textContent = 'Reveal';
        }
    }
    async function CopySecret(id, button) 
    {
        button.disabled = true;
        const EPOCH = STATE.epoch;
        try 
        {
            const RESULT = await Api('reveal_credential', 
            {
                credential_id: id
            });
            if (EPOCH !== STATE.epoch || STATE.detailTab !== 'credentials') 
            {
                return;
            }
            try 
            {
                await navigator.clipboard.writeText(RESULT.password);
            }
            catch (_) 
            {
                const INPUT = document.createElement('textarea');
                INPUT.value = RESULT.password;
                INPUT.className = 'clipboard-fallback';
                document.body.append(INPUT);
                INPUT.select();
                let copied;
                try 
                {
                    copied = document.execCommand('copy');
                }
                finally 
                {
                    INPUT.value = '';
                    INPUT.remove();
                }
                if (!copied) 
                {
                    throw new Error('Could not copy the password. Use Reveal to copy it manually.');
                }
            }
            AT.toast('Password copied.');
        }
        catch (error) 
        {
            Message(error.message, true);
        }
        finally 
        {
            button.disabled = false;
        }
    }
    async function LoadShares() 
    {
        const ID = sharedCredentialId;
        const [USERS, SHARES] = await Promise.all([Api('list_shareable_users'), Api('list_credential_shares', 
        {
            credential_id: ID
        })]);
        if (ID !== sharedCredentialId || !SHARING.open) 
        {
            return;
        }
        const SHARED = new Set(SHARES.map(share => Number(share.shared_with_user_id)));
        GetElement('inventoryShareUser').replaceChildren(new Option('Choose a user', ''));
        USERS.filter(user => !SHARED.has(Number(user.user_id))).forEach(user => GetElement('inventoryShareUser').add(new Option(`${user.first_name} ${user.last_name} (${user.employee_id})`, user.user_id)));
        GetElement('inventoryShares').innerHTML = SHARES.length ? SHARES.map(share => `<div class="inventory-share-row"><div><strong>${ESC(share.first_name + ' ' + share.last_name)}</strong><small>${ESC(share.employee_id)}</small></div>${Action('Revoke', 'revoke', share.shared_with_user_id)}</div>`).join('') : '<p class="muted text-sm">This credential has not been shared.</p>';
    }
    async function OpenShares() 
    {
        if (!editorModel || editorModel.kind !== 'credential' || isEditorBusy) 
        {
            return;
        }
        sharedCredentialId = editorModel.record.credential_id;
        GetElement('inventoryShareMessage').textContent = '';
        GetElement('inventoryShares').textContent = 'Loading sharing…';
        SHARING.showModal();
        try 
        {
            await LoadShares();
        }
        catch (error) 
        {
            GetElement('inventoryShareMessage').textContent = error.message;
        }
    }
    async function ChangeShare(userId, isRevoke = false) 
    {
        if (isShareBusy || !sharedCredentialId || !userId) 
        {
            return;
        }
        isShareBusy = true;
        GetElement('inventoryShareSave').disabled = true;
        try 
        {
            await Api(isRevoke ? 'unshare_credential' : 'share_credential', 
            {

            }, 
            {
                credential_id: sharedCredentialId,
                user_id: userId
            });
            await LoadShares();
            GetElement('inventoryShareMessage').textContent = isRevoke ? 'Access revoked.' : 'Credential shared.';
        }
        catch (error) 
        {
            GetElement('inventoryShareMessage').textContent = error.message;
        }
        finally 
        {
            isShareBusy = false;
            GetElement('inventoryShareSave').disabled = false;
        }
    }

    // Delegate record actions using IDs; contact and description text never becomes JavaScript.
    ROOT.addEventListener('click', event => 
    {
        const BUTTON = event.target.closest('button');
        if (!BUTTON) 
        {
            return;
        }
        const DATA = BUTTON.dataset;
        if ('inventoryTab' in DATA) 
        {
            SetMain(DATA.inventoryTab);
        }
        else if ('serverTab' in DATA) 
        {
            SetDetailTab(DATA.serverTab);
        }
        else if ('server' in DATA) 
        {
            OpenServer(DATA.server);
        }
        else if ('group' in DATA) 
        {
            OpenGroup(DATA.group);
        }
        else if ('new' in DATA) 
        {
            OpenEditor(DATA.new, 
            {

            }, DATA.groupId ? 
            {
                server_group_id: DATA.groupId
            }
            : 
            {

            });
        }
        else if ('edit' in DATA) 
        {
            const RECORD = FindRecord(DATA.kind, DATA.edit);
            if (RECORD) 
            {
                OpenEditor(DATA.kind, RECORD);
            }
        }
        else if ('copy' in DATA) 
        {
            AT.copyText(DATA.copy);
        }
        else if ('retry' in DATA) 
        {
            Refresh();
        }
        else if ('reveal' in DATA) 
        {
            RevealCredential(DATA.reveal);
        }
        else if ('copySecret' in DATA) 
        {
            CopySecret(DATA.copySecret, BUTTON);
        }
        else if ('members' in DATA) 
        {
            SetMain('servers');
            GetElement('serverSearch').value = '';
            GetElement('serverEnvironment').value = '';
            GetElement('serverStatusFilter').value = '';
            GetElement('serverGroupFilter').value = DATA.members;
            RenderServers(true);
        }
        else if ('showDue' in DATA) 
        {
            SetMain('renewals');
            GetElement('renewalStatus').value = 'attention';
            GetElement('renewalSearch').value = '';
            GetElement('renewalType').value = '';
            RenderRenewals();
        }
        else if ('serverRenewals' in DATA) 
        {
            const NAME = STATE.server?.server_name || '';
            SetMain('renewals');
            GetElement('renewalStatus').value = '';
            GetElement('renewalType').value = '';
            GetElement('renewalSearch').value = NAME;
            RenderRenewals();
        }
    });
    ROOT.querySelectorAll('[role="tablist"]').forEach(tablist => tablist.addEventListener('keydown', event => 
    {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) 
        {
            return;
        }
        const TABS = [...tablist.querySelectorAll('[role="tab"]')];
        const INDEX = TABS.indexOf(document.activeElement);
        if (INDEX < 0) 
        {
            return;
        }
        const NEXT = event.key === 'Home' ? 0 : event.key === 'End' ? TABS.length - 1 : (INDEX + (event.key === 'ArrowRight' ? 1 : -1) + TABS.length) % TABS.length;
        event.preventDefault();
        TABS[NEXT].click();
        TABS[NEXT].focus();
    }));
    GetElement('inventoryRefresh').addEventListener('click', Refresh);
    GetElement('serverBack').addEventListener('click', () => SetMain('servers'));
    GetElement('groupBack').addEventListener('click', () => SetMain('groups'));
    GetElement('serverEdit').addEventListener('click', () => 
    {
        if (STATE.server) 
        {
            OpenEditor('server', STATE.server);
        }
    });
    GetElement('groupEdit').addEventListener('click', () => 
    {
        if (STATE.group) 
        {
            OpenEditor('group', STATE.group);
        }
    });
    GetElement('serverSearch').addEventListener('input', AT.debounce(() => RenderServers(true), 150));
    ['serverEnvironment', 'serverGroupFilter', 'serverStatusFilter'].forEach(id => GetElement(id).addEventListener('change', () => RenderServers(true)));
    GetElement('serverClearFilters').addEventListener('click', () => 
    {
        ['serverSearch', 'serverEnvironment', 'serverGroupFilter', 'serverStatusFilter'].forEach(id => GetElement(id).value = '');
        RenderServers(true);
    });
    GetElement('serverPrevious').addEventListener('click', () => 
    {
        STATE.page--;
        RenderServers();
    });
    GetElement('serverNext').addEventListener('click', () => 
    {
        STATE.page++;
        RenderServers();
    });
    GetElement('groupSearch').addEventListener('input', () => RenderGroups());
    GetElement('renewalSearch').addEventListener('input', AT.debounce(RenderRenewals, 150));
    ['renewalType', 'renewalStatus'].forEach(id => GetElement(id).addEventListener('change', RenderRenewals));
    GetElement('renewalClearFilters').addEventListener('click', () => 
    {
        ['renewalSearch', 'renewalType', 'renewalStatus'].forEach(id => GetElement(id).value = '');
        RenderRenewals();
    });
    FORM.addEventListener('submit', SaveEditor);
    FORM.addEventListener('change', event => 
    {
        if (editorModel?.kind === 'expiry' && event.target.name === 'server_id') 
        {
            ExpiryApplications();
        }
    });
    EDITOR.querySelectorAll('[data-close-editor]').forEach(button => button.addEventListener('click', () => 
    {
        if (!isEditorBusy) 
        {
            EDITOR.close();
        }
    }));
    EDITOR.addEventListener('cancel', event => 
    {
        if (isEditorBusy) 
        {
            event.preventDefault();
        }
    });
    EDITOR.addEventListener('close', () => 
    {
        if (!EDITOR.open) 
        {
            editorModel = null;
            FORM.reset();
            const PASSWORD = GetElement('inventoryField_password');
            if (PASSWORD) 
            {
                PASSWORD.value = '';
            }
        }
    });
    GetElement('inventoryEditorDelete').addEventListener('click', DeleteEditor);
    GetElement('inventoryEditorShare').addEventListener('click', OpenShares);
    SHARING.querySelectorAll('[data-close-share]').forEach(button => button.addEventListener('click', () => 
    {
        if (!isShareBusy) 
        {
            SHARING.close();
        }
    }));
    SHARING.addEventListener('cancel', event => 
    {
        if (isShareBusy) 
        {
            event.preventDefault();
        }
    });
    SHARING.addEventListener('close', () => 
    {
        if (!SHARING.open) 
        {
            sharedCredentialId = null;
        }
    });
    GetElement('inventoryShareForm').addEventListener('submit', event => 
    {
        event.preventDefault();
        ChangeShare(GetElement('inventoryShareUser').value);
    });
    GetElement('inventoryShares').addEventListener('click', event => 
    {
        const BUTTON = event.target.closest('[data-revoke]');
        if (BUTTON) 
        {
            ChangeShare(BUTTON.dataset.revoke, true);
        }
    });
    document.addEventListener('visibilitychange', () => 
    {
        if (document.hidden) 
        {
            ClearSecrets();
        }
    });
    window.addEventListener('pagehide', ClearSecrets);
    Refresh().then(async () => 
    {
        const PARAMS = new URLSearchParams(location.search);
        const ID = Number(PARAMS.get('renewal_id'));
        if (PARAMS.has('server_id')) 
        {
            await OpenServer(Number(PARAMS.get('server_id')));
        }
        if (PARAMS.get('tab') === 'renewals') 
        {
            SetMain('renewals');
            if (PARAMS.get('status') === 'attention') 
            {
                GetElement('renewalStatus').value = 'attention';
            }
            RenderRenewals();
        }
        if (ID) 
        {
            const RENEWAL = STATE.renewals.find(item => Number(item.expiry_id) === ID);
            SetMain('renewals');
            if (RENEWAL) 
            {
                GetElement('renewalSearch').value = RENEWAL.item_name;
                RenderRenewals();
                Message('Showing renewal #' + ID + '. Clear the search to see all renewals.');
            }
            else 
            {
                Message('This renewal is no longer available.', true);
            }
        }
    });
    document.addEventListener('experience:page-size', e => { e.detail.handled = true; RenderServers(); });
})();