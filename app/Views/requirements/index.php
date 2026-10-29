<?php
/**
 * Filename: index.php
 * Purpose: Render the Requirements workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    id="requirementsApp"
    data-api="<?= H(APP_BASE_URL) ?>/modules/requirements/api.php"
    class="card"
>
    <div class="card-header">
        <h2 class="layout-38965f9b">Requirements</h2>
        <div class="layout-d6241da4">
            <a class="btn btn-sm" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/requirements/excel_export.php')) ?>">Export Excel</a>
            <button
                class="btn btn-primary btn-sm"
                type="button"
                onclick="NewRequirement()"
            ><?= UiIcon('plus') ?> New Requirement</button>
        </div>
    </div>
    <div class="filter-bar">
        <input
            type="search"
            id="reqSearch"
            placeholder="Search ID, title, or description..."
            class="layout-bb78a856"
        >
        <select id="reqStatus" aria-label="Filter by status">
            <option value="">All statuses</option>
            <option>Draft</option>
            <option>Approved</option>
            <option>Implemented</option>
            <option>Retired</option>
        </select>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Owner</th>
                    <th>Links</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="reqRows">
                <tr>
                    <td colspan="7" class="muted">Loading...</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="grid cols-2 layout-022bc9ae">
    <div class="card" id="reqDetail">
        <h2>Requirement details</h2>
        <p class="muted text-sm">Select a requirement to see and manage its linked assets.</p>
    </div>
    <div class="card">
        <h2>Impact view</h2>
        <p class="muted text-sm">Select an asset to find requirements tied to it. Selecting a database table also includes links to its columns.</p>
        <div class="form-row"><label for="impactType">Asset type</label><select id="impactType"></select></div>
        <div class="form-row"><label for="impactAsset">Asset</label><select id="impactAsset"></select></div>
        <button
            class="btn btn-primary btn-sm"
            type="button"
            onclick="LoadImpact()"
        >Show impact</button>
        <div id="impactResults" class="layout-76977cc9"></div>
    </div>
</div>

<div class="modal-overlay" id="reqModal">
    <div class="modal layout-e467eac5">
        <div class="modal-header">
            <h3 id="reqModalTitle">New Requirement</h3><button
                class="modal-close"
                type="button"
                aria-label="Close requirement editor"
                aria-label="Close requirement editor"
                onclick="AT.closeModal('reqModal')"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="r_id">
            <div class="grid cols-2">
                <div class="form-row"><label for="r_code">Requirement ID *</label><input
                        id="r_code"
                        maxlength="40"
                        placeholder="REQ-001"
                        required
                    ></div>
                <div class="form-row"><label for="r_title">Title *</label><input
                        id="r_title"
                        maxlength="200"
                        required
                    ></div>
            </div>
            <div class="form-row"><label for="r_description">Description</label><textarea id="r_description" rows="5"></textarea></div>
            <div class="grid cols-2">
                <div class="form-row"><label for="r_status">Status</label><select id="r_status">
                        <option>Draft</option>
                        <option>Approved</option>
                        <option>Implemented</option>
                        <option>Retired</option>
                    </select></div>
                <div class="form-row"><label for="r_priority">Priority</label><select id="r_priority">
                        <option>Low</option>
                        <option selected>Medium</option>
                        <option>High</option>
                        <option>Critical</option>
                    </select></div>
            </div>
            <div class="form-row"><label for="r_owner">Owner</label><select id="r_owner">
                    <option value="">Unassigned</option>
                </select></div>
        </div>
        <div class="modal-footer"><button
                class="btn"
                type="button"
                onclick="AT.closeModal('reqModal')"
            >Cancel</button><button
                class="btn btn-primary"
                type="button"
                onclick="SaveRequirement()"
            >Save</button></div>
    </div>
</div>

<?php echo view('layouts/footer', get_defined_vars());
?>
