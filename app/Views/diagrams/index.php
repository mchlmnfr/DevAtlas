<?php
/**
 * Filename: index.php
 * Purpose: Render the Diagrams workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="erdApp"
    data-api="<?= H(APP_BASE_URL.'/modules/diagrams/api.php') ?>"
    data-base="<?= H(APP_BASE_URL.'/modules/diagrams/') ?>"
>
    <div class="ws-hero">
        <div>
            <div class="ws-eyebrow">DATA MODELING</div>
            <h2>From dictionary to diagram.</h2>
            <p>Select documented tables, bring their columns and foreign keys together, and refine the model on an interactive canvas.</p>
        </div>
        <div class="ws-actions"><a class="btn" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/datadict/index.php')) ?>">Data Dictionary</a><button class="btn btn-primary" id="erdGenerateOpen">Generate ERD</button></div>
    </div>
    <div
        class="ws-error"
        id="erdError"
        role="alert"
        hidden
        tabindex="-1"
    ></div>
    <section id="erdLibrary" class="ws-card">
        <div class="ws-card-heading">
            <div>
                <h3>Saved ERDs</h3>
                <p>Open a model to arrange tables, review relationships or export.</p>
            </div><button class="btn btn-sm" id="erdBlank">Create blank ERD</button>
        </div>
        <div class="ws-toolbar">
            <div class="form-row ws-search"><label for="erdSearch">Find a model</label><input
                    id="erdSearch"
                    type="search"
                    placeholder="Search name or description"
                ></div><span
                class="hint"
                id="erdLibraryCount"
                aria-live="polite"
            ></span>
        </div>
        <div id="erdCards" class="erd-cards">
            <div class="ws-state">Loading ERDs…</div>
        </div>
    </section>
    <section id="erdEditor" hidden>
        <div class="ws-card">
            <div class="ws-card-heading">
                <div><button class="ws-link-button" id="erdBack"><?= UiIcon('arrow-left') ?> Saved ERDs</button>
                    <h3 id="erdTitle"></h3>
                    <p id="erdSubtitle"></p>
                </div>
                <div class="ws-actions"><button class="btn btn-sm" id="erdRename">Edit name</button><button class="btn btn-sm btn-danger" id="erdDelete">Delete ERD</button></div>
            </div>
            <div class="erd-model-bar">
                <div id="erdStats" class="ws-actions"></div>
                <div class="ws-actions"><a id="erdGraphml" class="btn btn-sm">GraphML</a><a id="erdExcel" class="btn btn-sm">Excel</a><button id="erdSvgExport" class="btn btn-sm">SVG image</button><button id="erdImport" class="btn btn-sm">Import Excel</button><input
                        id="erdImportFile"
                        type="file"
                        accept=".xlsx"
                        hidden
                    ></div>
            </div>
            <details class="erd-notes" id="erdNotes">
                <summary id="erdNotesTitle">Generation notes</summary>
                <div id="erdNotesBody"></div>
            </details>
            <div class="erd-canvas-toolbar">
                <div class="ws-actions"><button class="btn btn-sm" id="erdAddTable"><?= UiIcon('plus') ?> Table</button><button class="btn btn-sm" id="erdAddEdge"><?= UiIcon('plus') ?> Relationship</button><button class="btn btn-sm" id="erdArrange">Auto arrange</button><button
                        class="btn btn-sm btn-primary"
                        id="erdSaveLayout"
                        disabled
                    >Layout saved</button></div>
                <div class="ws-actions"><button
                        class="btn btn-sm"
                        id="erdZoomOut"
                        aria-label="Zoom out"
                    ><?= UiIcon('minus') ?></button><span id="erdZoomLabel">100%</span><button
                        class="btn btn-sm"
                        id="erdZoomIn"
                        aria-label="Zoom in"
                    ><?= UiIcon('plus') ?> </button><button class="btn btn-sm" id="erdFit">Fit</button></div>
            </div>
            <div
                class="erd-canvas"
                id="erdViewport"
                tabindex="0"
                aria-label="ERD canvas. Scroll to pan. Select a table to edit. Drag its header or use arrow keys to move."
            ><svg
                    id="erdSvg"
                    xmlns="http://www.w3.org/2000/svg"
                    role="img"
                    aria-label="Entity relationship diagram"
                ></svg></div>
            <div class="erd-canvas-caption"><span>Drag table headers to arrange · Select a table to edit · Arrow keys move the selected table</span><span><b>PK</b> Primary key &nbsp; <b>FK</b> Foreign key &nbsp; <b>*</b> Required column</span></div>
            <div
                class="erd-inspector"
                id="erdInspector"
                hidden
            >
                <div><strong id="erdSelectedName"></strong>
                    <p id="erdSelectedDescription"></p>
                </div>
                <div class="ws-actions"><button class="btn btn-sm" id="erdEditTable">Edit table</button><button class="btn btn-sm btn-danger" id="erdRemoveTable">Remove table</button></div>
            </div>
        </div>
        <section class="ws-card">
            <div class="ws-card-heading">
                <div>
                    <h3>Relationships</h3>
                    <p>Source is the referencing table; target is the referenced table. Select a relationship to adjust its cardinality.</p>
                </div><span class="ws-tag" id="erdEdgeCount"></span>
            </div>
            <div class="ws-table-wrap">
                <table class="ws-table erd-edges">
                    <thead>
                        <tr>
                            <th>Source table / column</th>
                            <th>Target table / column</th>
                            <th>Cardinality</th>
                            <th>Origin</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="erdEdges"></tbody>
                </table>
            </div>
        </section>
    </section>
</div>
<dialog class="ws-dialog wide" id="erdGenerateDialog">
    <form id="erdGenerateForm">
        <div class="ws-dialog-header">
            <div>
                <h3>Generate from Data Dictionary</h3>
                <p>Columns, primary keys and documented foreign keys are included.</p>
            </div><button
                type="button"
                class="ws-dialog-close"
                data-dialog-close="erdGenerateDialog"
                aria-label="Close"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                class="ws-error"
                id="erdGenerateError"
                role="alert"
                hidden
                tabindex="-1"
            ></div>
            <div class="form-row"><label for="erdName">ERD name</label><input
                    id="erdName"
                    required
                    maxlength="200"
                    placeholder="e.g. Customer & order model"
                ></div>
            <div class="form-grid">
                <div class="form-row"><label for="erdDatabase">Database group</label><select id="erdDatabase">
                        <option value="">All database groups</option>
                    </select></div>
                <div class="form-row"><label for="erdTableSearch">Find table or schema</label><input
                        id="erdTableSearch"
                        type="search"
                        placeholder="Search documented tables"
                    ></div>
            </div>
            <div class="erd-selection-bar"><span id="erdSelectionCount" aria-live="polite">0 tables selected</span>
                <div class="ws-actions"><button
                        class="ws-link-button"
                        type="button"
                        id="erdSelectVisible"
                    >Select visible</button><button
                        class="ws-link-button"
                        type="button"
                        id="erdClear"
                    >Clear</button></div>
            </div>
            <div class="erd-table-picker" id="erdTablePicker">Loading dictionary…</div>
            <label class="erd-check"><input
                    id="erdRelated"
                    type="checkbox"
                    checked
                > Include referenced tables automatically</label>
            <p class="hint">Up to 50 tables and 5,000 columns per ERD, including referenced tables. Inactive tables are marked. No live database connection or record data is needed.</p>
            <p class="ws-note">Cardinality is inferred from nullability and single-column primary keys. Verify unique constraints and composite foreign keys in the source database.</p>
        </div>
        <div class="ws-dialog-footer"><button
                type="button"
                class="btn"
                data-dialog-close="erdGenerateDialog"
            >Cancel</button><button
                type="submit"
                class="btn btn-primary"
                id="erdGenerateSubmit"
            >Generate ERD</button></div>
    </form>
</dialog>
<dialog class="ws-dialog" id="erdNameDialog">
    <form id="erdNameForm">
        <div class="ws-dialog-header">
            <div>
                <h3 id="erdNameDialogTitle">Create ERD</h3>
                <p>Use a descriptive name for this model.</p>
            </div><button
                type="button"
                class="ws-dialog-close"
                data-dialog-close="erdNameDialog"
                aria-label="Close"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                class="ws-error"
                id="erdNameError"
                role="alert"
                hidden
                tabindex="-1"
            ></div>
            <div class="form-row"><label for="erdEditName">Name</label><input
                    id="erdEditName"
                    required
                    maxlength="200"
                ></div>
            <div class="form-row"><label for="erdEditDescription">Description</label><textarea
                    id="erdEditDescription"
                    rows="3"
                    maxlength="5000"
                ></textarea></div>
        </div>
        <div class="ws-dialog-footer"><button
                type="button"
                class="btn"
                data-dialog-close="erdNameDialog"
            >Cancel</button><button class="btn btn-primary" id="erdNameSubmit">Save ERD</button></div>
    </form>
</dialog>
<dialog class="ws-dialog wide" id="erdEntityDialog">
    <form id="erdEntityForm">
        <div class="ws-dialog-header">
            <div>
                <h3 id="erdEntityTitle">Edit table</h3>
                <p>Changes affect this ERD snapshot only. Removing columns also removes their relationships.</p>
            </div><button
                type="button"
                class="ws-dialog-close"
                data-dialog-close="erdEntityDialog"
                aria-label="Close"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                class="ws-error"
                id="erdEntityError"
                role="alert"
                hidden
                tabindex="-1"
            ></div>
            <div class="form-row"><label for="erdEntityName">Table name</label><input
                    id="erdEntityName"
                    maxlength="255"
                    required
                ></div>
            <div class="form-row"><label for="erdEntityDescription">Description</label><textarea
                    id="erdEntityDescription"
                    rows="2"
                    maxlength="5000"
                ></textarea></div>
            <div class="erd-selection-bar"><strong>Columns</strong><button
                    class="btn btn-sm"
                    type="button"
                    id="erdColumnAdd"
                ><?= UiIcon('plus') ?> Column</button></div>
            <div id="erdEntityColumns"></div>
        </div>
        <div class="ws-dialog-footer"><button
                type="button"
                class="btn"
                data-dialog-close="erdEntityDialog"
            >Cancel</button><button class="btn btn-primary" id="erdEntitySubmit">Save table</button></div>
    </form>
</dialog>
<dialog class="ws-dialog" id="erdEdgeDialog">
    <form id="erdEdgeForm">
        <div class="ws-dialog-header">
            <div>
                <h3>Relationship</h3>
                <p>Connect referencing and referenced tables.</p>
            </div><button
                type="button"
                class="ws-dialog-close"
                data-dialog-close="erdEdgeDialog"
                aria-label="Close"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                class="ws-error"
                id="erdEdgeError"
                role="alert"
                hidden
                tabindex="-1"
            ></div>
            <div class="form-grid">
                <div class="form-row"><label for="erdEdgeSource">Source table</label><select id="erdEdgeSource" required></select></div>
                <div class="form-row"><label for="erdEdgeTarget">Target table</label><select id="erdEdgeTarget" required></select></div>
                <div class="form-row"><label for="erdEdgeSourceColumn">Source column</label><select id="erdEdgeSourceColumn"></select></div>
                <div class="form-row"><label for="erdEdgeTargetColumn">Target column</label><select id="erdEdgeTargetColumn"></select></div>
            </div>
            <div class="form-row"><label for="erdEdgeCardinality">Source : target cardinality</label><select id="erdEdgeCardinality">
                    <option value="0..N:1">Zero or many : one</option>
                    <option value="0..N:0..1">Zero or many : zero or one</option>
                    <option value="0..1:1">Zero or one : one</option>
                    <option value="0..1:0..1">Zero or one : zero or one</option>
                    <option value="1:1">One : one</option>
                    <option value="1:N">One : many</option>
                    <option value="N:1">Many : one</option>
                    <option value="N:M">Many : many</option>
                </select></div>
            <div class="form-row"><label for="erdEdgeLabel">Label</label><input id="erdEdgeLabel" maxlength="255"></div>
        </div>
        <div class="ws-dialog-footer"><button
                type="button"
                class="btn"
                data-dialog-close="erdEdgeDialog"
            >Cancel</button><button class="btn btn-primary" id="erdEdgeSubmit">Save relationship</button></div>
    </form>
</dialog>

<?php echo view('layouts/footer', get_defined_vars());
?>
