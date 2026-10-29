<?php
/**
 * Filename: history.php
 * Purpose: Render the Codereview workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="reviewHistoryApp"
    data-api="<?= APP_BASE_URL ?>/modules/codereview/history_api.php"
    data-base="<?= APP_BASE_URL ?>"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">REVIEW TRACKING</div>
            <h2>Follow findings through to a decision.</h2>
            <p>Saved reviews contain findings and file metadata. Raw source and function inventory literal values are not saved.</p>
        </div><a
            class="btn btn-primary"
            data-permission="create"
            href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/codereview/index.php')) ?>"
        >Start a review</a>
    </section>
    <div
        class="ws-error"
        id="reviewHistoryError"
        hidden
        role="alert"
        tabindex="-1"
    ></div>
    <section class="ws-card">
        <div class="wf-filters">
            <div class="form-row wf-search"><label for="reviewHistorySearch">Find a review</label><input
                    id="reviewHistorySearch"
                    type="search"
                    maxlength="200"
                    placeholder="Title, project or creator"
                ></div>
            <div class="form-row"><label for="reviewHistoryEngine">Engine</label><select id="reviewHistoryEngine">
                    <option value="">All engines</option>
                    <option value="standard">Standard diagnostics</option>
                    <option value="security">Security patterns</option>
                    <option value="qms">QMS CodeSniffer</option>
                    <option value="owasp">OWASP Top 10:2025</option>
                    <option value="inventory">Function inventory</option>
                </select></div>
            <div class="form-row"><label for="reviewHistoryApplication">Application</label><select id="reviewHistoryApplication">
                    <option value="">All applications</option>
                </select></div><label class="wf-checkbox"><input id="reviewHistoryMine" type="checkbox">Assigned to me</label>
        </div>
        <div class="ws-table-wrap">
            <table class="ws-table">
                <thead>
                    <tr>
                        <th>Review / application</th>
                        <th>Engine</th>
                        <th>Findings</th>
                        <th>Reviewer / due</th>
                        <th>State</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="reviewHistoryRows"></tbody>
            </table>
        </div>
        <div class="ws-pagination"><span id="reviewHistoryCount"></span>
            <div class="ws-actions"><button class="btn btn-sm" id="reviewHistoryPrevious">Previous</button><button class="btn btn-sm" id="reviewHistoryNext">Next</button></div>
        </div>
    </section>
</div>
<dialog
    id="reviewDetailDialog"
    class="ws-dialog wide"
    aria-labelledby="reviewDetailTitle"
>
    <div class="ws-dialog-header">
        <div>
            <h3 id="reviewDetailTitle">Saved review</h3>
            <p id="reviewDetailSubtitle"></p>
        </div><button
            class="ws-dialog-close"
            type="button"
            data-dialog-close="reviewDetailDialog"
            aria-label="Close review"
        ><?= UiIcon('x') ?></button>
    </div>
    <div class="ws-dialog-body">
        <div
            class="ws-error"
            id="reviewDetailError"
            hidden
            role="alert"
            tabindex="-1"
        ></div>
        <div id="reviewDetailMeta"></div>
        <div class="wf-dialog-actions"><a
                class="btn"
                id="reviewSavedExport"
                data-permission="export"
            >Export findings CSV</a></div>
        <details data-permission="edit">
            <summary>Review title & application</summary>
            <form id="reviewDetailsForm">
                <div class="form-row"><label for="reviewTitle">Title</label><input
                        id="reviewTitle"
                        maxlength="200"
                        required
                    ></div>
                <div class="form-row"><label for="reviewApplication">Application</label><select id="reviewApplication"></select></div><button class="btn" id="reviewDetailsSave">Save details</button>
            </form>
        </details>
        <details data-permission="review">
            <summary>Assign a reviewer</summary>
            <form id="reviewAssignForm">
                <div class="form-grid">
                    <div class="form-row"><label for="reviewAssignee">Reviewer</label><select id="reviewAssignee"></select></div>
                    <div class="form-row"><label for="reviewDue">Due date</label><input
                            class="wf-date"
                            id="reviewDue"
                            type="date"
                        ></div>
                </div><button class="btn" id="reviewAssignSave">Save assignment</button>
            </form>
        </details>
        <div class="wf-filters">
            <div class="form-row"><label for="reviewFindingStatus">Finding status</label><select id="reviewFindingStatus">
                    <option value="">All statuses</option>
                    <option>Open</option>
                    <option>In Progress</option>
                    <option>Fixed</option>
                    <option>Accepted</option>
                </select></div>
        </div>
        <div class="ws-table-wrap">
            <table class="ws-table">
                <thead>
                    <tr>
                        <th>File / line</th>
                        <th>Finding</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="reviewFindingRows"></tbody>
            </table>
        </div>
        <div class="ws-pagination"><span id="reviewFindingCount"></span>
            <div class="ws-actions"><button class="btn btn-sm" id="reviewFindingPrevious">Previous</button><button class="btn btn-sm" id="reviewFindingNext">Next</button></div>
        </div>
        <details>
            <summary>Compare with another saved run</summary>
            <div class="form-row"><label for="reviewBaseline">Baseline review ID</label><input
                    id="reviewBaseline"
                    type="number"
                    min="1"
                    placeholder="e.g. 12"
                ></div><button class="btn" id="reviewCompare">Compare runs</button>
            <div id="reviewComparison"></div>
        </details>
        <section data-permission="review">
            <h4>Reviewer notes & completion</h4>
            <p class="wf-muted">Do not paste source code, passwords or tokens. Completing a review records a human decision; it does not certify compliance.</p>
            <div class="form-row"><label for="reviewNote">Review note</label><textarea
                    id="reviewNote"
                    rows="3"
                    maxlength="2000"
                ></textarea></div>
            <div class="ws-actions"><button class="btn" id="reviewAddNote">Add note</button><button class="btn btn-primary" id="reviewComplete">Complete review</button></div>
        </section>
        <div id="reviewNotes"></div>
    </div>
    <div class="ws-dialog-footer"><button
            class="btn"
            type="button"
            data-dialog-close="reviewDetailDialog"
        >Close review</button></div>
</dialog>
<dialog
    class="ws-dialog"
    id="findingEditor"
    aria-labelledby="findingEditorTitle"
>
    <form id="findingForm">
        <div class="ws-dialog-header">
            <h3 id="findingEditorTitle">Finding decision</h3><button
                class="ws-dialog-close"
                type="button"
                data-dialog-close="findingEditor"
                aria-label="Close finding"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                id="findingError"
                class="ws-error"
                hidden
                role="alert"
                tabindex="-1"
            ></div>
            <p id="findingContext" class="wf-muted"></p>
            <div class="form-row"><label for="findingStatus">Status</label><select id="findingStatus">
                    <option>Open</option>
                    <option>In Progress</option>
                    <option>Fixed</option>
                    <option>Accepted</option>
                </select></div>
            <div class="form-row"><label for="findingNote">Decision / verification note</label><textarea
                    id="findingNote"
                    rows="4"
                    maxlength="2000"
                    placeholder="Required for Fixed or Accepted. Exclude source and secrets."
                ></textarea></div>
        </div>
        <div class="ws-dialog-footer"><button
                class="btn"
                type="button"
                data-dialog-close="findingEditor"
            >Cancel</button><button class="btn btn-primary" id="findingSave">Save decision</button></div>
    </form>
</dialog>

<?php echo view('layouts/footer', get_defined_vars());
?>
