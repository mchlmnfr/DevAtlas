<?php
/**
 * Filename: index.php
 * Purpose: Render the Dailylogs workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="dailyLogsApp"
    data-module="<?= H($dailyLogModule) ?>"
    data-api="<?= APP_BASE_URL ?>/modules/<?= H($dailyLogModule) ?>/api.php"
    data-base="<?= APP_BASE_URL ?>"
    data-review="<?= $canReview ? '1' : '0' ?>"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">TEAM MONITORING</div>
            <h2><?= H($pageTitle) ?></h2>
            <p><?= $isPersonal ? 'Record your workday. Save a draft or submit work activities for weekly review. Apply for leave in the Leaves module.' : ($isMonitor ? 'Monitor daily and weekly completion within your unit / section. Only approved logs count toward completion.' : 'Review one complete employee week at a time, or select several employee weeks.') ?></p>
        </div>

        <?php if ($isPersonal): ?>
        <button
            type="button"
            class="btn btn-primary"
            id="logAdd"
            data-permission="create"
        ><?= UiIcon('plus') ?> Add Daily Log</button>

        <?php elseif (!$isMonitor && Permissions::IsAdmin()): ?>
        <button
            type="button"
            class="btn btn-primary"
            id="sectionReviewersOpen"
        >Section Reviewers</button>

        <?php endif; ?>
    </section>

    <?php if (isset($approvalTab)): echo view('partials/approval_tabs', get_defined_vars());
 endif;
 ?>
    <div
        class="ws-error"
        id="dailyError"
        role="alert"
        tabindex="-1"
        hidden
    ></div>

    <?php if ($isMonitor): ?>
    <div class="ws-note dl-week-note">Working days are Monday–Friday excluding configured holidays, 8 hours per day. Entered hours include submitted and approved work and leave. Remaining hours are calculated per working day, so extra hours on one day do not replace missing hours on another. Private drafts and returned entries do not count.</div>

    <?php elseif (!$isPersonal): ?>
    <div class="ws-note dl-week-note">Approve or return one complete week per employee. A decision covers all submitted work entries from Monday–Sunday. New or corrected submissions reopen the whole week for approval.</div>

    <?php endif; ?>
    <section class="ws-card">
        <form id="logFilters" class="wf-filters">

            <?php if (!$isPersonal): ?>
            <div class="form-row wf-search"><label for="filterEmployee">Employee</label><input
                    type="search"
                    id="filterEmployee"
                    name="q"
                    maxlength="200"
                    placeholder="Name or employee ID"
                ></div>
            <div class="form-row"><label for="filterUnit">Unit / Section</label><select id="filterUnit" name="unit_id">
                    <option value="">All authorized units</option>
                </select></div>

            <?php endif; ?>

            <?php if ($isMonitor): ?>
            <div class="form-row"><label for="filterWeek">Week Start</label><input
                    class="wf-date"
                    type="date"
                    id="filterWeek"
                    name="week_start"
                    required
                ></div>
            <div class="form-row"><label>Week Covered</label>
                <p id="weekCovered" aria-live="polite">&mdash;</p>
            </div>

            <?php else: ?>
            <div class="form-row"><label for="filterStatus">Status</label><select id="filterStatus" name="status">
                    <option value="">All statuses</option>

                    <?php foreach (['Draft','Submitted','Reviewed','Returned'] as $status): if (!$isPersonal && $status === 'Draft')
{
    continue;
}
    ?>
                    <option <?= !$isPersonal && $status === 'Submitted' ? 'selected' : '' ?>><?= $status ?></option>

                    <?php endforeach; ?>
                </select></div>
            <div class="form-row"><label for="filterFrom">Date From</label><input
                    class="wf-date"
                    type="date"
                    id="filterFrom"
                    name="date_from"
                ></div>
            <div class="form-row"><label for="filterTo">Date To</label><input
                    class="wf-date"
                    type="date"
                    id="filterTo"
                    name="date_to"
                ></div>

            <?php endif; ?>
            <button
                type="submit"
                class="btn btn-primary"
                id="logFilter"
            >Filter</button><button
                type="button"
                class="btn"
                id="logReset"
            >Reset</button>
        </form>
        <div class="ws-card-heading">
            <div>
                <h3><?= $isPersonal ? 'My Daily Activity Logs' : ($isMonitor ? 'Team members' : 'Employee weeks') ?></h3>
                <p id="logListHint"><?= $isPersonal ? 'Draft, submitted and returned entries can be edited. Submitted changes remain pending weekly approval; reviewed entries are locked.' : ($isMonitor ? 'Daily cells show approved work hours and approved leave / undertime.' : 'One decision covers the full employee week. Return a week with a comment when corrections are needed. Section Head and Department Head roles may review their own weeks; other roles require another reviewer.') ?></p>
            </div>
            <div class="ws-actions"><button
                    type="button"
                    class="btn"
                    id="logRefresh"
                >Refresh</button></div>
        </div>

        <?php if (!$isPersonal && !$isMonitor && $canReview): ?>
        <div class="dl-bulk"><label class="wf-checkbox"><input type="checkbox" id="logSelectAll"> Select submitted employee weeks on this page</label><span id="logSelected" aria-live="polite">0 selected</span><label for="bulkComment" class="sr-only">Review comment</label><input
                type="text"
                id="bulkComment"
                maxlength="10000"
                placeholder="Review comment (required to return)"
            ><button
                type="button"
                class="btn btn-primary"
                id="logBulkApprove"
                disabled
            >Approve selected weeks</button><button
                type="button"
                class="btn"
                id="logBulkReturn"
                disabled
            >Return selected weeks</button></div>

        <?php endif; ?>
        <div class="ws-table-wrap">
            <table class="ws-table dl-table">
                <thead id="logHead"></thead>
                <tbody id="logRows">
                    <tr>
                        <td>
                            <div class="ws-state" role="status">Loading&hellip;</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="ws-pagination"><span
                id="logCount"
                role="status"
                aria-live="polite"
            ></span>
            <div class="ws-actions"><button
                    type="button"
                    id="logPrevious"
                    class="btn btn-sm"
                    disabled
                >Previous</button><button
                    type="button"
                    id="logNext"
                    class="btn btn-sm"
                    disabled
                >Next</button></div>
        </div>
    </section>
</div>

<?php if ($isPersonal): ?>
<dialog
    class="ws-dialog wide"
    id="logEditor"
    aria-labelledby="logEditorTitle"
>
    <form id="logForm" novalidate>
        <div class="ws-dialog-header">
            <div>
                <h3 id="logEditorTitle">Add Daily Log</h3>
                <p id="logEditorHint">Keep a draft until you are ready to submit for review.</p>
            </div><button
                type="button"
                class="ws-dialog-close"
                data-close="logEditor"
                aria-label="Close editor"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                id="logFormError"
                class="ws-error"
                role="alert"
                hidden
                tabindex="-1"
            ></div><input type="hidden" name="log_id"><input type="hidden" name="version">
            <div class="form-grid">
                <div class="form-row"><label for="entryDate">Activity Date</label><input
                        class="wf-date"
                        type="date"
                        id="entryDate"
                        name="activity_date"
                        required
                    >
                    <div
                        class="hint"
                        id="workingDayHint"
                        role="status"
                    ></div>
                </div>
            </div>
            <fieldset id="activityFields" class="dl-fieldset">
                <legend class="sr-only">Work activity</legend>
                <div class="form-grid">
                    <div class="form-row"><label for="entryClass">Classification</label><select
                            id="entryClass"
                            name="classification"
                            required
                        ></select></div>
                    <div class="form-row"><label for="entryActivity">Activity Type</label><select
                            id="entryActivity"
                            name="activity_type"
                            aria-describedby="entryActivityHelp"
                            required
                        ></select>
                        <div
                            id="entryActivityHelp"
                            class="dl-activity-help"
                            role="status"
                            aria-live="polite"
                            aria-atomic="true"
                        ><strong id="entryActivityHelpTitle">Activity guide</strong>
                            <p id="entryActivityHelpText">Choose an activity type to see its description.</p>
                        </div>
                    </div>
                    <div class="form-row dl-full"><label for="entryPlanned">Projected Activity / Planned Task</label><textarea
                            id="entryPlanned"
                            name="planned_task"
                            rows="3"
                            maxlength="10000"
                            required
                        ></textarea></div>
                    <div class="form-row dl-full"><label for="entryActual">Actual Activity Performed</label><textarea
                            id="entryActual"
                            name="actual_activity"
                            rows="3"
                            maxlength="10000"
                            required
                        ></textarea></div>
                </div>
            </fieldset>
            <div class="form-row"><label for="entryHours" id="hoursLabel">Duration in Hours</label><input
                    type="number"
                    id="entryHours"
                    name="duration_hours"
                    min="0.01"
                    max="24"
                    step="0.01"
                    required
                >
                <div class="hint" id="hoursHint">Use decimal hours, for example 0.25 for 15 minutes.</div>
            </div>
            <div class="form-row"><label for="entryRemarks">Remarks</label><textarea
                    id="entryRemarks"
                    name="remarks"
                    rows="3"
                    maxlength="10000"
                ></textarea></div>
        </div>
        <div class="ws-dialog-footer"><button
                type="button"
                class="btn"
                data-close="logEditor"
            >Cancel</button><button
                type="button"
                class="btn"
                id="logSaveDraft"
            >Save as Draft</button><button
                type="submit"
                class="btn btn-primary"
                id="logSubmit"
            >Submit</button></div>
    </form>
</dialog>

<?php endif; ?>
<dialog
    class="ws-dialog wide"
    id="logDetail"
    aria-labelledby="logDetailTitle"
>
    <div class="ws-dialog-header">
        <div>
            <h3 id="logDetailTitle">Daily log details</h3>
            <p id="detailSubtitle"></p>
        </div><button
            type="button"
            class="ws-dialog-close"
            data-close="logDetail"
            aria-label="Close details"
        ><?= UiIcon('x') ?></button>
    </div>
    <div class="ws-dialog-body">
        <div
            class="ws-error"
            id="detailError"
            role="alert"
            hidden
            tabindex="-1"
        ></div>
        <dl class="wf-details" id="detailFields"></dl>
    </div>
    <div class="ws-dialog-footer" id="detailActions"></div>
</dialog>

<?php if ($isMonitor): ?>
<dialog
    class="ws-dialog wide"
    id="memberLogs"
    aria-labelledby="memberLogsTitle"
>
    <div class="ws-dialog-header">
        <h3 id="memberLogsTitle">Team member logs</h3><button
            type="button"
            class="ws-dialog-close"
            data-close="memberLogs"
            aria-label="Close member logs"
        ><?= UiIcon('x') ?></button>
    </div>
    <div class="ws-dialog-body">
        <p class="wf-muted">Submitted, reviewed and returned logs for the selected week.</p>
        <div id="memberLogRows"></div>
        <div class="ws-pagination"><span id="memberLogCount"></span>
            <div class="ws-actions"><button
                    type="button"
                    class="btn"
                    id="memberPrevious"
                >Previous</button><button
                    type="button"
                    class="btn"
                    id="memberNext"
                >Next</button></div>
        </div>
    </div>
</dialog>

<?php endif; ?>

<?php if (!$isPersonal && !$isMonitor && Permissions::IsAdmin()): ?>
<dialog
    class="ws-dialog wide"
    id="sectionReviewers"
    aria-labelledby="sectionReviewersTitle"
>
    <div class="ws-dialog-header">
        <div>
            <h3 id="sectionReviewersTitle">Section Reviewers</h3>
            <p>Assign one reviewer per unit / section. The assigned reviewer and administrators can approve or return this section's logs. Reviewers must belong to the section.</p>
        </div><button
            type="button"
            class="ws-dialog-close"
            data-close="sectionReviewers"
            aria-label="Close section reviewers"
        ><?= UiIcon('x') ?></button>
    </div>
    <div class="ws-dialog-body">
        <div
            id="sectionReviewersError"
            class="ws-error"
            role="alert"
            hidden
            tabindex="-1"
        ></div>
        <p class="wf-muted">Choose an active user with Review Daily Logs view and review permissions. Units without an assigned reviewer can only be reviewed by an administrator. Section Head and Department Head roles may approve their own weeks. Other roles cannot approve their own logs.</p>
        <div id="sectionReviewerRows"></div>
        <p
            id="sectionReviewersStatus"
            role="status"
            aria-live="polite"
        ></p>
    </div>
    <div class="ws-dialog-footer"><button
            type="button"
            class="btn"
            id="sectionReviewersRefresh"
        >Refresh</button><button
            type="button"
            class="btn"
            data-close="sectionReviewers"
        >Close</button></div>
</dialog>

<?php endif; ?>

<?php if (!$isPersonal && !$isMonitor): ?>
<dialog
    class="ws-dialog wide"
    id="weekReview"
    aria-labelledby="weekReviewTitle"
>
    <div class="ws-dialog-header">
        <div>
            <h3 id="weekReviewTitle">Employee week</h3>
            <p id="weekReviewSubtitle"></p>
        </div><button
            type="button"
            class="ws-dialog-close"
            data-close="weekReview"
            aria-label="Close employee week"
        ><?= UiIcon('x') ?></button>
    </div>
    <div class="ws-dialog-body">
        <div
            class="ws-error"
            id="weekReviewError"
            role="alert"
            hidden
            tabindex="-1"
        ></div>
        <p id="weekReviewPrevious" class="wf-muted"></p>
        <div id="weekReviewRows"></div>
        <div class="form-row" id="weekReviewCommentRow"><label for="weekReviewComment">Weekly review comment</label><textarea
                id="weekReviewComment"
                rows="3"
                maxlength="10000"
                placeholder="Required when returning or reopening a week"
            ></textarea></div>
    </div>
    <div class="ws-dialog-footer"><button
            type="button"
            class="btn"
            data-close="weekReview"
        >Close</button><button
            type="button"
            class="btn"
            id="weekReturn"
        >Return / Reopen Week</button><button
            type="button"
            class="btn btn-primary"
            id="weekApprove"
        >Approve Week</button></div>
</dialog>

<?php endif; ?>

<?php echo view('layouts/footer', get_defined_vars());
?>
