<?php
/**
 * Filename: index.php
 * Purpose: Render the Logs workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div class="lg-heading">
    <div><span class="lg-eyebrow">CODEIGNITER LOGS</span>
        <h2>See what happened.<br class="lg-mobile-break"> Know what to check.</h2>
        <p class="muted">CodeIgniter framework events, with readable summaries and complete exception details.</p>
    </div>
    <div class="lg-actions"><button
            class="btn btn-primary"
            type="button"
            id="lgRefresh"
        ><?= UiIcon('refresh-cw') ?> Refresh</button><a
            class="btn"
            id="lgDownload"
            aria-disabled="true"
        >Download raw file</a>
        <details class="lg-more" id="lgMore">
            <summary class="btn">More <span aria-hidden="true"><?= UiIcon('chevron-down') ?></span></summary>
            <div class="lg-more-menu"><button type="button" id="lgTestEntry">Add diagnostic entry</button><button
                    type="button"
                    id="lgClear"
                    class="lg-danger"
                >Delete selected daily file</button></div>
        </details>
    </div>
</div>
<div
    id="lgError"
    class="lg-page-error"
    role="alert"
    hidden
></div>
<div class="lg-stats" aria-label="Filter events by severity">
    <button
        class="lg-stat active"
        id="lgStatAll"
        type="button"
        data-severity=""
        aria-pressed="true"
    ><span class="lg-stat-icon lg-tone-blue" aria-hidden="true"><?= UiIcon('file-text') ?></span><span><span class="lg-stat-label">Recorded events</span><strong id="lgCountAll">—</strong><small>In the loaded preview</small></span></button>
    <button
        class="lg-stat"
        id="lgStatErrors"
        type="button"
        data-severity="error"
        aria-pressed="false"
    ><span class="lg-stat-icon lg-tone-red" aria-hidden="true"><?= UiIcon('circle-x') ?></span><span><span class="lg-stat-label">Errors</span><strong id="lgCountErrors">—</strong><small>Includes critical errors</small></span></button>
    <button
        class="lg-stat"
        id="lgStatWarnings"
        type="button"
        data-severity="warning"
        aria-pressed="false"
    ><span class="lg-stat-icon lg-tone-amber" aria-hidden="true"><?= UiIcon('triangle-alert') ?></span><span><span class="lg-stat-label">Warnings</span><strong id="lgCountWarnings">—</strong><small>Includes PHP warnings</small></span></button>
    <button
        class="lg-stat"
        id="lgStatInformation"
        type="button"
        data-severity="info"
        aria-pressed="false"
    ><span class="lg-stat-icon lg-tone-violet" aria-hidden="true"><?= UiIcon('info') ?></span><span><span class="lg-stat-label">Information</span><strong id="lgCountInfo">—</strong><small>Routine recorded activity</small></span></button>
</div>
<section class="card lg-workspace" aria-labelledby="lgHistoryTitle">
    <div class="lg-toolbar">
        <div class="form-row lg-day-field"><label for="lgFile">Daily log</label><select id="lgFile">
                <option value="">Loading files…</option>
            </select></div>
        <div class="form-row lg-limit-field"><label for="lgLimit">Preview size</label><select id="lgLimit">
                <option value="500">Latest 500 events</option>
                <option value="2000">Latest 2,000 events</option>
            </select></div><label class="lg-check lg-auto-check"><input id="lgAutoRefresh" type="checkbox">Refresh every 30 seconds</label><span
            class="lg-refreshed"
            id="lgRefreshed"
            role="status"
            aria-live="polite"
        ></span>
    </div>
    <div class="lg-file-meta"><span id="lgFileMeta">Choose a daily log to review recorded events.</span><span>Times shown in Asia/Manila</span></div>
    <div
        id="lgPreviewNotice"
        class="lg-preview-notice"
        role="status"
        hidden
    ></div>
    <div class="lg-filter-grid">
        <div class="form-row lg-search-field"><label for="lgSearch">Search events</label><input
                id="lgSearch"
                type="search"
                placeholder="Message, page, file or technical detail"
            ></div>
        <div class="form-row"><label for="lgSeverity">Severity</label><select id="lgSeverity">
                <option value="">All severities</option>
                <option value="error">Errors &amp; critical errors</option>
                <option value="warning">Warnings</option>
                <option value="info">Information</option>
                <option value="other">Other / unrecognized</option>
            </select></div>
        <div class="form-row"><label for="lgModule">Affected area</label><select id="lgModule">
                <option value="">All areas</option>
            </select></div>
        <div class="form-row"><label for="lgSort">Sort by</label><select id="lgSort">
                <option value="newest">Most recent</option>
                <option value="oldest">Oldest first</option>
                <option value="repeated">Most repeated</option>
            </select></div>
    </div>
    <div class="lg-view-options"><label class="lg-check"><input
                id="lgGroup"
                type="checkbox"
                checked
            >Group repeated events</label><label class="lg-check"><input id="lgRepeatedOnly" type="checkbox">Repeated events only</label><button
            class="btn btn-sm"
            id="lgReset"
            type="button"
        >Reset filters</button></div>
    <div class="lg-history-heading">
        <div>
            <h2 id="lgHistoryTitle">Event history</h2>
            <p
                id="lgResultsCount"
                class="muted"
                aria-live="polite"
            >Loading events…</p>
        </div><span class="lg-history-note">Recorded history · selected daily log</span>
    </div>
    <div id="lgEvents" aria-busy="true">
        <div class="lg-empty">Loading recorded events…</div>
    </div>
    <div class="lg-pagination"><span id="lgPageLabel" class="hint"></span><button
            class="btn btn-sm"
            type="button"
            id="lgPrevious"
        >Previous</button><button
            class="btn btn-sm"
            type="button"
            id="lgNext"
        >Next</button></div>
    <p class="lg-guidance-note">Suggested checks are based on the recorded message. They help you investigate and do not confirm the cause.</p>
</section>
<script>window.LOG_CONFIG=<?= json_encode(['api' => APP_BASE_URL.'/modules/logs/api.php','download' => APP_BASE_URL.'/modules/logs/download.php'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>

<?php echo view('layouts/footer', get_defined_vars());
 ?>
