<?php
/**
 * Filename: index.php
 * Purpose: Render the Proginventory workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div
    class="ws-workspace"
    id="inventoryApp"
    data-client-url="<?= APP_BASE_URL ?>/modules/proginventory/export_client.php"
    data-server-url="<?= APP_BASE_URL ?>/modules/proginventory/export.php"
>
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">INVENTORY / PROJECT FILES</div>
            <h2>Know what’s in your project.</h2>
            <p>Choose a source, add report details, and create an organized Excel inventory of your project files.</p>
        </div><span class="ws-tag"><span data-icon="file"></span> Excel · 11 columns</span>
    </section>
    <form id="inventoryForm">
        <div class="inventory-layout">
            <div>
                <fieldset id="inventoryFields" class="inventory-fields">
                    <section class="ws-card">
                        <div class="ws-card-heading">
                            <div class="inventory-step-heading"><span class="inventory-step">01</span>
                                <div>
                                    <h3>Choose your source</h3>
                                    <p>Pick a local folder or an application directory on the server.</p>
                                </div>
                            </div>
                        </div>
                        <div class="ws-card-body">
                            <div class="inventory-source-options" aria-label="Inventory source"><button
                                    type="button"
                                    class="inventory-source"
                                    id="modeBtnClient"
                                    data-mode="client"
                                    aria-pressed="true"
                                ><span data-icon="folder"></span><span><strong>This computer</strong><small>Choose a folder with your browser</small></span><i aria-hidden="true"><?= UiIcon('check') ?></i></button><button
                                    type="button"
                                    class="inventory-source"
                                    id="modeBtnServer"
                                    data-mode="server"
                                    aria-pressed="false"
                                ><span data-icon="grid"></span><span><strong>Application server</strong><small>Enter a folder on the hosted server</small></span><i aria-hidden="true"><?= UiIcon('check') ?></i></button></div>
                            <div id="clientModeSection"><input
                                    type="file"
                                    id="folderPicker"
                                    webkitdirectory
                                    directory
                                    multiple
                                    hidden
                                ><button
                                    class="inventory-folder-picker"
                                    type="button"
                                    id="inventoryChoose"
                                ><span class="inventory-folder-icon" data-icon="folder"></span><strong id="folderPickedSummary">Choose a project folder</strong><span id="inventoryFolderHint">Browse folders on this computer</span><span class="btn btn-sm">Choose folder</span></button>
                                <p class="inventory-privacy">Only file names, paths, sizes and modified dates are included. File contents are never uploaded.</p><button
                                    type="button"
                                    class="ws-link-button"
                                    id="inventoryClear"
                                    hidden
                                >Clear selected folder</button>
                            </div>
                            <div id="serverModeSection" hidden>
                                <div class="form-row"><label for="pi_server_path">Folder path on the server *</label><input
                                        type="text"
                                        id="pi_server_path"
                                        maxlength="4096"
                                        placeholder="C:\projects\my-app"
                                        autocomplete="off"
                                    >
                                    <div class="hint">An absolute path on the machine hosting DevAtlas. This cannot read a folder on your own computer.</div>
                                </div>
                                <div class="ws-note">The server folder is scanned when you select <strong>Scan &amp; export</strong>. Its file metadata is written directly into the workbook.</div>
                            </div>
                        </div>
                    </section>
                    <section class="ws-card">
                        <div class="ws-card-heading">
                            <div class="inventory-step-heading"><span class="inventory-step">02</span>
                                <div>
                                    <h3>Add report details</h3>
                                    <p>These values appear alongside each file in your inventory.</p>
                                </div>
                            </div>
                        </div>
                        <div class="ws-card-body">
                            <div class="form-grid">
                                <div class="form-row"><label for="pi_application_id">Application (optional)</label><select id="pi_application_id">
                                        <option value="">No application linked</option>
                                    </select>
                                    <p class="hint">Keep export counts and version in the application catalog. File contents are not stored.</p>
                                </div>
                                <div class="form-row"><label for="pi_system_affected">System / project name *</label><input
                                        type="text"
                                        id="pi_system_affected"
                                        maxlength="200"
                                        required
                                        placeholder="e.g. Customer Portal"
                                    >
                                    <div class="hint">File IDs use the initials of this name.</div>
                                </div>
                                <div class="form-row"><label for="pi_file_version">File version</label><input
                                        type="text"
                                        id="pi_file_version"
                                        maxlength="100"
                                        value="1"
                                        placeholder="1"
                                    ></div>
                            </div>
                            <div class="form-row"><label for="pi_programmer_developer">Programmer / developer</label><input
                                    type="text"
                                    id="pi_programmer_developer"
                                    maxlength="200"
                                    value="<?= H($currentUser['full_name'] ?? '') ?>"
                                    placeholder="Name of the developer"
                                ></div>
                            <div class="form-row"><label for="pi_exclude_folders">Exclude folder names</label><input
                                    type="text"
                                    id="pi_exclude_folders"
                                    maxlength="1000"
                                    value=".git, node_modules, vendor"
                                >
                                <div class="hint">Separate names with commas. Matching folder names are excluded at any level; matching is case sensitive.</div>
                            </div>
                        </div>
                    </section>
                </fieldset>
                <section class="ws-card" id="inventoryPreviewSection">
                    <div class="ws-card-heading">
                        <div class="inventory-step-heading"><span class="inventory-step">03</span>
                            <div>
                                <h3>Preview your inventory</h3>
                                <p id="inventoryPreviewStatus" role="status">Select a folder to see the included file metadata.</p>
                            </div>
                        </div><span class="ws-tag" id="inventoryIncludedTag">0 files</span>
                    </div>
                    <div
                        class="ws-toolbar"
                        id="inventoryPreviewToolbar"
                        hidden
                    >
                        <div class="form-row ws-search"><label for="inventorySearch">Find a file</label>
                            <div class="ws-search-wrap"><span data-icon="search"></span><input
                                    type="search"
                                    id="inventorySearch"
                                    placeholder="File name or path…"
                                    maxlength="200"
                                ></div>
                        </div>
                        <div class="form-row"><label for="inventoryType">File type</label><select id="inventoryType">
                                <option value="">All types</option>
                            </select></div>
                    </div>
                    <div id="inventoryPreviewEmpty" class="ws-state"><span data-icon="file"></span><strong>No files selected yet.</strong>
                        <p>Choose a project folder above to preview the inventory.</p>
                    </div>
                    <div
                        class="ws-table-wrap"
                        id="inventoryPreviewTable"
                        hidden
                    >
                        <table class="ws-table inventory-table">
                            <thead>
                                <tr>
                                    <th>File / path</th>
                                    <th>Type</th>
                                    <th>Size</th>
                                    <th>Modified · Manila</th>
                                </tr>
                            </thead>
                            <tbody id="inventoryRows"></tbody>
                        </table>
                    </div>
                    <div
                        class="ws-pagination"
                        id="inventoryPagination"
                        hidden
                    ><span id="inventoryPageSummary" role="status"></span>
                        <div class="ws-actions"><button
                                class="btn btn-sm"
                                id="inventoryPrevious"
                                type="button"
                            ><?= UiIcon('arrow-left') ?> Previous</button><button
                                class="btn btn-sm"
                                id="inventoryNext"
                                type="button"
                            >Next <?= UiIcon('arrow-right') ?></button></div>
                    </div>
                </section>
            </div>
            <aside class="inventory-report">
                <section class="ws-card">
                    <div class="ws-card-heading">
                        <div>
                            <h3>Your export</h3>
                            <p>A formatted workbook, ready for review.</p>
                        </div><span data-icon="file"></span>
                    </div>
                    <div class="ws-card-body">
                        <div class="inventory-report-format"><span class="inventory-xlsx" aria-hidden="true">X</span>
                            <div><strong>Program Inventory</strong><small>Microsoft Excel · .xlsx</small></div>
                        </div>
                        <dl class="inventory-report-meta">
                            <div>
                                <dt>Source</dt>
                                <dd id="inventoryReportSource">This computer</dd>
                            </div>
                            <div>
                                <dt>Included files</dt>
                                <dd id="inventoryReportCount">—</dd>
                            </div>
                            <div>
                                <dt>Total file size</dt>
                                <dd id="inventoryReportSize">—</dd>
                            </div>
                            <div>
                                <dt>Excluded files</dt>
                                <dd id="inventoryReportExcluded">—</dd>
                            </div>
                            <div>
                                <dt>First file ID</dt>
                                <dd id="inventoryReportId">Add a project name</dd>
                            </div>
                        </dl>
                        <div
                            id="inventoryError"
                            class="ws-error"
                            role="alert"
                            tabindex="-1"
                            hidden
                        ></div><button
                            type="submit"
                            class="btn btn-primary inventory-export"
                            data-permission="export"
                            id="clientGenerateBtn"
                            disabled
                        >Download inventory</button>
                        <p class="inventory-export-hint" id="inventoryExportHint">Choose a folder and enter the project name to continue.</p>
                        <div
                            id="inventorySuccess"
                            class="ws-note"
                            role="status"
                            hidden
                        ></div>
                    </div>
                </section>
                <details class="ws-card inventory-columns">
                    <summary>What’s in the workbook?<span aria-hidden="true"><?= UiIcon('plus') ?> </span></summary>
                    <ol>

                        <?php foreach (['ID','File name','File size (KB)','File type','Date modified','System affected','File version','Programmer / developer','Path','Interface (action / system)','Remarks'] as $column): ?>
                        <li><?= H($column) ?></li>

                        <?php endforeach; ?>
                    </ol>
                    <p>Interface matches the project name. Remarks are left blank for your notes.</p>
                </details>
            </aside>
        </div>
    </form>
</div>

<?php echo view('layouts/footer', get_defined_vars());
?>
