<?php
/**
    * Filename: updates_dialog.php
    * Purpose: Provide updates dialog processing and supporting DevAtlas workflows.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-29
    * Recent updates: Render CodeIgniter layouts and align first-party template markup.
    */
?>
<dialog
    class="updates-dialog"
    id="updatesDialog"
    aria-labelledby="updatesTitle"
    data-api="<?= APP_BASE_URL ?>/modules/updates/api.php"
>
    <div class="updates-header">
        <div><span class="updates-eyebrow">RELEASE HISTORY</span>
            <h2 id="updatesTitle">What's New</h2>
            <p>Latest changes first. Earlier updates are kept below.</p>
        </div><button
            type="button"
            class="btn"
            id="updatesClose"
            aria-label="Close What's New"
        ><?= UiIcon('x') ?></button>
    </div>
    <div class="updates-body">
        <p
            id="updatesError"
            role="alert"
            hidden
        ></p>
        <div
            class="updates-timeline"
            id="updatesHistory"
            aria-live="polite"
        >Loading update history&hellip;</div>
    </div>
    <div class="updates-footer"><span id="updatesHint">Release history is always available from Help.</span><button
            type="button"
            class="btn btn-primary"
            id="updatesDone"
        >Got it</button></div>
</dialog>
