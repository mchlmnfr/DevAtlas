<?php
/**
 * Filename: index.php
 * Purpose: Render the Applications workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="applicationsApp"
    data-api="<?= APP_BASE_URL ?>/modules/applications/api.php"
    data-base="<?= APP_BASE_URL ?>"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">APPLICATION PORTFOLIO</div>
            <h2>Know what you own.</h2>
            <p>Connect each application to its owners, infrastructure, documentation and review history.</p>
        </div><button
            class="btn btn-primary"
            id="applicationAdd"
            data-permission="create"
        ><?= UiIcon('plus') ?> New application</button>
    </section>
    <div
        id="applicationError"
        class="ws-error"
        role="alert"
        hidden
        tabindex="-1"
    ></div>
    <div class="ws-summary">

        <?php foreach (['total' => 'Applications','active' => 'Active applications','critical' => 'Critical applications','unowned' => 'Without an owner'] as $key => $label):?>
        <div class="ws-stat"><span><strong id="appStat_<?= $key ?>">—</strong><small><?= $label ?></small></span></div>

        <?php endforeach; ?>
    </div>
    <section class="ws-card">
        <div class="ws-card-heading">
            <div>
                <h3>Application catalog</h3>
                <p>Link existing inventory entries to an application; existing records remain in their own modules.</p>
            </div><button class="btn" id="applicationRefresh">Refresh</button>
        </div>
        <div class="wf-filters">
            <div class="form-row wf-search"><label for="applicationSearch">Search applications</label><input
                    id="applicationSearch"
                    type="search"
                    maxlength="200"
                    placeholder="Code, name or description"
                ></div>
            <div class="form-row"><label for="applicationLifecycle">Lifecycle</label><select id="applicationLifecycle">
                    <option value="">All lifecycles</option>
                    <option>Planned</option>
                    <option>Active</option>
                    <option>Maintenance</option>
                    <option>Retired</option>
                </select></div>
            <div class="form-row"><label for="applicationOwner">Owner</label><select id="applicationOwner">
                    <option value="">All owners</option>
                </select></div>
        </div>
        <div class="ws-table-wrap">
            <table class="ws-table">
                <thead>
                    <tr>
                        <th>Application</th>
                        <th>Owner</th>
                        <th>Lifecycle</th>
                        <th>Criticality</th>
                        <th>Assets</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="applicationRows"></tbody>
            </table>
        </div>
        <div class="ws-pagination"><span id="applicationCount"></span>
            <div class="ws-actions"><button id="applicationPrevious" class="btn btn-sm">Previous</button><button id="applicationNext" class="btn btn-sm">Next</button></div>
        </div>
    </section>
</div>
<dialog
    class="ws-dialog wide"
    id="applicationEditor"
    aria-labelledby="applicationEditorTitle"
>
    <form id="applicationForm">
        <div class="ws-dialog-header">
            <h3 id="applicationEditorTitle">New application</h3><button
                class="ws-dialog-close"
                type="button"
                data-dialog-close="applicationEditor"
                aria-label="Close editor"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                class="ws-error"
                id="applicationFormError"
                hidden
                role="alert"
                tabindex="-1"
            ></div><input id="ap_id" type="hidden">
            <div class="form-grid">
                <div class="form-row"><label for="ap_application_code">Code *</label><input
                        id="ap_application_code"
                        maxlength="40"
                        required
                        placeholder="APP-001"
                    ></div>
                <div class="form-row"><label for="ap_application_name">Application name *</label><input
                        id="ap_application_name"
                        maxlength="180"
                        required
                    ></div>
            </div>
            <div class="form-row"><label for="ap_description">Purpose / description</label><textarea
                    id="ap_description"
                    rows="3"
                    maxlength="5000"
                ></textarea></div>
            <div class="form-grid">
                <div class="form-row"><label for="ap_lifecycle">Lifecycle</label><select id="ap_lifecycle">
                        <option>Planned</option>
                        <option selected>Active</option>
                        <option>Maintenance</option>
                        <option>Retired</option>
                    </select></div>
                <div class="form-row"><label for="ap_criticality">Criticality</label><select id="ap_criticality">
                        <option>Low</option>
                        <option selected>Medium</option>
                        <option>High</option>
                        <option>Critical</option>
                    </select></div>
                <div class="form-row"><label for="ap_owner_user_id">Business owner</label><select id="ap_owner_user_id"></select></div>
                <div class="form-row"><label for="ap_technical_owner_id">Technical owner</label><select id="ap_technical_owner_id"></select></div>
                <div class="form-row"><label for="ap_department_id">Department</label><select id="ap_department_id"></select></div>
                <div class="form-row"><label for="ap_application_url">Application URL</label><input
                        id="ap_application_url"
                        type="url"
                        maxlength="1000"
                        placeholder="https://"
                    ></div>
            </div>
            <div class="form-row"><label for="ap_repository_url">Repository URL</label><input
                    id="ap_repository_url"
                    type="url"
                    maxlength="1000"
                    placeholder="https://"
                ></div>
        </div>
        <div class="ws-dialog-footer"><button
                class="btn"
                type="button"
                data-dialog-close="applicationEditor"
            >Cancel</button><button class="btn btn-primary" id="applicationSave">Save application</button></div>
    </form>
</dialog>
<dialog
    class="ws-dialog wide"
    id="applicationDetail"
    aria-labelledby="applicationDetailTitle"
>
    <div class="ws-dialog-header">
        <div>
            <h3 id="applicationDetailTitle">Application</h3>
            <p id="applicationDetailSubtitle"></p>
        </div><button
            class="ws-dialog-close"
            type="button"
            data-dialog-close="applicationDetail"
            aria-label="Close details"
        ><?= UiIcon('x') ?></button>
    </div>
    <div class="ws-dialog-body">
        <div
            class="ws-error"
            id="applicationDetailError"
            role="alert"
            hidden
            tabindex="-1"
        ></div>
        <div id="applicationDetailBody"></div>
        <div class="wf-dialog-actions"><button
                id="applicationEdit"
                class="btn"
                data-permission="edit"
            >Edit application</button><button
                id="applicationDelete"
                class="btn btn-danger"
                data-permission="delete"
            >Delete application</button></div>
        <section data-permission="edit">
            <h4>Connect an existing asset</h4>
            <div class="form-grid">
                <div class="form-row"><label for="applicationAssetType">Asset type</label><select id="applicationAssetType"></select></div>
                <div class="form-row"><label for="applicationAsset">Existing record</label><select id="applicationAsset"></select></div>
            </div><button id="applicationLink" class="btn btn-primary">Link asset</button>
        </section>
        <div id="applicationAssets" class="wf-links"></div>
        <div id="applicationRelated"></div>
    </div>
    <div class="ws-dialog-footer"><button
            class="btn"
            type="button"
            data-dialog-close="applicationDetail"
        >Close</button></div>
</dialog>

<?php echo view('layouts/footer', get_defined_vars());
?>
