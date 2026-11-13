<?php
/**
 * Filename: index.php
 * Purpose: Render the Reports workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="reportsApp"
    data-api="<?= APP_BASE_URL ?>/modules/reports/api.php"
    data-export="<?= APP_BASE_URL ?>/modules/reports/export.php"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">TEAM MONITORING</div>
            <h2>Reports</h2>
            <p>View your section's daily logs and weekly summaries here. Expand an employee report to read its details, or export a copy to Excel.</p>
        </div><button
            type="button"
            class="btn"
            id="weeklySummaryOpen"
        >My Weekly Summary</button>
    </section>
    <div
        class="ws-error"
        id="reportsError"
        role="alert"
        tabindex="-1"
        hidden
    ></div>
    <p
        id="reportsAccessNote"
        class="ws-note"
        hidden
    >Section reports require access to your section. Your personal weekly summary is available above.</p>
    <section
        class="ws-card"
        id="reportsWorkspace"
        hidden
    >
        <form id="reportFilters" class="wf-filters">
            <div class="form-row"><label for="reportType">Report</label><select id="reportType" name="report_type">
                    <option value="weekly">Section Daily Logs / Weekly Summary</option>
                    <option value="daily">Detailed Daily Entries</option>
                </select></div>
            <div class="form-row"><label for="reportUnit">Unit / Section</label><select
                    id="reportUnit"
                    name="unit_id"
                    required
                >
                    <option value="">Choose a section</option>
                </select></div>
            <div class="form-row" data-daily-field><label for="reportFrom">Date From</label><input
                    class="wf-date"
                    type="date"
                    id="reportFrom"
                    name="date_from"
                    min="1900-01-01"
                    max="2100-12-31"
                    required
                ></div>
            <div class="form-row" data-daily-field><label for="reportTo">Date To</label><input
                    class="wf-date"
                    type="date"
                    id="reportTo"
                    name="date_to"
                    min="1900-01-01"
                    max="2100-12-31"
                    required
                ></div>
            <div class="form-row" data-daily-field><label for="reportStatus">Status</label><select id="reportStatus" name="status">
                    <option value="">All submitted, reviewed and returned</option>
                    <option value="Submitted">Submitted</option>
                    <option value="Reviewed">Reviewed / Approved</option>
                    <option value="Returned">Returned</option>
                </select></div>
            <div
                class="form-row"
                data-weekly-field
                hidden
            ><label for="reportWeek">Week containing</label><input
                    class="wf-date"
                    type="date"
                    id="reportWeek"
                    name="week_start"
                    min="1900-01-01"
                    max="2100-12-31"
                    required
                    disabled
                ></div>
            <button
                type="submit"
                class="btn btn-primary"
                id="reportGenerate"
            >Preview Report</button>

            <?php if (Permissions::Can('reports', 'export')): ?>
            <button
                type="button"
                class="btn"
                id="reportExport"
                data-permission="export"
            >Export Excel</button>

            <?php endif; ?>
        </form>
        <div class="ws-card-heading">
            <div>
                <h3 id="reportPreviewTitle">Section personnel</h3>
                <p id="reportHint">Daily Logs includes individual work and leave entries, planned tasks, actual activities, hours, remarks and review details. Private drafts are excluded.</p>
            </div><span
                id="reportCount"
                role="status"
                aria-live="polite"
            ></span>
        </div>
        <div class="ws-table-wrap">
            <table class="ws-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Report Name</th>
                        <th>Entries</th>
                        <th>Work Hours</th>
                        <th>Leave / UT Hours</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="reportRows">
                    <tr>
                        <td colspan="6">
                            <div class="ws-state">Select a section and period to preview its personnel.</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
    <section class="ws-card report-details" id="reportDetails" aria-labelledby="reportDetailsTitle" hidden>
        <div class="ws-card-heading">
            <div>
                <h3 id="reportDetailsTitle">Employee reports</h3>
                <p>Expand a name to read the full report for the selected section and period.</p>
            </div>
        </div>
        <div id="reportDetailsRows"></div>
    </section>
</div>

<?php require (ROOTPATH . 'modules/reports').'/../../lib/weekly_summary_dialog.php';
?>

<?php echo view('layouts/footer', get_defined_vars());
?>
