<?php
/**
 * Filename: index.php
 * Purpose: Render the Audit workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="auditApp"
    data-api="<?= APP_BASE_URL ?>/modules/audit/api.php"
    data-export="<?= APP_BASE_URL ?>/modules/audit/export.php"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">WORKSPACE HISTORY</div>
            <h2>A record of who did what.</h2>
            <p>Review server activity, committed record changes and browser interactions across every module.</p>
        </div>
        <div class="ws-actions"><button id="auditRefresh" class="btn">Refresh history</button><a id="auditExport" class="btn btn-primary">Export filtered CSV</a></div>
    </section>
    <div
        id="auditError"
        class="ws-error"
        role="alert"
        tabindex="-1"
        hidden
    ></div>
    <div class="ws-summary">
        <div class="ws-stat"><span class="ws-stat-icon" data-icon="clock"></span><span><strong id="auditTotal">—</strong><small>Matching events</small></span></div>
        <div class="ws-stat"><span class="ws-stat-icon" data-icon="users"></span><span><strong id="auditPeople">—</strong><small>Users in this view</small></span></div>
        <div class="ws-stat"><span class="ws-stat-icon" data-icon="file"></span><span><strong id="auditChanged">—</strong><small>Events with record changes</small></span></div>
        <div class="ws-stat"><span class="ws-stat-icon" data-icon="shield"></span><span><strong id="auditAttention">—</strong><small>Failed, denied or partial</small></span></div>
    </div>
    <section class="ws-card">
        <div class="ws-card-heading">
            <div>
                <h3>Activity history</h3>
                <p>Times shown in Asia/Manila. Refresh to include events received after this view was loaded.</p>
            </div><span class="ws-tag">Read-only history</span>
        </div>
        <div class="audit-filters">
            <div class="form-row audit-search"><label for="auditSearch">Search history</label><input
                    id="auditSearch"
                    type="search"
                    maxlength="200"
                    placeholder="User, employee ID, action or record reference"
                ></div>
            <div class="form-row"><label for="auditUser">User</label><select id="auditUser">
                    <option value="">All users and guests</option>
                </select></div>
            <div class="form-row"><label for="auditModule">Module</label><select id="auditModule">
                    <option value="">All modules</option>
                </select></div>
            <div class="form-row"><label for="auditOutcome">Outcome</label><select id="auditOutcome">
                    <option value="">All outcomes</option>
                    <option value="success">Successful</option>
                    <option value="failed">Failed</option>
                    <option value="denied">Denied</option>
                    <option value="partial">Partial</option>
                    <option value="reported">Browser reported</option>
                    <option value="in_progress">Incomplete / in progress</option>
                </select></div>
            <div class="form-row"><label for="auditSource">Activity source</label><select id="auditSource">
                    <option value="">All activity</option>
                    <option value="server">Server operations</option>
                    <option value="browser">Browser interactions</option>
                </select></div>
            <div class="form-row"><label for="auditFrom">From date</label><input id="auditFrom" type="date"></div>
            <div class="form-row"><label for="auditTo">Through date</label><input id="auditTo" type="date"></div><button class="btn" id="auditReset">Clear filters</button>
        </div>
        <div class="audit-history-note"><strong>Server events</strong> show request outcomes and database changes. <strong>Browser events</strong> show reported interactions; they do not confirm that an operation succeeded.</div>
        <div class="ws-table-wrap">
            <table class="ws-table audit-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Who</th>
                        <th>Activity</th>
                        <th>Outcome</th>
                        <th>Changes</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="auditRows">
                    <tr>
                        <td colspan="6" class="ws-state">Loading history…</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="ws-pagination"><span id="auditCount" aria-live="polite"></span>
            <div class="ws-actions"><button id="auditPrevious" class="btn btn-sm">Previous</button><button id="auditNext" class="btn btn-sm">Next</button></div>
        </div>
    </section>
    <p class="ws-note">History starts when auditing is enabled. Passwords, credential values, one-time codes, reset tokens, source contents and typed search text are not recorded. Safe status values and changed field names are retained. Browser reporting can be interrupted by a closed tab or network loss.</p>
</div>
<dialog
    class="ws-dialog wide"
    id="auditDetailDialog"
    aria-labelledby="auditDetailTitle"
>
    <form method="dialog">
        <div class="ws-dialog-header">
            <div>
                <h3 id="auditDetailTitle">Activity details</h3>
                <p id="auditDetailSubtitle"></p>
            </div><button
                class="ws-dialog-close"
                value="close"
                aria-label="Close activity details"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                id="auditDetailError"
                class="ws-error"
                role="alert"
                hidden
                tabindex="-1"
            ></div>
            <div id="auditDetailBody">Loading details…</div>
            <div
                class="audit-change-pages"
                id="auditChangePages"
                hidden
            ><span id="auditChangeCount"></span><button
                    class="btn btn-sm"
                    id="auditChangesPrevious"
                    type="button"
                >Previous changes</button><button
                    class="btn btn-sm"
                    id="auditChangesNext"
                    type="button"
                >Next changes</button></div>
        </div>
        <div class="ws-dialog-footer"><button class="btn" value="close">Close details</button></div>
    </form>
</dialog>

<?php echo view('layouts/footer', get_defined_vars());
?>
