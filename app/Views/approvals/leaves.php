<?php
/**
 * Filename: leaves.php
 * Purpose: Render the Approvals workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="leaveApprovalsApp"
    data-api="<?= APP_BASE_URL ?>/modules/leaves/api.php"
    data-approvals="<?= APP_BASE_URL ?>/modules/approvals/index.php?tab=leaves"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">TEAM MONITORING</div>
            <h2>Approvals</h2>
            <p>Review daily work logs and leave applications in their own queues.</p>
        </div>
    </section>

    <?php echo view('partials/approval_tabs', get_defined_vars());
 ?>
    <div
        class="ws-error"
        id="leaveError"
        role="alert"
        tabindex="-1"
        hidden
    ></div>
    <section class="ws-card">
        <div class="ws-card-heading">
            <div>
                <h3>Leave applications</h3>
                <p>Pending applications in your own section across all months. A decision covers every working day in the selected date range. Only Section Heads may approve their own leave.</p>
            </div><button
                type="button"
                class="btn"
                id="leaveRefresh"
            >Refresh</button>
        </div>
        <div class="ws-table-wrap">
            <table class="ws-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Date From / To</th>
                        <th>Leave / Period</th>
                        <th>Total hours</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="leaveReviewRows"></tbody>
            </table>
        </div>
        <div class="ws-pagination"><span id="leaveReviewCount" role="status"></span>
            <div class="ws-actions"><button
                    type="button"
                    class="btn btn-sm"
                    id="leaveReviewPrevious"
                >Previous</button><button
                    type="button"
                    class="btn btn-sm"
                    id="leaveReviewNext"
                >Next</button></div>
        </div>
    </section>
</div>

<?php echo view('partials/leave_detail_dialog', get_defined_vars());
 ?>

<?php echo view('layouts/footer', get_defined_vars());
 ?>
