<?php
/**
 * Filename: header.php
 * Purpose: Provide header processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Apply saved account motion before module content is displayed.
 */

/**
 * Shared application layout.
 * Expects $pageTitle, $pageSubtitle (optional), $activeNav to be set by the including page.
 */
require_once ROOTPATH . 'config/config.php';
require_once ROOTPATH . 'lib/helpers.php';
require_once ROOTPATH . 'lib/Auth.php';

Auth::RequirePage();
// every page that includes this header requires a logged-in session
$currentUser = Auth::User();
require_once ROOTPATH . 'lib/UserUiPreferences.php';
$uiState = $uiState ?? UserUiPreferences::Load(Database::GetConnection('app'), (int)$currentUser['user_id']);

$activeNav = $activeNav ?? '';
require_once ROOTPATH.'lib/WorkspaceExperience.php';
$experienceState = $experienceState ?? WorkspaceExperience::Initial(Database::GetConnection('app'), (int)$currentUser['user_id'], $activeNav, $uiState['preferences']);
$pageTitle = $pageTitle ?? APP_NAME;
$token = CsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-motion="<?= H($uiState['preferences']['motion']) ?>" data-accent="<?= H($experienceState['documents']['appearance']['value']['accent']) ?>" data-reading-size="<?= H($experienceState['documents']['appearance']['value']['textSize']) ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="<?= H($token) ?>">
        <title><?= H($pageTitle) ?> · <?= H(APP_NAME) ?></title>
        <link
            rel="icon"
            type="image/svg+xml"
            href="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
        >
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/app.css')) ?>">
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/help.css')) ?>">

        <?php foreach ($pageStyles ?? [] as $stylesheet): ?>
        <link rel="stylesheet" href="<?= H(AssetUrl($stylesheet)) ?>">

        <?php endforeach; ?>
        <script>window.AT_AVATAR_UPLOAD_URL = <?= json_encode(APP_BASE_URL . '/modules/account/upload_avatar.php') ?>;</script>

        <script>window.AT_EXPERIENCE = <?= json_encode($experienceState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
        <?php echo view('layouts/ui_assets', get_defined_vars());
?>
        <script src="<?= H(AssetUrl('assets/js/app.js')) ?>"></script>
        <script>window.AT_ACCESS = <?= json_encode(Permissions::ClientMatrix() + ['module' => $activeNav]) ?>;</script>
        <script src="<?= H(AssetUrl('assets/js/permissions-ui.js')) ?>"></script>
        <script>window.AT_AUDIT = <?= json_encode(['endpoint' => APP_BASE_URL . '/modules/audit/client.php']) ?>;</script>
        <script defer src="<?= H(AssetUrl('assets/js/audit-client.js')) ?>"></script>
        <link rel="stylesheet" href="<?= H(AssetUrl('assets/css/devatlas.css')) ?>">
    </head>
    <body class="<?= H($bodyClass ?? '') ?>">
        <div class="app-shell">
            <aside class="sidebar" id="appSidebar">
                <a
                    class="brand"
                    href="<?= H(RouteUrls::Url(APP_BASE_URL . '/index.php')) ?>"
                    aria-label="<?= H(APP_NAME) ?> dashboard"
                ><img
                        class="brand-icon"
                        src="<?= H(AssetUrl('assets/brand/devatlas-mark.svg')) ?>"
                        width="36"
                        height="36"
                        alt=""
                    ><span><?= H(APP_NAME) ?><small>Enterprise IT management</small></span></a>
                <nav id="sidebarNav" aria-label="Main navigation">
                    <div class="nav-section">
                        <div class="nav-heading">Overview</div>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/index.php')) ?>" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('layout-dashboard') ?></span><span class="nav-label">Dashboard</span></a>

                        <?php if (Permissions::Can('notifications')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/notifications/index.php')) ?>" class="<?= $activeNav === 'notifications' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('bell') ?></span><span class="nav-label">Notifications</span><span
                                class="badge badge-blue notification-badge"
                                id="notificationBadge"
                                hidden
                            ></span></a>

                        <?php endif; ?>
                    </div>
                    <?php if (Permissions::Can('teammonitoring') || Permissions::Can('dailylogs') || Permissions::Can('leaves') || Permissions::Can('approvals') || Permissions::Can('reports')): ?>
                    <div class="nav-section">
                        <div class="nav-heading">Team Monitoring</div>

                        <?php foreach (['teammonitoring' => ['Team Monitoring','users'],'dailylogs' => ['My Daily Logs','notebook-pen'],'leaves' => ['Leaves','calendar-days'],'approvals' => ['Approvals','clipboard-check'],'reports' => ['Reports','file-chart-column']] as $navKey => $navItem): if (Permissions::Can($navKey)): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/' . $navKey . '/index.php')) ?>" class="<?= $activeNav === $navKey ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon($navItem[1]) ?></span><span class="nav-label"><?= H($navItem[0]) ?></span></a>

                        <?php endif;
        endforeach;
          ?>
                    </div>

                    <?php endif; ?>
                    <?php if (Permissions::Can('datadict') || Permissions::Can('diagrams') || Permissions::Can('dataconversion')): ?>
                    <div class="nav-section">
                        <div class="nav-heading">Data Management</div>
                        <?php if (Permissions::Can('datadict')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/datadict/index.php')) ?>" class="<?= $activeNav === 'datadict' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('database') ?></span><span class="nav-label">Data Dictionary</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('diagrams')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/diagrams/index.php')) ?>" class="<?= $activeNav === 'diagrams' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('workflow') ?></span><span class="nav-label">ERD Generator</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('dataconversion')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/dataconversion/index.php')) ?>" class="<?= $activeNav === 'dataconversion' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('database') ?></span><span class="nav-label">Data Conversion</span></a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="nav-section">
                        <div class="nav-heading">Workspace</div>

                        <?php if (Permissions::Visible('applications') && Permissions::Can('applications')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/applications/index.php')) ?>" class="<?= $activeNav === 'applications' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('layers') ?></span><span class="nav-label">Applications</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Visible('requirements') && Permissions::Can('requirements')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/requirements/index.php')) ?>" class="<?= $activeNav === 'requirements' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('list-checks') ?></span><span class="nav-label">Requirements</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('codereview')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/codereview/index.php')) ?>" class="<?= $activeNav === 'codereview' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('code-xml') ?></span><span class="nav-label">Code Review</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('servers')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/servers/index.php')) ?>" class="<?= $activeNav === 'servers' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('server') ?></span><span class="nav-label">Server Inventory</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('proginventory')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/proginventory/index.php')) ?>" class="<?= $activeNav === 'proginventory' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('folder-code') ?></span><span class="nav-label">Program Inventory</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('directory')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/directory/index.php')) ?>" class="<?= $activeNav === 'directory' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('contact') ?></span><span class="nav-label">Call Directory</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('bookmarks')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/bookmarks/index.php')) ?>" class="<?= $activeNav === 'bookmarks' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('external-link') ?></span><span class="nav-label">Access Links</span></a>

                        <?php endif; ?>
                    </div>

                    <div class="nav-section">
                        <div class="nav-heading">Parameter</div>

                        <?php if (Permissions::Can('usermgmt')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/usermgmt/index.php')) ?>" class="<?= $activeNav === 'usermgmt' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('user-round') ?></span><span class="nav-label">Users</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('organization')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/organization/index.php')) ?>" class="<?= $activeNav === 'organization' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('network') ?></span><span class="nav-label">Organization</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('permissions')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/permissions/index.php')) ?>" class="<?= $activeNav === 'permissions' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('shield-check') ?></span><span class="nav-label">Roles &amp; Permissions</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('settings')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/settings/index.php')) ?>" class="<?= $activeNav === 'settings' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('settings-2') ?></span><span class="nav-label">Parameters</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('holidays')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/holidays/index.php')) ?>" class="<?= $activeNav === 'holidays' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('calendar-days') ?></span><span class="nav-label">Holidays</span></a>

                        <?php endif; ?>
                    </div>
                    <div class="nav-section">
                        <div class="nav-heading">Logs</div>

                        <?php if (Permissions::Can('logs')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/logs/index.php')) ?>" class="<?= $activeNav === 'logs' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('scroll-text') ?></span><span class="nav-label">Error</span></a>

                        <?php endif; ?>

                        <?php if (Permissions::Can('audit')): ?>
                        <a href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/audit/index.php')) ?>" class="<?= $activeNav === 'audit' ? 'active' : '' ?>"><span class="nav-icon"><?= UiIcon('history') ?></span><span class="nav-label">Audit</span></a>

                        <?php endif; ?>
                    </div>
                </nav>
                <div class="sidebar-footer">

                    <?php echo view('layouts/user_menu', get_defined_vars());
?>
                    <div class="sidebar-footer-caption">v1.0 · Local-first · Offline</div>
                </div>
            </aside>
            <div class="main">
                <header
                    class="topbar"
                    id="appTopbar"
                    aria-label="Workspace header"
                >
                    <button
                        class="topbar-menu-button"
                        id="sidebarToggle"
                        type="button"
                        onclick="AT.toggleSidebar()"
                        aria-label="Toggle sidebar"
                        aria-controls="appSidebar"
                        aria-expanded="true"
                        title="Toggle sidebar"
                    ><?= UiIcon('panel-left') ?></button>
                    <div class="topbar-title">
                        <h1><?= H($pageTitle) ?></h1>

                        <?php if (!empty($pageSubtitle)): ?>
                        <div class="subtitle"><?= H($pageSubtitle) ?></div>

                        <?php endif; ?>
                    </div>
                    <div
                        class="topbar-search-wrap"
                        id="moduleSearchWrap"
                        x-data="WorkspaceSearch"
                        data-record-search="<?= APP_BASE_URL ?>/modules/account/search.php"
                    >
                        <label class="topbar-search" for="moduleSearch"><?= UiIcon('search', 'topbar-search-icon') ?><input
                                id="moduleSearch"
                                type="search"
                                x-model="query"
                                role="combobox"
                                aria-label="Search workspace"
                                aria-autocomplete="list"
                                aria-haspopup="listbox"
                                aria-controls="moduleSearchResults"
                                aria-expanded="false"
                                placeholder="Search workspace…"
                                autocomplete="off"
                                maxlength="120"
                                x-on:focus="Search"
                                x-on:input="Search"
                                x-on:keydown="Keydown"
                            ><kbd aria-hidden="true">Ctrl K</kbd></label>
                        <div
                            class="module-search-panel"
                            id="moduleSearchPanel"
                            hidden
                        >
                            <div class="module-search-heading"><span
                                    id="moduleSearchStatus"
                                    role="status"
                                    aria-live="polite"
                                ></span><small>Modules &amp; records</small></div>
                            <div
                                id="recordSearchFragment"
                                hx-sync="this:replace"
                                hidden
                                aria-hidden="true"
                            ></div>
                            <div
                                class="module-search-results"
                                id="moduleSearchResults"
                                role="listbox"
                                aria-label="Modules and records"
                            ></div>
                            <div
                                class="module-search-empty"
                                id="moduleSearchEmpty"
                                hidden
                            ><strong>No matching records or modules</strong><span>Try an employee, server, table or module name.</span></div>
                            <div class="module-search-help" aria-hidden="true"><span><?= UiIcon('arrow-up') ?> <?= UiIcon('arrow-down') ?> Choose</span><span>Enter Open</span><span>Esc Close</span></div>
                        </div>
                    </div>
                    <div class="topbar-right">
                        <details class="help-menu" id="helpMenu">
                            <summary aria-label="Help and updates" title="Help and updates"><?= UiIcon('circle-help') ?></summary>
                            <div class="help-menu-links">

                                <?php if (Permissions::Can('wiki')): ?>
                                <a
                                    href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/wiki/index.php')) ?>"
                                    target="_blank"
                                    rel="noopener"
                                >Documents Wiki <span aria-hidden="true"><?= UiIcon('arrow-up-right') ?></span></a>

                                <?php endif; ?>
                                <button type="button" data-updates-open>What's New</button>
                            </div>
                        </details>
                        <?= UiIcon('clock-3', 'topbar-time-icon') ?>
                        <div class="topbar-meta">
                            <div id="topbarDate" class="topbar-date"></div><time
                                id="topbarClock"
                                class="topbar-clock"
                                data-time-zone="<?= H($clockTimeZone ?? APP_TIMEZONE) ?>"
                            ></time>
                        </div>
                    </div>
                </header>
                <div class="content">



