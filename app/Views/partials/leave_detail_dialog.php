<?php
/**
 * Filename: leave_detail_dialog.php
 * Purpose: Share leave application details between the calendar and approval queue.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<dialog
    class="ws-dialog"
    id="leaveDetail"
    aria-labelledby="leaveDetailTitle"
>
    <div class="ws-dialog-header">
        <h3 id="leaveDetailTitle">Leave application</h3><button
            type="button"
            class="ws-dialog-close"
            data-leave-close="leaveDetail"
            aria-label="Close leave details"
        ><?= UiIcon('x') ?></button>
    </div>
    <div class="ws-dialog-body">
        <div
            id="leaveDetailError"
            class="ws-error"
            role="alert"
            tabindex="-1"
            hidden
        ></div>
        <dl class="wf-details" id="leaveDetailFields"></dl>
        <div
            class="form-row"
            id="leaveReviewCommentRow"
            hidden
        ><label for="leaveReviewComment">Approval / Return comment</label><textarea
                id="leaveReviewComment"
                maxlength="10000"
                rows="3"
                placeholder="A comment is required to return or reopen leave."
            ></textarea></div>
    </div>
    <div class="ws-dialog-footer" id="leaveDetailActions"></div>
</dialog>
