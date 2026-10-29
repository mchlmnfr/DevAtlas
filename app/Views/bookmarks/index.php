<?php

/**

 * Filename: index.php

 * Purpose: Render the Bookmarks workspace.

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
        <meta name="csrf-token" content="<?= H(CsrfToken()) ?>">
        <title>Quick Links · <?= H(APP_NAME) ?></title>
        <link
            rel="icon"
            type="image/svg+xml"
            href="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
        >
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/app.css')) ?>">

        <?php foreach ($pageStyles as $style): ?>
        <link rel="stylesheet" href="<?= H(AssetUrl($style)) ?>">

        <?php endforeach; ?>

        <?php echo view('layouts/ui_assets', get_defined_vars());

    ?>
        <script src="<?= H(AssetUrl('assets/js/app.js')) ?>"></script>
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/devatlas.css')) ?>">

    </head>
    <body class="<?= H($bodyClass) ?>">

        <header class="links-public-header"><a class="links-public-brand" href="<?= H($linksUrl) ?>"><img
                    class="brand-icon"
                    src="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
                    width="36"
                    height="36"
                    alt=""
                ><span><?= H(APP_NAME) ?><small>Public resources</small></span></a>
            <nav class="ws-actions" aria-label="Public resources"><a class="btn btn-sm" href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/directory/index.php')) ?>">Call Directory</a><a class="btn btn-primary btn-sm" href="<?= H($signInUrl) ?>"><?= $currentUser ? 'Update password' : 'Sign in' ?></a></nav>
        </header>
        <main class="links-public-main">

            <?php

}

?>

            <div
                class="ws-workspace"
                id="quickLinksApp"
                data-api="<?= APP_BASE_URL ?>/modules/bookmarks/api.php"
                data-can-manage="<?= $isCanManage ? '1' : '0' ?>"
            >

                <section class="ws-hero">
                    <div>
                        <div class="ws-eyebrow">ACCESS LINKS / QUICK LINKS</div>
                        <h2>Your tools, one click away.</h2>
                        <p>Find the right system or resource. Pin your regular destinations on this device for next time.</p>
                    </div>
                    <div class="ws-actions">

                        <?php if ($isCanManage): ?>
                        <button
                            class="btn btn-primary"
                            id="linkAdd"
                            type="button"
                        ><?= UiIcon('plus') ?> New link</button>

                        <?php else: ?>
                        <span class="ws-tag"><?= $isHasWorkspace ? 'View only' : 'Public · view only' ?></span>

                        <?php endif; ?>
                    </div>
                </section>

                <div class="links-summary" aria-label="Catalog totals"><span><strong id="linksTotal">—</strong>shared links</span><span><strong id="linksTagCount">—</strong>tags</span><span><strong id="linksHostCount">—</strong>destinations</span></div>

                <section class="ws-card links-browser" aria-label="Find quick links">
                    <div class="links-toolbar">
                        <div class="form-row links-search"><label for="bmSearch">Search resources</label>
                            <div class="ws-search-wrap"><span data-icon="search"></span><input
                                    id="bmSearch"
                                    type="search"
                                    placeholder="Title, description, address or tag…"
                                    maxlength="200"
                                    autocomplete="off"
                                ></div>
                        </div>
                        <div class="form-row"><label for="linksTag">Tag</label><select id="linksTag">
                                <option value="">All tags</option>
                            </select></div>
                        <div class="form-row links-sort"><label for="linksSort">Sort by</label><select id="linksSort">
                                <option value="title">Name · A to Z</option>
                                <option value="newest">Recently added</option>
                            </select></div>
                    </div>
                    <div class="links-tabs" aria-label="Link selection"><button
                            id="linksAll"
                            type="button"
                            aria-pressed="true"
                        >All links</button><button
                            id="linksPinned"
                            type="button"
                            aria-pressed="false"
                        >Pinned on this device</button><button id="linksReset" type="button">Clear filters</button></div>
                </section>

                <div
                    id="linksLoadError"
                    class="ws-state"
                    hidden
                ><strong>We couldn’t load the links.</strong>
                    <p>Check your connection and try again.</p><button
                        class="btn"
                        type="button"
                        id="linksRetry"
                    >Try again</button>
                </div>

                <div
                    id="bookmarkGrid"
                    class="links-grid"
                    aria-busy="true"
                ></div>

                <div
                    id="linksEmpty"
                    class="ws-state"
                    hidden
                ><span data-icon="grid"></span><strong id="linksEmptyTitle">No matching links.</strong>
                    <p id="linksEmptyText">Try another search or clear your filters.</p>
                </div>

                <div class="ws-pagination links-pagination"><span id="linksPageSummary" role="status">Loading links…</span>
                    <div class="ws-actions"><button
                            type="button"
                            class="btn btn-sm"
                            id="linksPrevious"
                            disabled
                        ><?= UiIcon('arrow-left') ?> Previous</button><button
                            type="button"
                            class="btn btn-sm"
                            id="linksNext"
                            disabled
                        >Next <?= UiIcon('arrow-right') ?></button></div>
                </div>

                <p class="links-help">

                    <?php if ($isCanManage): ?>
                    Links in this catalog are available to visitors without signing in. Use the editor to keep their addresses and descriptions current.

                    <?php else: ?>

                    <?php if ($isHasWorkspace): ?>
                    Your role can browse these resources. Contact an Analyst or Administrator to update shared links.

                    <?php else: ?>
                    Anyone can browse these resources. <a href="<?= H($signInUrl) ?>">Sign in to manage links</a>.

                    <?php endif; ?>

                    <?php endif; ?>
                    Pinned selections are saved in this browser.</p>

            </div>

            <?php if ($isCanManage): ?>

            <dialog
                id="bookmarkModal"
                class="ws-dialog"
                aria-labelledby="bookmarkModalTitle"
            >
                <form id="bookmarkForm">
                    <div class="ws-dialog-header">
                        <div>
                            <h3 id="bookmarkModalTitle">New link</h3>
                            <p>Add a shared destination to the public catalog.</p>
                        </div><button
                            class="ws-dialog-close"
                            type="button"
                            data-dialog-close="bookmarkModal"
                            aria-label="Close link editor"
                        ><?= UiIcon('x') ?></button>
                    </div>
                    <div class="ws-dialog-body">
                        <div
                            class="ws-error"
                            id="linkFormError"
                            role="alert"
                            tabindex="-1"
                            hidden
                        ></div>
                        <div class="form-row"><label for="bm_title">Title *</label><input
                                type="text"
                                id="bm_title"
                                maxlength="200"
                                required
                            ></div>
                        <div class="form-row"><label for="bm_url">Address *</label><input
                                type="url"
                                id="bm_url"
                                maxlength="500"
                                required
                                placeholder="https://intranet.example/tool"
                            >
                            <div class="hint">Use http:// or https://. The link opens in a new tab.</div>
                        </div>
                        <div class="form-row"><label for="bm_description">Description</label><textarea
                                id="bm_description"
                                rows="3"
                                maxlength="5000"
                                placeholder="What is this resource used for?"
                            ></textarea></div>
                        <div class="form-row"><label for="bm_tags">Tags</label><input
                                type="text"
                                id="bm_tags"
                                maxlength="255"
                                placeholder="monitoring, internal, production"
                            >
                            <div class="hint">Separate tags with commas so people can find this resource.</div>
                        </div>
                    </div>
                    <div class="ws-dialog-footer"><button
                            class="btn"
                            type="button"
                            data-dialog-close="bookmarkModal"
                        >Cancel</button><button
                            class="btn btn-primary"
                            id="linkSave"
                            type="submit"
                        >Save link</button></div>
                </form>
            </dialog>

            <?php endif;

if ($isHasWorkspace): echo view('layouts/footer', get_defined_vars());

else: ?>

        </main>
        <footer class="links-public-footer"><?= H(APP_NAME) ?> · Shared tools and resources

            <?php echo view('layouts/theme_control', get_defined_vars());

    ?>
        </footer>
        <script src="<?= H(AssetUrl('assets/js/workspace-ui.js')) ?>"></script>
        <script src="<?= H(AssetUrl($pageScript)) ?>"></script>
    </body>
</html>

<?php endif; ?>
