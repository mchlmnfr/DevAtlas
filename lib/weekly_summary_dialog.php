<?php
/**
    * Filename: weekly_summary_dialog.php
    * Purpose: Provide weekly summary dialog processing and supporting DevAtlas workflows.
    * Developer: DevAtlas maintainers
    * Date: 2026-10-28
    * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
    */
?>
<dialog class="ws-dialog wide" id="weeklySummary" aria-labelledby="weeklySummaryTitle">
<div class="ws-dialog-header"><h3 id="weeklySummaryTitle">Weekly Summary</h3><button type="button" class="ws-dialog-close" data-close="weeklySummary" aria-label="Close weekly summary">&times;</button></div>
<div class="ws-dialog-body">
<form id="summaryFilters" class="dl-summary-filters"><div class="form-row"><label for="summaryWeek">Week containing</label><input type="date" class="wf-date" id="summaryWeek" min="1900-01-01" max="2100-12-31" required></div><button type="submit" class="btn btn-primary">Generate Report</button></form>
<div id="summaryError" class="ws-error" role="alert" hidden tabindex="-1"></div>
<p id="summaryStatus" role="status" aria-live="polite"></p>
<section id="summaryReport" class="dl-summary-report" aria-label="Employee weekly summary" hidden>
<div class="dl-summary-heading"><h2>WEEKLY SUMMARY</h2><h3 id="summaryEmployee"></h3></div>
<p id="summaryPeriod" class="dl-summary-period"></p>
<div class="dl-summary-scroll"><table class="dl-summary-table"><caption class="sr-only">Approved work hours by activity type and classification</caption><thead><tr><th scope="col">ACTIVITY<br>TYPE</th><th scope="col">SD (Hrs)</th><th scope="col">SCR (Hrs)</th><th scope="col">Project (Hrs)</th><th scope="col">Total Hrs</th></tr></thead><tbody id="summaryRows"></tbody><tfoot id="summaryTotals"></tfoot></table></div>
<div class="dl-summary-note"><strong>Leaves:</strong><table class="dl-summary-leaves"><thead><tr><th>Date</th><th>Leave Type</th><th>Leave Period</th><th>Hours</th></tr></thead><tbody id="summaryLeaves"></tbody></table></div>
<p class="dl-summary-note">Monday–Friday. Approved (Reviewed) work activities only. Leave, undertime and weekend entries are excluded. UT in this table means Unit Testing.</p>
</section></div>
<div class="ws-dialog-footer"><button type="button" class="btn" data-close="weeklySummary">Close</button><button type="button" class="btn btn-primary" id="summaryPrint" disabled>Print / Save PDF</button></div>
</dialog>
