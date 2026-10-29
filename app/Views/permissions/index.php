<?php
/**
 * Filename: index.php
 * Purpose: Render the Permissions workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="permissionsApp"
    data-api="<?= APP_BASE_URL ?>/modules/permissions/api.php"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">ACCESS MANAGEMENT</div>
            <h2>A role for every responsibility.</h2>
            <p>Create a custom role, choose its permissions, then assign it in Users. Access changes apply on the next request.</p>
        </div>
        <div class="ws-actions"><a class="btn" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/usermgmt/index.php')) ?>">Manage users</a><button
                class="btn btn-primary"
                id="roleAdd"
                type="button"
                disabled
            ><?= UiIcon('plus') ?> New role</button></div>
    </section>
    <div
        id="permissionsError"
        class="ws-error"
        hidden
        role="alert"
        tabindex="-1"
    ></div>
    <section class="ws-card" aria-labelledby="roleHeading">
        <div class="ws-card-heading">
            <div>
                <h3 id="roleHeading">Role settings</h3>
                <p>Start with minimal access or copy an existing permission profile.</p>
            </div><button
                class="btn btn-sm"
                type="button"
                id="roleRefresh"
            >Refresh</button>
        </div>
        <div class="wf-role-settings">
            <div class="form-row"><label for="permissionRole">Select role</label><select id="permissionRole" disabled></select></div>
            <div class="wf-role-profile">
                <div class="ws-actions"><strong id="roleName">Loading roles…</strong><span class="ws-tag gray" id="roleKind"></span><span class="ws-tag" id="roleUsers"></span></div>
                <p id="roleDescription" class="wf-description"></p><code id="roleKey"></code>
            </div>
            <div class="ws-actions"><button
                    class="btn btn-sm"
                    type="button"
                    id="roleEdit"
                    hidden
                >Edit details</button><button
                    class="btn btn-sm"
                    type="button"
                    id="roleCopy"
                    disabled
                >Copy role</button><button
                    class="btn btn-sm btn-danger"
                    type="button"
                    id="roleDelete"
                    hidden
                >Delete role</button></div>
        </div>
    </section>
    <section class="ws-card">
        <div class="ws-card-heading">
            <div>
                <h3>Permission matrix</h3>
                <p>Modules follow the sidebar groups and order.</p>
                <p id="permissionHelp">View is required before other module permissions apply. Preset role names remain protected.</p>
            </div>
            <div class="ws-actions"><span
                    id="permissionStatus"
                    class="wf-muted"
                    role="status"
                    aria-live="polite"
                ></span><button
                    class="btn btn-primary"
                    id="permissionSave"
                    disabled
                >Save permissions</button></div>
        </div>
        <div class="ws-table-wrap">
            <table class="ws-table wf-matrix">
                <thead id="permissionHead"></thead>
                <tbody id="permissionRows"></tbody>
            </table>
        </div>
        <div class="wf-panel wf-muted">Public directory and Quick Links viewing, account self-service and personal notifications remain available. Credential access also requires the vault's existing owner/share checks. Support permits protected authentication email previews. Role administration is restricted to Administrators.</div>
    </section>
    <dialog
        class="ws-dialog"
        id="roleEditor"
        aria-labelledby="roleEditorTitle"
    >
        <form id="roleForm">
            <div class="ws-dialog-header">
                <div>
                    <h3 id="roleEditorTitle">New role</h3>
                    <p id="roleEditorHint">Set up a role for your team.</p>
                </div><button
                    class="ws-dialog-close"
                    type="button"
                    data-dialog-close="roleEditor"
                    aria-label="Close role editor"
                ><?= UiIcon('x') ?></button>
            </div>
            <div class="ws-dialog-body">
                <div
                    id="roleFormError"
                    class="ws-error"
                    hidden
                    role="alert"
                    tabindex="-1"
                ></div>
                <div class="form-row"><label for="roleEditorName">Role name <span aria-hidden="true">*</span></label><input
                        id="roleEditorName"
                        required
                        maxlength="80"
                        autocomplete="off"
                        placeholder="e.g. Support specialist"
                    ></div>
                <div class="form-row"><label for="roleEditorKey">Role key <span aria-hidden="true">*</span></label><input
                        id="roleEditorKey"
                        required
                        maxlength="20"
                        pattern="[a-z][a-z0-9_]{0,19}"
                        autocomplete="off"
                        placeholder="e.g. support_specialist"
                        aria-describedby="roleKeyHint"
                    >
                    <p class="hint" id="roleKeyHint">A unique identifier: 1–20 lowercase letters, numbers or underscores. It stays fixed after creation.</p>
                </div>
                <div class="form-row" id="roleSourceRow"><label for="roleEditorSource">Starting permissions</label><select id="roleEditorSource"></select>
                    <p class="hint">A copy is independent: future changes to the original role do not affect this one.</p>
                </div>
                <div class="form-row"><label for="roleEditorDescription">Description</label><textarea
                        id="roleEditorDescription"
                        rows="3"
                        maxlength="500"
                        placeholder="Who should use this role and what they are responsible for"
                    ></textarea></div>
            </div>
            <div class="ws-dialog-footer"><button
                    class="btn"
                    type="button"
                    data-dialog-close="roleEditor"
                >Cancel</button><button
                    class="btn btn-primary"
                    type="submit"
                    id="roleSubmit"
                >Create role</button></div>
        </form>
    </dialog>
</div>

<?php echo view('layouts/footer', get_defined_vars());
?>
