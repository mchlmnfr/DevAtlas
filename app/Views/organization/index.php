<?php
/**
 * Filename: index.php
 * Purpose: Render the Organization workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="organizationApp"
    data-api="<?= APP_BASE_URL ?>/modules/organization/api.php"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">ADMINISTRATION / ORGANIZATION</div>
            <h2>A clear structure for your teams.</h2>
            <p>Build your hierarchy, from groups to positions. Select an entry to view its place in the organization.</p>
        </div><button
            class="btn btn-primary"
            id="orgAddGroup"
            type="button"
        ><?= UiIcon('plus') ?> New group</button>
    </section>
    <section class="ws-summary five" aria-label="Organization totals">

        <?php foreach (['group' => 'Groups','division' => 'Divisions','department' => 'Departments','unit' => 'Units','position' => 'Positions'] as $key => $label): ?>
        <div class="ws-stat"><span class="ws-stat-icon" data-icon="branch"></span><span><strong id="orgCount_<?= $key ?>">—</strong><small><?= $label ?></small></span></div>

        <?php endforeach; ?>
    </section>
    <div class="org-layout">
        <section class="ws-card" aria-labelledby="orgBrowserTitle">
            <div class="ws-card-heading">
                <div>
                    <h3 id="orgBrowserTitle">Organization explorer</h3>
                    <p id="orgResultCount" role="status">Loading your structure…</p>
                </div>
                <div class="ws-actions"><button
                        type="button"
                        class="ws-link-button"
                        id="orgExpand"
                    >Expand all</button><span class="muted" aria-hidden="true">·</span><button
                        type="button"
                        class="ws-link-button"
                        id="orgCollapse"
                    >Collapse all</button></div>
            </div>
            <div class="ws-toolbar">
                <div class="form-row ws-search"><label for="orgSearch">Find a team or position</label>
                    <div class="ws-search-wrap"><span data-icon="search"></span><input
                            type="search"
                            id="orgSearch"
                            placeholder="Search names or descriptions…"
                            maxlength="200"
                            autocomplete="off"
                        ></div>
                </div><button
                    type="button"
                    class="btn btn-sm"
                    id="orgClear"
                >Clear</button>
            </div>
            <div
                id="orgLoadError"
                class="ws-state"
                hidden
            ><strong>We couldn’t load the organization.</strong>
                <p>Check your connection, then try again.</p><button
                    class="btn"
                    id="orgRetry"
                    type="button"
                >Try again</button>
            </div>
            <div
                id="orgEmpty"
                class="ws-state"
                hidden
            ><span data-icon="branch"></span><strong id="orgEmptyTitle">Your organization starts here.</strong>
                <p id="orgEmptyText">Add a group, then build the teams beneath it.</p><button
                    class="btn btn-primary"
                    type="button"
                    id="orgEmptyAdd"
                ><?= UiIcon('plus') ?> New group</button>
            </div>
            <ul
                id="orgTree"
                class="org-tree"
                aria-label="Organization hierarchy"
                aria-busy="true"
            ></ul>
        </section>
        <aside class="ws-card org-detail" aria-labelledby="orgDetailTitle">
            <div class="ws-card-heading">
                <div>
                    <h3 id="orgDetailTitle">Entry details</h3>
                    <p>One place to view and manage an entry.</p>
                </div><span data-icon="branch"></span>
            </div>
            <div class="ws-card-body" id="orgDetail">
                <div class="org-detail-placeholder"><span data-icon="branch"></span><strong>Select an entry</strong>
                    <p>Choose a group, team or position from the explorer to see its details and available actions.</p>
                </div>
            </div>
        </aside>
    </div>
    <div class="ws-note"><strong>Keep people and contacts connected.</strong> Assign accounts in <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/usermgmt/index.php')) ?>">Users</a> and maintain shared department contacts in the <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/directory/index.php')) ?>">Call Directory</a>.</div>
</div>
<dialog
    id="orgEditor"
    class="ws-dialog"
    aria-labelledby="orgEditorTitle"
>
    <form id="orgForm">
        <div class="ws-dialog-header">
            <div>
                <h3 id="orgEditorTitle">New entry</h3>
                <p id="orgEditorSubtitle">Add an entry to your organization.</p>
            </div><button
                type="button"
                class="ws-dialog-close"
                data-dialog-close="orgEditor"
                aria-label="Close organization editor"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                id="orgFormError"
                class="ws-error"
                role="alert"
                tabindex="-1"
                hidden
            ></div>
            <div
                class="ws-note org-parent-note"
                id="orgParentNote"
                hidden
            ></div>
            <div class="form-row"><label for="orgName" id="orgNameLabel">Name *</label><input
                    type="text"
                    id="orgName"
                    maxlength="150"
                    required
                    autocomplete="off"
                ></div>
            <div class="form-row"><label for="orgDescription">Description <span class="muted">(optional)</span></label><textarea
                    id="orgDescription"
                    rows="4"
                    placeholder="What does this team or position do?"
                ></textarea></div>
        </div>
        <div class="ws-dialog-footer"><button
                class="btn"
                type="button"
                data-dialog-close="orgEditor"
            >Cancel</button><button
                class="btn btn-primary"
                type="submit"
                id="orgSave"
            >Save entry</button></div>
    </form>
</dialog>

<?php echo view('layouts/footer', get_defined_vars());
?>
