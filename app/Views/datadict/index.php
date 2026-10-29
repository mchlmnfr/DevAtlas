<?php
/**
 * Filename: index.php
 * Purpose: Render the Datadict workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div class="dd-heading">
    <div>
        <h2>Your data catalog</h2>
        <p class="muted">Keep technical structure and business meaning together.</p>
    </div>
    <div class="dd-actions"><button
            class="btn"
            id="ddRefresh"
            type="button"
        >Refresh</button><button
            class="btn"
            id="ddImport"
            type="button"
        >Import metadata</button><button
            class="btn btn-primary"
            id="ddNewTable"
            type="button"
        ><?= UiIcon('plus') ?> New table</button></div>
</div>
<div
    id="ddStats"
    class="dd-stats"
    aria-live="polite"
></div>
<div class="dd-workspace">
    <section class="card dd-catalog" aria-labelledby="ddCatalogTitle">
        <div class="card-header">
            <h2 id="ddCatalogTitle">Tables &amp; views</h2><span class="hint" id="ddCatalogCount"></span>
        </div>
        <div class="form-row"><label for="ddSearch">Find a table</label><input
                id="ddSearch"
                type="search"
                placeholder="Name, schema, description or owner"
            ></div>
        <div class="dd-filter-pair">
            <div class="form-row"><label for="ddSource">Database</label><select id="ddSource">
                    <option value="">All databases</option>
                </select></div>
            <div class="form-row"><label for="ddEngine">Engine</label><select id="ddEngine">
                    <option value="">All engines</option>
                </select></div>
        </div>
        <div class="dd-filter-pair">
            <div class="form-row"><label for="ddStatus">Status</label><select id="ddStatus">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select></div>
            <div class="form-row"><label for="ddCoverage">Documentation</label><select id="ddCoverage">
                    <option value="">All coverage</option>
                    <option value="missing">Needs descriptions</option>
                    <option value="complete">Fully described</option>
                    <option value="sensitive">Sensitive fields</option>
                </select></div>
        </div>
        <div class="dd-export-bar"><a
                class="btn btn-sm"
                id="ddExcel"
                aria-disabled="true"
            >Export Excel</a><a
                class="btn btn-sm"
                id="ddJson"
                aria-disabled="true"
            >Export metadata JSON</a></div>
        <p class="hint" id="ddExportHint">Choose a database to export its dictionary.</p>
        <div id="ddTableList" class="dd-table-list">
            <p class="empty-state">Loading catalog…</p>
        </div>
        <div class="dd-pagination"><button
                class="btn btn-sm"
                id="ddPrev"
                type="button"
            >Previous</button><span class="hint" id="ddPage"></span><button
                class="btn btn-sm"
                id="ddNext"
                type="button"
            >Next</button></div>
    </section>
    <section
        class="card dd-detail"
        id="ddDetail"
        aria-live="polite"
    >
        <div class="dd-welcome"><span class="dd-welcome-icon" aria-hidden="true"><?= UiIcon('file-text') ?></span>
            <h2>Select a table to get started</h2>
            <p class="muted">Explore columns, document business rules and follow relationships.</p><button
                class="btn"
                type="button"
                data-dd="import"
            >Import from a database or metadata file</button>
        </div>
    </section>
</div>

<dialog
    id="ddEditor"
    class="dd-dialog dd-editor"
    aria-labelledby="ddEditorTitle"
>
    <form id="ddEditorForm">
        <header class="dd-dialog-header">
            <h2 id="ddEditorTitle">Edit metadata</h2><button
                class="btn btn-sm"
                type="button"
                data-close="ddEditor"
                aria-label="Close editor"
            ><?= UiIcon('x') ?></button>
        </header>
        <div class="dd-dialog-body">
            <p
                id="ddEditorError"
                class="dd-editor-error"
                role="alert"
                hidden
            ></p>
            <div id="ddEditorFields"></div>
        </div>
        <footer class="dd-dialog-footer"><button
                class="btn btn-danger"
                id="ddEditorDelete"
                type="button"
                hidden
            >Delete</button><span class="dd-spacer"></span><button
                class="btn"
                type="button"
                data-close="ddEditor"
            >Cancel</button><button
                class="btn btn-primary"
                id="ddEditorSave"
                type="submit"
            >Save changes</button></footer>
    </form>
</dialog>

<dialog
    id="ddImportDialog"
    class="dd-dialog dd-import-dialog"
    aria-labelledby="ddImportTitle"
>
    <header class="dd-dialog-header">
        <div>
            <h2 id="ddImportTitle">Import metadata</h2>
            <p class="hint">Read structure, then choose the objects to add or refresh.</p>
        </div><button
            class="btn btn-sm"
            type="button"
            data-close="ddImportDialog"
            aria-label="Close import"
        ><?= UiIcon('x') ?></button>
    </header>
    <div class="dd-dialog-body">
        <div
            class="dd-tabs"
            role="tablist"
            aria-label="Import source"
        ><button
                class="active"
                id="ddLiveTab"
                type="button"
                role="tab"
                aria-selected="true"
                aria-controls="ddLivePanel"
            >Live database</button><button
                id="ddFileTab"
                type="button"
                role="tab"
                aria-selected="false"
                aria-controls="ddFilePanel"
            >Metadata JSON</button></div>
        <div
            id="ddLivePanel"
            role="tabpanel"
            aria-labelledby="ddLiveTab"
        >
            <p class="hint">Reads catalogs only. Table rows and target data are never imported or changed.</p>
            <div class="form-row"><label for="ddConnection">Connection profile</label><select id="ddConnection">
                    <option value="">New connection</option>
                </select></div>
            <div
                class="dd-actions"
                id="ddProfileActions"
                hidden
            ><button
                    class="btn btn-sm"
                    id="ddEditProfile"
                    type="button"
                >Edit profile</button><button
                    class="btn btn-sm btn-danger"
                    id="ddDeleteProfile"
                    type="button"
                >Delete profile</button></div>
            <div id="ddConnectionFields">
                <div class="form-grid">
                    <div class="form-row"><label for="ddConnectEngine">Database engine</label><select id="ddConnectEngine"></select></div>
                    <div class="form-row" id="ddPortRow"><label for="ddConnectPort">Port</label><input
                            id="ddConnectPort"
                            type="number"
                            min="1"
                            max="65535"
                        ></div>
                </div>
                <p
                    id="ddDriverInfo"
                    class="dd-driver-info"
                    role="status"
                ></p>
                <div class="form-grid">
                    <div class="form-row" id="ddHostRow"><label id="ddHostLabel" for="ddConnectHost">Host</label><input
                            id="ddConnectHost"
                            type="text"
                            maxlength="255"
                            autocomplete="off"
                            placeholder="db.example.internal"
                        ></div>
                    <div class="form-row"><label id="ddDatabaseLabel" for="ddConnectDatabase">Database name</label><input
                            id="ddConnectDatabase"
                            type="text"
                            maxlength="1000"
                            autocomplete="off"
                        ></div>
                </div>
                <div class="form-grid" id="ddCredentialRows">
                    <div class="form-row"><label for="ddConnectUser">Username</label><input
                            id="ddConnectUser"
                            type="text"
                            maxlength="150"
                            autocomplete="off"
                        ></div>
                    <div class="form-row"><label for="ddConnectPassword">Password</label><input
                            id="ddConnectPassword"
                            type="password"
                            maxlength="4096"
                            autocomplete="new-password"
                        ><label
                            class="dd-check"
                            id="ddClearPasswordRow"
                            hidden
                        ><input type="checkbox" id="ddClearPassword">Clear stored password when saving</label></div>
                </div>
                <div class="dd-check"><input id="ddSaveProfile" type="checkbox"><label for="ddSaveProfile">Save connection for reuse</label></div>
                <div
                    class="form-row"
                    id="ddProfileLabelRow"
                    hidden
                ><label for="ddProfileLabel">Profile label</label><input
                        id="ddProfileLabel"
                        maxlength="150"
                        type="text"
                    >
                    <p class="hint">Saved passwords are encrypted with the application key and are never returned to the browser.</p>
                </div>
                <button
                    class="btn btn-sm"
                    id="ddSaveProfileBtn"
                    type="button"
                    hidden
                >Save profile</button>
            </div>
            <div
                id="ddProfileSummary"
                class="dd-profile-summary"
                hidden
            ></div>
            <div class="dd-actions dd-connect-actions"><button
                    class="btn"
                    id="ddTestConnection"
                    type="button"
                >Test connection</button><button
                    class="btn btn-primary"
                    id="ddLoadSchemas"
                    type="button"
                >Connect &amp; browse</button></div>
            <div id="ddSchemaFields" hidden>
                <div class="form-grid">
                    <div class="form-row"><label for="ddImportSchema">Schema</label><select id="ddImportSchema"></select></div>
                    <div class="form-row"><label for="ddImportDatabase">Dictionary database label</label><input
                            id="ddImportDatabase"
                            type="text"
                            maxlength="150"
                        >
                        <p class="hint">Use the existing label to refresh the same dictionary.</p>
                    </div>
                </div><label class="dd-check"><input
                        id="ddIncludeViews"
                        type="checkbox"
                        checked
                    >Include views</label><button
                    class="btn btn-sm"
                    id="ddLoadObjects"
                    type="button"
                >Load tables &amp; views</button>
            </div>
        </div>
        <div
            id="ddFilePanel"
            role="tabpanel"
            aria-labelledby="ddFileTab"
            hidden
        >
            <p>Import a portable dictionary from any engine, including systems without a live connector. Use the example format or a metadata JSON export from this module.</p>
            <p class="hint">This accepts metadata JSON only, up to 6 MB, 500 tables/views and 25,000 fields. It does not execute SQL or infer NoSQL field types from records.</p>
            <a class="btn btn-sm" id="ddExample">Download example JSON</a>
            <div class="form-row"><label for="ddMetadataFile">Dictionary metadata file</label><input
                    id="ddMetadataFile"
                    type="file"
                    accept=".json,application/json"
                ></div>
            <div class="form-row"><label for="ddFileDatabase">Dictionary database label override (optional)</label><input
                    id="ddFileDatabase"
                    type="text"
                    maxlength="150"
                    placeholder="Leave blank to keep the file’s database labels"
                ></div>
            <button
                class="btn btn-primary"
                id="ddPreviewFile"
                type="button"
            >Validate &amp; preview file</button>
        </div>
        <p
            id="ddImportStatus"
            class="dd-import-status"
            role="status"
            aria-live="polite"
        ></p>
        <section
            id="ddObjectSelection"
            hidden
            aria-label="Choose objects to import"
        >
            <div class="card-header">
                <h3>Choose objects</h3><span class="hint" id="ddSelectionCount"></span>
            </div>
            <div class="dd-selection-tools"><input
                    id="ddObjectSearch"
                    type="search"
                    aria-label="Search import objects"
                    placeholder="Filter objects"
                ><button
                    class="btn btn-sm"
                    id="ddSelectVisible"
                    type="button"
                >Select visible</button><button
                    class="btn btn-sm"
                    id="ddClearSelection"
                    type="button"
                >Clear selection</button></div>
            <div id="ddObjectList" class="dd-object-list"></div>
            <details id="ddPreviewDetails" hidden>
                <summary>Column preview</summary>
                <div class="table-wrap" id="ddColumnPreview"></div>
            </details>
            <p class="hint">Re-import refreshes structure and keeps your descriptions, rules and sensitivity flags. Previously documented columns missing from the source are retained and reported for review.</p>
        </section>
        <section id="ddImportProgress" hidden><progress
                id="ddProgressBar"
                value="0"
                max="1"
            ></progress>
            <p
                id="ddProgressText"
                class="hint"
                aria-live="polite"
            ></p>
            <div id="ddImportResults" class="dd-import-results"></div>
        </section>
    </div>
    <footer class="dd-dialog-footer"><span class="hint" id="ddImportFooterHint">Choose a source to begin.</span><span class="dd-spacer"></span><button
            class="btn"
            id="ddStopImport"
            type="button"
            hidden
        >Stop after current table</button><button
            class="btn"
            type="button"
            data-close="ddImportDialog"
        >Close</button><button
            class="btn btn-primary"
            id="ddRunImport"
            type="button"
            disabled
        >Import selected</button></footer>
</dialog>
<script>window.DD_CONFIG=<?= json_encode(['api' => APP_BASE_URL.'/modules/datadict/api.php','excel' => APP_BASE_URL.'/modules/datadict/excel_export.php'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>

<?php echo view('layouts/footer', get_defined_vars());
?>
