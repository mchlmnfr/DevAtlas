<?php
/** Data Management / Data Conversion workspace. */
$canCreate = Permissions::Can('dataconversion', 'create');
$canExport = Permissions::Can('dataconversion', 'export');
?>
<?= view('layouts/header', get_defined_vars()) ?>
<div class="ws-workspace dc-workspace" id="conversionApp"
     data-api="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/dataconversion/api.php')) ?>"
     data-can-create="<?= $canCreate ? '1' : '0' ?>" data-can-export="<?= $canExport ? '1' : '0' ?>">
    <section class="ws-hero dc-hero">
        <div>
            <div class="ws-eyebrow">DATA MANAGEMENT / DATA CONVERSION</div>
            <h2>From source file to SQL.</h2>
            <p>Validate your data, inspect the records and download a script ready for SQL Server.</p>
        </div>
        <span class="ws-tag">CSV / XLSX → SQL</span>
    </section>
    <ol class="dc-steps" aria-label="Conversion steps">
        <li><span>01</span> Upload source</li><li><span>02</span> Validate &amp; preview</li><li><span>03</span> Download SQL</li>
    </ol>
    <div id="conversionMessage" class="dc-message" role="alert" hidden></div>
    <div class="dc-layout">
        <section class="ws-card dc-source-card">
            <div class="ws-card-heading"><div><h3>Source &amp; destination</h3><p>Choose the conversion that matches your file.</p></div></div>
            <div class="ws-card-body">
                <?php if (!$canCreate): ?><p class="dc-access-note">Your role has view access. Ask an Administrator for Create access to upload and validate files.</p><?php endif; ?>
                <form id="conversionForm" enctype="multipart/form-data">
                    <fieldset id="conversionFields" class="dc-fields" <?= $canCreate ? '' : 'disabled' ?>>
                        <legend class="sr-only">Conversion options</legend>
                        <div class="dc-mode-options">
                            <label class="dc-mode"><input type="radio" name="mode" value="consol" checked><span><strong>Report consolidation</strong><small>CSV or Excel → central_db.dbo.consol_data</small></span></label>
                            <label class="dc-mode"><input type="radio" name="mode" value="generic"><span><strong>Existing SQL Server table</strong><small>CSV → your chosen schema.table</small></span></label>
                        </div>
                        <div class="form-row"><label for="conversionSource">Source file</label>
                            <input id="conversionSource" name="source" type="file" accept=".csv,.xlsx" required>
                            <p class="hint">UTF-8 CSV or .xlsx. Current server upload limit: <?= H((string)$uploadLimit) ?> MB per file (module maximum: 25 MB).</p>
                        </div>
                        <div class="form-row"><label for="conversionDelimiter">CSV delimiter</label>
                            <select name="delimiter" id="conversionDelimiter"><option value=",">Comma (,)</option><option value=";">Semicolon (;)</option><option value="tab">Tab</option><option value="|">Pipe (|)</option></select>
                            <p class="hint">Used for CSV files. Excel worksheets are read directly.</p>
                        </div>
                        <div id="conversionGeneric" hidden>
                            <div class="form-row"><label for="conversionTable">Destination table</label><input id="conversionTable" name="table" value="dbo.ReportData" maxlength="257" placeholder="dbo.ReportData"><p class="hint">Run the generated script in the database containing this table.</p></div>
                            <div class="form-row"><label for="conversionSchema">Schema snapshot <span class="dc-optional">optional</span></label><input id="conversionSchema" name="schema" type="file" accept=".csv"><p class="hint">CSV headers: TableSchema, TableName, ColumnName, IsIdentity, IsComputed.</p></div>
                        </div>
                        <p id="conversionReportHelp" class="dc-inline-note">Required: ReportCode and ReportName. TableName is derived from ReportCode; InsertedAt uses the database default.</p>
                        <button class="btn btn-primary dc-validate" type="submit" id="conversionValidate">Validate &amp; preview</button>
                    </fieldset>
                </form>
                <div class="dc-resources">
                    <span>STARTER FILES</span>
                    <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/dataconversion/api.php?action=template')) ?>">Download report CSV template</a>
                    <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/dataconversion/api.php?action=schema_query')) ?>">Download schema export query</a>
                </div>
            </div>
        </section>
        <section class="ws-card dc-preview-card" aria-labelledby="conversionPreviewTitle" aria-busy="false" id="conversionPreviewPanel">
            <div class="ws-card-heading"><div><h3 id="conversionPreviewTitle">Validation &amp; preview</h3><p>Check the destination and records before downloading.</p></div><span id="conversionReadyBadge" class="dc-ready" hidden>Validated</span></div>
            <div class="ws-card-body">
                <div id="conversionEmpty" class="dc-empty"><div class="dc-empty-symbol" aria-hidden="true">↗</div><h3>Your preview starts here</h3><p>Upload a source file to check its headers and records. The first five validated rows will appear here.</p></div>
                <div id="conversionResult" hidden>
                    <div class="dc-summary"><div><small>VALIDATED ROWS</small><strong id="conversionRows"></strong></div><div><small>OUTPUT COLUMNS</small><strong id="conversionColumns"></strong></div><div class="dc-destination"><small>DESTINATION</small><strong id="conversionDestination"></strong></div></div>
                    <div class="dc-file-summary"><span id="conversionFilename"></span><button type="button" class="btn btn-sm" id="conversionClear" <?= $canCreate ? '' : 'disabled' ?>>Clear upload</button></div>
                    <div class="form-row dc-sheet-row" id="conversionSheetRow" hidden><label for="conversionSheet">Report worksheets</label><select id="conversionSheet" <?= $canCreate ? '' : 'disabled' ?>></select><p class="hint">“All report worksheets” excludes Summary Count Per Dept.</p></div>
                    <div class="dc-preview-caption"><strong>Record preview</strong><span id="conversionPreviewCount"></span></div>
                    <div class="dc-table-scroll" tabindex="0" role="region" aria-label="Validated source records"><table class="dc-table"><thead id="conversionTableHead"></thead><tbody id="conversionTableBody"></tbody></table></div>
                    <p class="hint">Long values are shortened in the preview. The SQL download keeps the complete source values.</p>
                    <div id="conversionNotes" class="dc-notes"></div>
                    <div class="dc-download-row"><div><strong>Ready for SQL Server</strong><p id="conversionExpiry"></p></div><button type="button" class="btn btn-primary" id="conversionDownload" <?= $canExport ? '' : 'disabled' ?>>Download SQL</button></div>
                    <?php if (!$canExport): ?><p class="dc-access-note">Export access is required to download the generated SQL.</p><?php endif; ?>
                </div>
            </div>
        </section>
    </div>
    <p class="dc-footer-note">Scripts are generated for you to review and run in SQL Server. DevAtlas does not connect to the destination database. Uploads are private to your session; previews expire after one hour.</p>
</div>
<?= view('layouts/footer', get_defined_vars()) ?>
