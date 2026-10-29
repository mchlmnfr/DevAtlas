<?php
/**
 * Filename: index.php
 * Purpose: Render the Servers workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="inventory-workspace"
    id="serverInventory"
    data-api="<?= H(APP_BASE_URL . '/modules/servers/api.php') ?>"
    data-rdp-url="<?= H(APP_BASE_URL . '/modules/servers/rdp_download.php') ?>"
>
    <div class="inventory-status-bar">
        <div
            id="inventorySummary"
            role="status"
            aria-live="polite"
        >Loading inventory…</div><button
            type="button"
            class="btn btn-sm"
            id="inventoryRefresh"
        ><?= UiIcon('refresh-cw') ?> Refresh</button>
    </div>
    <div
        class="inventory-tabs"
        role="tablist"
        aria-label="Inventory views"
    >
        <button
            id="inventoryServersTab"
            type="button"
            role="tab"
            aria-controls="inventoryServers"
            aria-selected="true"
            data-inventory-tab="servers"
        >Servers <span id="inventoryServerCount">—</span></button>
        <button
            id="inventoryGroupsTab"
            type="button"
            role="tab"
            aria-controls="inventoryGroups"
            aria-selected="false"
            tabindex="-1"
            data-inventory-tab="groups"
        >Groups <span id="inventoryGroupCount">—</span></button>
        <button
            id="inventoryRenewalsTab"
            type="button"
            role="tab"
            aria-controls="inventoryRenewals"
            aria-selected="false"
            tabindex="-1"
            data-inventory-tab="renewals"
        >Renewals <span id="inventoryRenewalCount">—</span></button>
    </div>

    <section
        id="inventoryServers"
        role="tabpanel"
        aria-labelledby="inventoryServersTab"
    >
        <div class="card inventory-panel" id="inventoryServerList">
            <div class="inventory-panel-head">
                <div>
                    <h2>Servers</h2>
                    <p>Select a server to view its systems, access methods, and credentials.</p>
                </div><button
                    class="btn btn-primary btn-sm"
                    type="button"
                    data-new="server"
                ><?= UiIcon('plus') ?> Add server</button>
            </div>
            <div class="inventory-filters">
                <div class="inventory-search"><label for="serverSearch">Search inventory</label><input
                        type="search"
                        id="serverSearch"
                        placeholder="Server, IP, group, or application…"
                        maxlength="200"
                    ></div>
                <div><label for="serverEnvironment">Environment</label><select id="serverEnvironment">
                        <option value="">All environments</option>
                    </select></div>
                <div><label for="serverGroupFilter">Group</label><select id="serverGroupFilter">
                        <option value="">All groups</option>
                        <option value="ungrouped">Ungrouped</option>
                    </select></div>
                <div><label for="serverStatusFilter">Status</label><select id="serverStatusFilter">
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select></div>
            </div>
            <div class="inventory-list-meta"><span
                    id="serverResults"
                    role="status"
                    aria-live="polite"
                >Loading servers…</span><button
                    type="button"
                    class="inventory-text-button"
                    id="serverClearFilters"
                >Clear filters</button></div>
            <div class="table-wrap inventory-server-table">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Server / address</th>
                            <th scope="col">Environment / group</th>
                            <th scope="col">Hosting / role</th>
                            <th scope="col">Resources</th>
                            <th scope="col">Applications</th>
                        </tr>
                    </thead>
                    <tbody id="inventoryServerRows">
                        <tr>
                            <td colspan="5" class="empty-state">Loading servers…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                class="inventory-pagination"
                id="serverPagination"
                aria-label="Server pages"
                hidden
            ><button
                    class="btn btn-sm"
                    id="serverPrevious"
                    type="button"
                ><?= UiIcon('arrow-left') ?> Previous</button><span id="serverPageLabel"></span><button
                    class="btn btn-sm"
                    id="serverNext"
                    type="button"
                >Next <?= UiIcon('arrow-right') ?></button></nav>
        </div>

        <section
            class="card inventory-panel"
            id="inventoryServerDetail"
            hidden
            aria-labelledby="inventoryDetailName"
        >
            <div class="inventory-detail-head">
                <div><button
                        type="button"
                        class="inventory-text-button"
                        id="serverBack"
                    ><?= UiIcon('arrow-left') ?> All servers</button>
                    <h2 id="inventoryDetailName" tabindex="-1">Server details</h2>
                    <div id="inventoryDetailMeta" class="inventory-detail-meta"></div>
                </div><button
                    class="btn btn-sm"
                    type="button"
                    id="serverEdit"
                >Edit server</button>
            </div>
            <div
                class="inventory-detail-tabs"
                role="tablist"
                aria-label="Server details"
            ><button
                    id="serverOverviewTab"
                    type="button"
                    role="tab"
                    data-server-tab="overview"
                    aria-controls="serverOverviewPanel"
                    aria-selected="true"
                >Overview</button><button
                    id="serverSystemsTab"
                    type="button"
                    role="tab"
                    data-server-tab="systems"
                    aria-controls="serverSystemsPanel"
                    aria-selected="false"
                    tabindex="-1"
                >Applications</button><button
                    id="serverAccessTab"
                    type="button"
                    role="tab"
                    data-server-tab="access"
                    aria-controls="serverAccessPanel"
                    aria-selected="false"
                    tabindex="-1"
                >Access</button><button
                    id="serverCredentialsTab"
                    type="button"
                    role="tab"
                    data-server-tab="credentials"
                    aria-controls="serverCredentialsPanel"
                    aria-selected="false"
                    tabindex="-1"
                >Credentials</button></div>
            <div
                id="serverOverviewPanel"
                role="tabpanel"
                aria-labelledby="serverOverviewTab"
                class="inventory-detail-body"
            ></div>
            <div
                id="serverSystemsPanel"
                role="tabpanel"
                aria-labelledby="serverSystemsTab"
                class="inventory-detail-body"
                hidden
            ></div>
            <div
                id="serverAccessPanel"
                role="tabpanel"
                aria-labelledby="serverAccessTab"
                class="inventory-detail-body"
                hidden
            ></div>
            <div
                id="serverCredentialsPanel"
                role="tabpanel"
                aria-labelledby="serverCredentialsTab"
                class="inventory-detail-body"
                hidden
            ></div>
        </section>
    </section>

    <section
        id="inventoryGroups"
        role="tabpanel"
        aria-labelledby="inventoryGroupsTab"
        hidden
    >
        <div class="card inventory-panel" id="inventoryGroupList">
            <div class="inventory-panel-head">
                <div>
                    <h2>Server groups</h2>
                    <p>Organize related servers and the applications shared across them.</p>
                </div><button
                    class="btn btn-primary btn-sm"
                    type="button"
                    data-new="group"
                ><?= UiIcon('plus') ?> Add group</button>
            </div>
            <div class="inventory-group-search"><label for="groupSearch">Find a group</label><input
                    type="search"
                    id="groupSearch"
                    placeholder="Group name or description…"
                    maxlength="200"
                ></div>
            <div id="inventoryGroupCards" class="inventory-group-grid">
                <div class="empty-state">Loading groups…</div>
            </div>
        </div>
        <section
            class="card inventory-panel"
            id="inventoryGroupDetail"
            hidden
            aria-labelledby="inventoryGroupName"
        >
            <div class="inventory-detail-head">
                <div><button
                        type="button"
                        class="inventory-text-button"
                        id="groupBack"
                    ><?= UiIcon('arrow-left') ?> All groups</button>
                    <h2 id="inventoryGroupName" tabindex="-1">Group details</h2>
                    <p id="inventoryGroupDescription" class="muted text-sm"></p>
                </div><button
                    class="btn btn-sm"
                    type="button"
                    id="groupEdit"
                >Edit group</button>
            </div>
            <div id="inventoryGroupMembers" class="inventory-group-members"></div>
            <div id="inventoryGroupSystems" class="inventory-detail-body"></div>
        </section>
    </section>

    <section
        id="inventoryRenewals"
        role="tabpanel"
        aria-labelledby="inventoryRenewalsTab"
        hidden
    >
        <div class="card inventory-panel">
            <div class="inventory-panel-head">
                <div>
                    <h2>Certificates &amp; renewals</h2>
                    <p>Certificates, licenses, and services, ordered by their next expiry.</p>
                </div><button
                    class="btn btn-primary btn-sm"
                    type="button"
                    data-new="expiry"
                ><?= UiIcon('plus') ?> Track renewal</button>
            </div>
            <div class="inventory-filters inventory-renewal-filters">
                <div><label for="renewalSearch">Search renewals</label><input
                        type="search"
                        id="renewalSearch"
                        placeholder="Name, server, or application…"
                        maxlength="200"
                    ></div>
                <div><label for="renewalStatus">Renewal status</label><select id="renewalStatus">
                        <option value="">All renewals</option>
                        <option value="attention">Needs attention</option>
                        <option value="expired">Expired</option>
                        <option value="soon">Due soon</option>
                        <option value="healthy">Current</option>
                    </select></div>
                <div><label for="renewalType">Type</label><select id="renewalType">
                        <option value="">All types</option>
                        <option>SSL Certificate</option>
                        <option>License</option>
                        <option>Other Service</option>
                    </select></div>
            </div>
            <div class="inventory-list-meta"><span
                    id="renewalResults"
                    role="status"
                    aria-live="polite"
                >Loading renewals…</span><button
                    class="inventory-text-button"
                    type="button"
                    id="renewalClearFilters"
                >Clear filters</button></div>
            <div class="table-wrap">
                <table class="inventory-renewal-table">
                    <thead>
                        <tr>
                            <th scope="col">Renewal</th>
                            <th scope="col">Linked server / application</th>
                            <th scope="col">Expiry date</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="inventory-sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody id="inventoryRenewalRows">
                        <tr>
                            <td colspan="5" class="empty-state">Loading renewals…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <p
        class="inventory-page-message"
        id="inventoryPageMessage"
        role="status"
        aria-live="polite"
    ></p>
</div>

<dialog
    class="inventory-modal"
    id="inventoryEditor"
    aria-labelledby="inventoryEditorTitle"
>
    <form id="inventoryEditorForm">
        <div class="inventory-modal-head">
            <div>
                <p class="inventory-eyebrow" id="inventoryEditorContext"></p>
                <h2 id="inventoryEditorTitle">Edit record</h2>
            </div><button
                class="inventory-icon-button"
                type="button"
                data-close-editor
                aria-label="Close editor"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="inventory-modal-body">
            <fieldset id="inventoryEditorFields"></fieldset>
            <p
                id="inventoryEditorMessage"
                class="inventory-form-message"
                role="status"
                aria-live="polite"
            ></p>
        </div>
        <div class="inventory-modal-footer">
            <div class="inventory-editor-secondary"><button
                    class="btn btn-sm btn-danger"
                    type="button"
                    id="inventoryEditorDelete"
                    hidden
                >Delete</button><button
                    class="btn btn-sm"
                    type="button"
                    id="inventoryEditorShare"
                    hidden
                >Manage sharing</button></div>
            <div><button
                    class="btn btn-sm"
                    type="button"
                    data-close-editor
                >Cancel</button><button
                    class="btn btn-primary btn-sm"
                    type="submit"
                    id="inventoryEditorSave"
                >Save changes</button></div>
        </div>
    </form>
</dialog>

<dialog
    class="inventory-modal inventory-share-modal"
    id="inventoryShareDialog"
    aria-labelledby="inventoryShareTitle"
>
    <div class="inventory-modal-head">
        <div>
            <p class="inventory-eyebrow">CREDENTIAL ACCESS</p>
            <h2 id="inventoryShareTitle">Manage sharing</h2>
        </div><button
            class="inventory-icon-button"
            type="button"
            data-close-share
            aria-label="Close sharing"
        ><?= UiIcon('x') ?></button>
    </div>
    <div class="inventory-modal-body">
        <form id="inventoryShareForm" class="inventory-share-form">
            <div><label for="inventoryShareUser">Share with a user</label><select id="inventoryShareUser" required>
                    <option value="">Choose a user</option>
                </select></div><button
                class="btn btn-primary btn-sm"
                type="submit"
                id="inventoryShareSave"
            >Share</button>
        </form>
        <p
            class="inventory-form-message"
            id="inventoryShareMessage"
            role="status"
            aria-live="polite"
        ></p>
        <div id="inventoryShares"></div>
    </div>
    <div class="inventory-modal-footer"><span class="text-sm muted">Only the creator can manage sharing.</span><button
            class="btn btn-sm"
            type="button"
            data-close-share
        >Done</button></div>
</dialog>

<?php echo view('layouts/footer', get_defined_vars());
?>
