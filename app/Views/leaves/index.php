<?php
/**
 * Filename: index.php
 * Purpose: Render the Leaves workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="leavesApp"
    data-approvals="<?= APP_BASE_URL ?>/modules/approvals/index.php?tab=leaves"
    data-api="<?= APP_BASE_URL ?>/modules/leaves/api.php"
    data-create="<?= Permissions::Can('leaves', 'create') ? '1' : '0' ?>"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">TEAM MONITORING</div>
            <h2>Leaves</h2>
            <p>Plan around your department's leave calendar and birthdays. Submit an application here. Your section approver reviews it in the Approvals module.</p>
        </div>

        <?php if (Permissions::Can('leaves', 'create')): ?>
        <button
            type="button"
            class="btn btn-primary"
            id="leaveAdd"
        ><?= UiIcon('plus') ?> Apply for Leave</button>

        <?php endif; ?>
    </section>
    <div
        class="ws-error"
        id="leaveError"
        role="alert"
        tabindex="-1"
        hidden
    ></div>
    <section class="ws-card">
        <form class="wf-filters" id="leaveFilters">
            <div class="form-row"><label for="leaveMonth">Month</label><input
                    type="month"
                    id="leaveMonth"
                    min="1900-01"
                    max="2100-12"
                    required
                ></div>

            <?php if (Permissions::IsAdmin()): ?>
            <div class="form-row"><label for="leaveDepartment">Department</label><select id="leaveDepartment">
                    <option value="">My calendar</option>
                </select></div>

            <?php endif; ?>
            <button
                type="submit"
                class="btn"
                id="leaveRefresh"
            >Refresh</button>
        </form>
        <div class="ws-card-heading">
            <div>
                <h3 id="leaveCalendarTitle">Department leave calendar</h3>
                <p id="leaveScope" role="status"></p>
            </div>
            <div class="ws-actions"><button
                    type="button"
                    class="btn btn-sm"
                    id="leavePrevious"
                    aria-label="Previous month"
                ><?= UiIcon('chevron-left') ?></button><button
                    type="button"
                    class="btn btn-sm"
                    id="leaveToday"
                >This month</button><button
                    type="button"
                    class="btn btn-sm"
                    id="leaveNext"
                    aria-label="Next month"
                ><?= UiIcon('chevron-right') ?></button></div>
        </div>
        <div class="leave-legend"><span class="ws-tag green">Approved</span><span class="ws-tag amber">Pending approval</span><span class="ws-tag">Birthday</span><span class="wf-muted">Draft and returned requests are private. Select a request for details.</span></div>
        <div class="leave-calendar-scroll">
            <div
                id="leaveCalendar"
                class="leave-calendar"
                role="table"
                aria-labelledby="leaveCalendarTitle"
                aria-busy="true"
            ></div>
        </div>
        <p
            id="leaveCalendarCount"
            class="wf-muted"
            role="status"
            aria-live="polite"
        ></p>
    </section>
    <section class="ws-card">
        <div class="ws-card-heading">
            <div>
                <h3>My leave applications</h3>
                <p>Requests for the selected month. Submitted requests remain editable until approved.</p>
            </div>
        </div>
        <div class="ws-table-wrap">
            <table class="ws-table">
                <thead>
                    <tr>
                        <th>Date From / To</th>
                        <th>Leave / Period</th>
                        <th>Total hours</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="leaveMineRows"></tbody>
            </table>
        </div>
        <div class="ws-pagination"><span id="leaveMineCount" role="status"></span>
            <div class="ws-actions"><button
                    type="button"
                    class="btn btn-sm"
                    id="leaveMinePrevious"
                >Previous</button><button
                    type="button"
                    class="btn btn-sm"
                    id="leaveMineNext"
                >Next</button></div>
        </div>
    </section>

    <div class="ws-note">Leave and undertime apply to working days, excluding organization holidays. Half-day leave is 4 hours and whole-day leave is 8 hours. Submitted and approved leave continue to count in Team Monitoring; approved leave appears in section reports.</div>
</div>
<dialog
    class="ws-dialog"
    id="leaveEditor"
    aria-labelledby="leaveEditorTitle"
>
    <form id="leaveForm" novalidate>
        <div class="ws-dialog-header">
            <div>
                <h3 id="leaveEditorTitle">Apply for Leave</h3>
                <p id="leaveEditorHint">Your section's authorized approver will review this request.</p>
            </div><button
                type="button"
                class="ws-dialog-close"
                data-leave-close="leaveEditor"
                aria-label="Close application"
            ><?= UiIcon('x') ?></button>
        </div>
        <div class="ws-dialog-body">
            <div
                id="leaveFormError"
                class="ws-error"
                role="alert"
                tabindex="-1"
                hidden
            ></div><input type="hidden" name="log_id"><input type="hidden" name="version">
            <div class="form-grid">
                <div class="form-row"><label for="leaveDate">Date From</label><input
                        type="date"
                        id="leaveDate"
                        name="date_from"
                        min="1900-01-01"
                        max="2100-12-31"
                        required
                    ></div>
                <div class="form-row"><label for="leaveDateTo">Date To</label><input
                        type="date"
                        id="leaveDateTo"
                        name="date_to"
                        min="1900-01-01"
                        max="2100-12-31"
                        required
                    ></div>
                <div class="form-row"><label for="leaveType">Leave type</label><select
                        id="leaveType"
                        name="leave_type"
                        required
                    >

                        <?php foreach (['VL' => 'Vacation Leave','SL' => 'Sick Leave','ML' => 'Maternity Leave','EL' => 'Emergency Leave','UT' => 'Undertime'] as $code => $label): ?>
                        <option value="<?= $code ?>"><?= H($code.' — '.$label) ?></option>

                        <?php endforeach; ?>
                    </select></div>
                <div class="form-row" id="leavePeriodRow"><label for="leavePeriod">Period</label><select
                        id="leavePeriod"
                        name="leave_period"
                        required
                    >
                        <option value="WHOLE">Whole day (8 hours)</option>
                        <option value="AM">AM (4 hours)</option>
                        <option value="PM">PM (4 hours)</option>
                    </select></div>
                <div class="form-row"><label for="leaveHours">Hours per working day</label><input
                        type="number"
                        id="leaveHours"
                        name="duration_hours"
                        min="0.01"
                        max="8"
                        step="0.01"
                        required
                        readonly
                    ></div>
            </div>
            <p class="hint">Apply for up to 366 calendar days. Weekends and organization holidays are excluded from the range. One decision covers the entire application.</p>
            <div class="form-row"><label for="leaveRemarks">Reason / Remarks</label><textarea
                    id="leaveRemarks"
                    name="remarks"
                    rows="3"
                    maxlength="10000"
                    aria-describedby="leavePrivacy"
                ></textarea>
                <p class="hint" id="leavePrivacy">Only you and authorized approvers in your section can read these remarks.</p>
            </div>
        </div>
        <div class="ws-dialog-footer"><button
                type="button"
                class="btn"
                data-leave-close="leaveEditor"
            >Cancel</button><button
                type="button"
                class="btn"
                id="leaveSaveDraft"
            >Save as Draft</button><button
                type="submit"
                class="btn btn-primary"
                id="leaveSubmit"
            >Submit Leave</button></div>
    </form>
</dialog>

<?php echo view('partials/leave_detail_dialog', get_defined_vars());
 ?>

<?php echo view('layouts/footer', get_defined_vars());
 ?>
