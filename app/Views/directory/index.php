<?php

/**

 * Filename: index.php

 * Purpose: Render the Directory workspace.

 * Developer: DevAtlas maintainers

 * Date: 2026-10-29

 * Recent updates: Render CodeIgniter layouts and align first-party template markup.

 */

?>

<?php if ($isHasWorkspace)

{

    echo view('layouts/header', get_defined_vars());

}

else

{

    ?>

<!DOCTYPE html>

<html lang="en">

    <head>

        <meta charset="UTF-8">

        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>Call Directory · <?= H(APP_NAME) ?></title>

        <link
            rel="icon"
            type="image/svg+xml"
            href="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
        >

        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/app.css')) ?>">

        <?php echo view('layouts/ui_assets', get_defined_vars());

    ?>
        <script src="<?= H(AssetUrl('assets/js/app.js')) ?>"></script>

        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/devatlas.css')) ?>">

    </head>

    <body class="directory-page directory-public">

        <header class="directory-public-header">

            <a class="directory-public-brand" href="<?= H($directoryUrl) ?>"><img
                    class="brand-icon"
                    src="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
                    width="36"
                    height="36"
                    alt=""
                ><span><?= H(APP_NAME) ?><small>Call Directory</small></span></a>

            <div class="directory-public-actions"><span class="directory-view-badge">View only</span><a class="btn btn-primary btn-sm" href="<?= H($signInUrl) ?>"><?= $currentUser ? 'Update password' : 'Sign in' ?></a></div>

        </header>

        <main class="directory-public-main">

            <?php

}

?>

            <div
                id="directoryApp"
                class="directory-workspace"
                data-api="<?= H(APP_BASE_URL . '/modules/directory/api.php') ?>"
                data-can-manage="<?= $isCanManage ? '1' : '0' ?>"
            >

                <section class="directory-hero" aria-labelledby="directoryHeading">

                    <div>
                        <div class="directory-eyebrow">PEOPLE &amp; TEAMS</div>
                        <h2 id="directoryHeading">Find the right department.</h2>
                        <p>Look up a shared inbox, call a hotline, or find a local extension.</p>
                    </div>

                    <div class="directory-hero-actions">

                        <button
                            type="button"
                            class="btn btn-sm"
                            id="directoryShare"
                        >Copy directory link</button>

                        <button
                            type="button"
                            class="btn btn-sm"
                            id="directoryPrint"
                            disabled
                        >Print</button>

                        <?php if ($isCanManage): ?>
                        <a class="btn btn-primary btn-sm" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/organization/index.php')) ?>">Manage organization</a>

                        <?php endif; ?>

                    </div>

                </section>

                <section class="directory-summary" aria-label="Directory totals">

                    <div><span class="directory-summary-icon" aria-hidden="true"><?= UiIcon('network') ?></span><span><strong id="directoryDepartmentTotal">—</strong><small>Departments</small></span></div>

                    <div><span class="directory-summary-icon" aria-hidden="true"><?= UiIcon('phone') ?></span><span><strong id="directoryNumberTotal">—</strong><small>Phone numbers</small></span></div>

                    <div><span class="directory-summary-icon" aria-hidden="true">@</span><span><strong id="directoryEmailTotal">—</strong><small>Shared inboxes</small></span></div>

                    <div><span class="directory-summary-icon" aria-hidden="true"><?= UiIcon('users') ?></span><span><strong id="directoryGroupTotal">—</strong><small>Groups</small></span></div>

                </section>

                <section class="card directory-browser" aria-label="Browse contacts">

                    <div class="directory-filter-bar">

                        <div class="directory-search-field"><label for="deptSearch">Search the directory</label>
                            <div class="directory-search-input"><span aria-hidden="true"><?= UiIcon('search') ?></span><input
                                    type="search"
                                    id="deptSearch"
                                    maxlength="200"
                                    placeholder="Department, team, email, or number…"
                                    autocomplete="off"
                                    aria-controls="deptGrid"
                                ></div>
                        </div>

                        <div><label for="directoryGroup">Group</label><select id="directoryGroup">
                                <option value="">All groups</option>
                            </select></div>

                        <div><label for="directoryDivision">Division</label><select id="directoryDivision">
                                <option value="">All divisions</option>
                            </select></div>

                        <div><label for="directoryType">Contact type</label><select id="directoryType">
                                <option value="">All contacts</option>
                                <option value="email">With email</option>
                                <option value="phone">With phone numbers</option>
                                <option value="local">Local extensions</option>
                                <option value="mobile">Mobile numbers</option>
                                <option value="hotline">Hotlines</option>
                                <option value="missing">Without contacts</option>
                            </select></div>

                    </div>

                    <div class="directory-results-toolbar">

                        <p
                            id="directoryResults"
                            role="status"
                            aria-live="polite"
                        >Loading departments…</p>

                        <div class="directory-view-controls"><button
                                type="button"
                                class="directory-text-button"
                                id="directoryReset"
                            >Clear filters</button><label class="directory-sort-label" for="directorySort">Sort<select id="directorySort">
                                    <option value="asc">Name A–Z</option>
                                    <option value="desc">Name Z–A</option>
                                </select></label>
                            <div
                                class="directory-view-switch"
                                role="group"
                                aria-label="Results layout"
                            ><button
                                    type="button"
                                    data-directory-view="cards"
                                    aria-pressed="true"
                                >Cards</button><button
                                    type="button"
                                    data-directory-view="list"
                                    aria-pressed="false"
                                >List</button></div>
                        </div>

                    </div>

                    <div
                        id="deptGrid"
                        class="directory-contact-grid"
                        aria-busy="true"
                    >
                        <div class="directory-state"><span class="directory-loading" aria-hidden="true"></span>
                            <p>Loading the directory…</p>
                        </div>
                    </div>

                    <nav
                        id="directoryPagination"
                        class="directory-pagination"
                        aria-label="Directory pages"
                        hidden
                    ><button
                            class="btn btn-sm"
                            type="button"
                            id="directoryPrevious"
                        ><?= UiIcon('arrow-left') ?> Previous</button><span id="directoryPageLabel"></span><button
                            class="btn btn-sm"
                            type="button"
                            id="directoryNext"
                        >Next <?= UiIcon('arrow-right') ?></button></nav>

                </section>

                <div class="directory-footnote"><span>Shared department contacts</span><span><?= $isCanManage ? 'Use Manage contacts on a department to keep its details up to date.' : 'Browsing is open to everyone. Sign in to maintain contact details.' ?></span></div>

            </div>

            <?php if ($isCanManage): ?>

            <dialog
                class="directory-editor"
                id="contactsModal"
                aria-labelledby="contactsModalTitle"
            >

                <div class="directory-editor-header">
                    <div>
                        <div class="directory-eyebrow">DEPARTMENT CONTACTS</div>
                        <h2 id="contactsModalTitle">Manage contacts</h2>
                    </div><button
                        type="button"
                        class="directory-icon-button"
                        data-close-editor
                        aria-label="Close contact editor"
                    ><?= UiIcon('x') ?></button>
                </div>

                <div class="directory-editor-body">

                    <p class="directory-editor-path" id="directoryEditorPath"></p>

                    <p
                        id="directoryEditorStatus"
                        class="directory-editor-status"
                        role="status"
                        aria-live="polite"
                    ></p>

                    <fieldset id="directoryEditorFields">

                        <section class="directory-editor-section">
                            <h3>Shared inboxes</h3>
                            <div id="cm_email_list"></div>

                            <form id="directoryEmailForm" class="directory-contact-form">
                                <div><label for="cm_new_email" id="directoryEmailLabel">Add email address</label><input
                                        id="cm_new_email"
                                        name="email"
                                        type="email"
                                        required
                                        maxlength="200"
                                        placeholder="team@company.com"
                                        autocomplete="off"
                                    ></div><button
                                    class="btn btn-primary btn-sm"
                                    type="submit"
                                    id="directoryEmailSave"
                                >Add email</button><button
                                    class="btn btn-sm"
                                    type="button"
                                    id="directoryEmailCancel"
                                    hidden
                                >Cancel edit</button>
                            </form>

                        </section>

                        <section class="directory-editor-section">
                            <h3>Telephone &amp; local numbers</h3>
                            <div id="cm_number_list"></div>

                            <form id="directoryNumberForm" class="directory-contact-form directory-number-form">
                                <div><label for="cm_new_number" id="directoryNumberLabel">Add phone number</label><input
                                        id="cm_new_number"
                                        name="phone_number"
                                        type="tel"
                                        required
                                        maxlength="50"
                                        placeholder="e.g. 2104 or +63 2 8123 4567"
                                        autocomplete="off"
                                    ></div>
                                <div><label for="cm_new_label">Label</label><input
                                        id="cm_new_label"
                                        name="label"
                                        type="text"
                                        required
                                        maxlength="50"
                                        value="Local"
                                        list="directoryNumberLabels"
                                        autocomplete="off"
                                    ><datalist id="directoryNumberLabels">
                                        <option value="Local">
                                        <option value="Mobile">
                                        <option value="Hotline">
                                        <option value="Office">
                                        <option value="Fax">
                                    </datalist></div><button
                                    class="btn btn-primary btn-sm"
                                    type="submit"
                                    id="directoryNumberSave"
                                >Add number</button><button
                                    class="btn btn-sm"
                                    type="button"
                                    id="directoryNumberCancel"
                                    hidden
                                >Cancel edit</button>
                            </form>

                        </section>

                    </fieldset>

                </div>

                <div class="directory-editor-footer"><span>Changes are saved as you add, edit, or remove a contact.</span><button
                        type="button"
                        class="btn btn-sm"
                        data-close-editor
                    >Done</button></div>

            </dialog>

            <?php endif; ?>

            <noscript>
                <div class="card">Enable JavaScript to search and browse the Call Directory.</div>
            </noscript>

            <?php if ($isHasWorkspace)

{

    echo view('layouts/footer', get_defined_vars());

}

   else

   {

       ?>

        </main>

        <footer class="directory-public-footer"><?= H(APP_NAME) ?> <span>·</span> Call Directory <span>·</span> View only

            <?php echo view('layouts/theme_control', get_defined_vars());

       ?>
        </footer>

        <script src="<?= H(AssetUrl($pageScript)) ?>"></script>

    </body>

</html>

<?php

   }

?>
