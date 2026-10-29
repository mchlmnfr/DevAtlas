<?php
/**
 * Filename: index.php
 * Purpose: Render the Codereview workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="crApp"
    data-api="<?= APP_BASE_URL ?>/modules/codereview/api.php"
    data-export="<?= APP_BASE_URL ?>/modules/codereview/export.php"
    data-max-files="<?= CodeReview::MAX_FILES ?>"
    data-max-file-bytes="<?= CodeReview::MAX_FILE_BYTES ?>"
    data-max-total-bytes="<?= CodeReview::MAX_TOTAL_BYTES ?>"
    data-max-request-bytes="<?= CodeReview::MAX_REQUEST_BYTES ?>"
>
    <div class="ws-hero">
        <div>
            <div class="ws-eyebrow">SOURCE QUALITY</div>
            <h2>A clearer view of your code.</h2>
            <p>Choose a review engine, scan a project, and work through findings with file and line references.</p>
        </div>
        <div class="ws-actions"><a class="btn" href="history.php">Saved reviews</a><span class="ws-tag">Five review engines</span></div>
    </div>
    <div
        class="ws-error"
        id="crError"
        hidden
        role="alert"
        tabindex="-1"
    ></div>
    <section class="ws-card">
        <div class="ws-card-heading">
            <div>
                <h3>Configure a review</h3>
                <p>Findings and metadata are saved automatically. Raw source is not retained.</p>
            </div><span class="ws-tag gray" id="crEngineLabel">Standard diagnostics</span>
        </div>
        <fieldset id="crConfig" class="cr-config">
            <legend class="sr-only">Review configuration</legend>
            <section class="cr-source-section" aria-labelledby="crSourceTitle">
                <div class="cr-source-heading">
                    <div class="cr-step"><span>01</span>
                        <div>
                            <h4 id="crSourceTitle">Choose your source</h4>
                            <p>Pick a local folder or an application directory on the server.</p>
                        </div>
                    </div>
                </div>
                <div class="cr-source-body">
                    <div
                        class="cr-source-choices"
                        role="group"
                        aria-label="Review source"
                    >
                        <button
                            class="cr-source-choice"
                            id="crClientBtn"
                            type="button"
                            onclick="CrSetMode('client')"
                            aria-pressed="true"
                            aria-controls="crClientFields"
                        ><span class="cr-source-choice-icon" data-icon="folder"></span><span class="cr-source-choice-text"><strong>This computer</strong><small>Choose a folder with your browser</small></span><span
                                class="cr-source-selected"
                                data-icon="check"
                                aria-hidden="true"
                            ></span></button>
                        <button
                            class="cr-source-choice"
                            id="crServerBtn"
                            type="button"
                            onclick="CrSetMode('server')"
                            aria-pressed="false"
                            aria-controls="crServerFields"
                        ><span class="cr-source-choice-icon" data-icon="grid"></span><span class="cr-source-choice-text"><strong>Application server</strong><small>Select a folder on the host server</small></span><span
                                class="cr-source-selected"
                                data-icon="check"
                                aria-hidden="true"
                            ></span></button>
                    </div>
                    <div
                        id="crClientFields"
                        class="cr-folder-picker"
                        aria-labelledby="crFolderTitle"
                    >
                        <span
                            class="cr-folder-icon"
                            data-icon="folder"
                            aria-hidden="true"
                        ></span>
                        <strong id="crFolderTitle">Choose a project folder</strong>
                        <p
                            id="crFolderInfo"
                            aria-live="polite"
                            aria-atomic="true"
                        >Browse folders on this computer</p>
                        <button
                            class="btn btn-sm"
                            id="crChooseFolder"
                            type="button"
                        >Choose folder</button>
                        <input
                            id="crFolder"
                            type="file"
                            webkitdirectory
                            directory
                            multiple
                            aria-label="Choose a project folder"
                            hidden
                        >
                    </div>
                    <div
                        id="crServerFields"
                        class="cr-folder-picker cr-server-picker"
                        aria-labelledby="crServerTitle"
                        hidden
                    >
                        <span
                            class="cr-folder-icon"
                            data-icon="grid"
                            aria-hidden="true"
                        ></span>
                        <strong id="crServerTitle">Choose an application directory</strong>
                        <p>Browse project folders under this server’s web root.</p>
                        <div class="cr-server-directory"><label class="sr-only" for="crProject">Application directory</label><select id="crProject">
                                <option value="">Loading server folders…</option>
                            </select></div>
                    </div>
                    <p class="cr-source-notice" id="crSourceNotice">Source file contents are sent to this server when you select Review files. Findings and metadata are saved; raw source is not retained.</p>
                </div>
            </section>
            <div class="cr-section">
                <div class="cr-step"><span>02</span>
                    <div>
                        <h4>Choose a review engine</h4>
                        <p>Each engine provides a different view of the same project.</p>
                    </div>
                </div>
                <div
                    class="cr-engines"
                    role="group"
                    aria-label="Review engine"
                >
                    <button
                        type="button"
                        class="cr-engine"
                        data-engine="standard"
                        aria-pressed="true"
                    ><span class="cr-engine-icon" data-icon="file"></span><strong>Standard diagnostics</strong><small>Code metrics, TODOs, long lines and common quality patterns.</small><span class="cr-engine-tag">QUALITY</span></button>
                    <button
                        type="button"
                        class="cr-engine"
                        data-engine="security"
                        aria-pressed="false"
                    ><span class="cr-engine-icon" data-icon="shield"></span><strong>Security patterns</strong><small>Potentially unsafe constructs that need a closer look.</small><span class="cr-engine-tag">SECURITY</span></button>
                    <button
                        type="button"
                        class="cr-engine"
                        data-engine="qms"
                        aria-pressed="false"
                    ><span class="cr-engine-icon" data-icon="check"></span><strong>QMS CodeSniffer</strong><small>24 criteria, coverage notes and manual review checkpoints.</small><span class="cr-engine-tag">QMS STANDARDS</span></button>
                    <button
                        type="button"
                        class="cr-engine"
                        data-engine="owasp"
                        aria-pressed="false"
                    ><span class="cr-engine-icon" data-icon="shield"></span><strong>OWASP Top 10:2025</strong><small>Category mapping, remediation and manual security checkpoints.</small><span class="cr-engine-tag">OWASP</span></button>
                    <button
                        type="button"
                        class="cr-engine"
                        data-engine="inventory"
                        aria-pressed="false"
                    ><span class="cr-engine-icon" data-icon="branch"></span><strong>Function inventory</strong><small>PHP routines, arguments, variables and return details.</small><span class="cr-engine-tag">DOCUMENTATION</span></button>
                </div>
                <label class="sr-only" for="crEngine">Selected review engine</label><select id="crEngine" class="cr-engine-select">
                    <option value="standard">Standard diagnostics</option>
                    <option value="security">Security patterns</option>
                    <option value="qms">QMS CodeSniffer</option>
                    <option value="owasp">OWASP Top 10:2025</option>
                    <option value="inventory">Function inventory</option>
                </select>
            </div>
            <div class="cr-section cr-filters">
                <div class="cr-step"><span>03</span>
                    <div>
                        <h4>Refine the scan</h4>
                        <p>Optional filters help keep the review focused.</p>
                    </div>
                </div>
                <div class="form-row"><label for="crApplication">Application (optional)</label><select id="crApplication">
                        <option value="">No application linked</option>
                    </select>
                    <p class="hint">Link the saved review to an application, or choose one later in Saved reviews.</p>
                </div>
                <div class="cr-filter-grid">
                    <div class="form-row"><label for="crType">File type</label><select id="crType">
                            <option value="">All supported types</option>
                            <option value="php">PHP</option>
                            <option value="phtml">PHTML</option>
                            <option value="inc">INC</option>
                            <option value="js">JavaScript</option>
                            <option value="css">CSS</option>
                            <option value="sql">SQL</option>
                            <option value="html">HTML</option>
                            <option value="htm">HTM</option>
                        </select></div>
                    <div class="form-row"><label for="crModified">Modified</label><select id="crModified">
                            <option value="0">Any time</option>
                            <option value="1">Within 1 day</option>
                            <option value="7">Within 7 days</option>
                            <option value="30">Within 30 days</option>
                        </select></div>
                    <div class="form-row"><label for="crSearch">Filename contains</label><input
                            id="crSearch"
                            type="search"
                            maxlength="100"
                            placeholder="e.g. login.php"
                        ></div>
                </div>
                <fieldset
                    id="crQmsOptions"
                    class="qms-options"
                    hidden
                >
                    <legend>QMS CodeSniffer settings</legend>
                    <div class="form-grid">
                        <div class="form-row"><label for="crQmsScope">Change scope</label><select id="crQmsScope">
                                <option value="existing">Existing files</option>
                                <option value="new">New files</option>
                                <option value="modified">Modified files</option>
                            </select>
                            <p class="hint">Criterion 7 applies to new/modified scripts. Criterion 16 applies to modified scripts.</p>
                        </div>
                        <div class="form-row"><label for="crQmsIndent">Indentation</label><select id="crQmsIndent">
                                <option value="4">4 spaces</option>
                                <option value="2">2 spaces</option>
                                <option value="tabs">Tabs</option>
                            </select>
                            <p class="hint">Review files with the same change scope together.</p>
                        </div>
                    </div>
                    <details>
                        <summary>Comment standards & review coverage</summary>
                        <p class="hint">The opening comment requires Filename, Purpose, Developer, Date and Recent updates. Database naming, SQL templates, approvals and successful log creation require reviewer confirmation. For modified scripts, verify change-trail placement against a diff.</p>
                        <pre class="qms-example">/*
 * Filename: example.php
 * Purpose: Validate submitted records.
 * Developer: Developer name (BDS SP/AP)
 * Date: <?= Date('Y-m-d') ?>
 * Recent updates: Added input validation.
 */

/*
 * Changed: Added validation; removed the legacy check.
 * Developer: Developer name (BDS SP/AP)
 * Date: <?= Date('Y-m-d') ?>
 */</pre>
                    </details>
                </fieldset>
            </div>
        </fieldset>
        <div class="cr-runbar">
            <div class="ws-actions"><button
                    class="btn btn-primary"
                    type="button"
                    data-permission="create"
                    id="crScanBtn"
                    onclick="CrScan()"
                >Review files</button><button
                    class="btn"
                    id="crCancel"
                    type="button"
                    hidden
                >Cancel scan</button><span
                    class="cr-progress"
                    id="crProgress"
                    role="status"
                    aria-live="polite"
                ></span></div><span class="hint"><?= number_format(CodeReview::MAX_FILES) ?> files · <?= CodeReview::MAX_FILE_BYTES / 1024 ?> KB per file · <?= CodeReview::MAX_TOTAL_BYTES / 1048576 ?> MB per scan</span>
        </div>
        <div class="cr-disclosure">Local folder scans send supported source contents to this server for analysis. Findings and metadata are saved; raw source is not retained. Pattern matches need human review.</div>
    </section>
    <div
        class="cr-report-bar"
        id="crReportBar"
        hidden
    >
        <div>
            <h3>Review results</h3>
            <p id="crReportContext"></p><span
                class="ws-tag amber"
                id="crStale"
                hidden
            >Settings changed · Run a new review to apply them</span>
        </div>
        <div class="ws-actions"><a
                class="btn"
                id="crSavedReview"
                hidden
            >Open saved review</a><button
                class="btn"
                data-permission="export"
                id="crExportXlsx"
                onclick="CrExport('xlsx')"
                disabled
            >Export XLSX</button><button
                class="btn"
                data-permission="export"
                id="crExportOds"
                onclick="CrExport('ods')"
                disabled
            >Export ODS</button></div>
    </div>
    <div id="crResults">
        <div class="ws-card ws-state" id="crInitial"><span data-icon="shield"></span><strong>Ready when you are.</strong>
            <p>Pick a source and engine, then select Review files. Findings and recommendations will appear here.</p>
        </div>
    </div>
</div>

<?php echo view('layouts/footer', get_defined_vars());
?>
