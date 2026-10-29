<?php
/**
 * Filename: index.php
 * Purpose: Render the Wiki workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<?= view('layouts/docs_header', get_defined_vars()) ?>

<div class="ws-workspace" id="wikiApp">
    <section class="ws-hero">
        <div>
            <div class="ws-eyebrow">SYSTEM GUIDE</div>
            <h2>Documents Wiki</h2>
            <p>Understand each module, follow the daily-log workflow and see what is planned for DevAtlas.</p>
        </div><a class="btn" href="#roadmap">System roadmap <?= UiIcon('arrow-down') ?></a>
    </section>
    <section class="wiki-start ws-card" aria-labelledby="workflowTitle">
        <div><span class="wiki-kicker">START HERE</span>
            <h3 id="workflowTitle">From daily work to the weekly report</h3>
            <p>Use this sequence to keep section hours and approvals up to date.</p>
        </div>
        <ol>
            <li><a href="#module-holidays">Set holidays</a><span>Administrator maintains the organization calendar.</span></li>
            <li><a href="#module-dailylogs">Record &amp; submit</a><span>Employees enter work, leave and undertime.</span></li>
            <li><a href="#module-reviewlogs">Review each week</a><span>Assigned reviewer approves or returns the employee week.</span></li>
            <li><a href="#module-teammonitoring">Monitor &amp; export</a><span>Check hours, then export employee sheets in Reports.</span></li>
        </ol>
    </section>
    <div class="wiki-layout">
        <aside class="wiki-contents ws-card" aria-label="Guide contents">
            <div class="wiki-search"><label for="wikiSearch">Find in the guide</label><input
                    type="search"
                    id="wikiSearch"
                    maxlength="200"
                    placeholder="Try approvals, hours, export..."
                    autocomplete="off"
                >
                <p
                    id="wikiCount"
                    role="status"
                    aria-live="polite"
                ><?= count($guides) ?> module guides</p>
            </div>
            <nav aria-label="Module guide navigation">

                <?php foreach ($groups as $group => $items): ?>
                <div class="wiki-nav-group">
                    <h3><?= H($group) ?></h3>

                    <?php foreach ($items as $guide): ?>
                    <a href="#module-<?= H($guide[0]) ?>" data-wiki-link="<?= H($guide[0]) ?>"><?= H($guide[2]) ?></a>

                    <?php endforeach; ?>
                </div>

                <?php endforeach; ?>
                <a class="wiki-roadmap-link" href="#roadmap">System roadmap <?= UiIcon('arrow-right') ?></a>
            </nav>
        </aside>
        <div class="wiki-guides">
            <div
                id="wikiEmpty"
                class="ws-card ws-state"
                hidden
            >No matching guides. Try a different module or action.</div>

            <?php foreach ($guides as [$key,$group,$title,$purpose,$steps,$rules]): ?>
            <article
                class="wiki-guide ws-card"
                id="module-<?= H($key) ?>"
                data-wiki-guide="<?= H($key) ?>"
            >
                <div class="wiki-guide-heading">
                    <div><span class="wiki-kicker"><?= H($group) ?></span>
                        <h3><?= H($title) ?></h3>
                    </div>

                    <?php if ($key === 'updates'): ?>
                    <button
                        type="button"
                        class="btn btn-sm"
                        data-updates-open
                    >Open What's New</button>

                    <?php elseif ($key !== 'account' && Permissions::Visible($key) && Permissions::Can($key)): ?>
                    <a
                        class="btn btn-sm"
                        target="_blank"
                        rel="noopener"
                        href="<?= APP_BASE_URL ?><?= $key === 'dashboard' ? '/index.php' : '/modules/'.H($key).'/index.php' ?>"
                    >Open module <?= UiIcon('arrow-right') ?></a>

                    <?php endif; ?>
                </div>
                <p class="wiki-purpose"><?= H($purpose) ?></p>
                <h4>How to use it</h4>
                <ol>

                    <?php foreach ($steps as $step): ?>
                    <li><?= H($step) ?></li>

                    <?php endforeach; ?>
                </ol>
                <div class="wiki-rule">
                    <h4>Access &amp; working rules</h4>
                    <p><?= H($rules) ?></p>
                </div>
            </article>

            <?php endforeach; ?>
        </div>
    </div>
    <section
        class="wiki-roadmap"
        id="roadmap"
        aria-labelledby="roadmapTitle"
    >
        <div class="ws-hero">
            <div>
                <div class="ws-eyebrow">SYSTEM DIRECTION</div>
                <h2 id="roadmapTitle">System roadmap</h2>
                <p>Current capabilities and planned work. Planned items have no committed delivery dates or implementation order.</p>
            </div><a class="btn" href="#wikiApp">Back to guide <?= UiIcon('arrow-up') ?></a>
        </div>
        <div class="ws-card wiki-delivered"><span class="badge badge-green">Delivered</span>
            <h3>Available now</h3>
            <p>Core documentation and inventory modules, the application catalog, role permissions and custom roles, notifications and reminders, saved code-review tracking and OWASP checks.</p>
            <p>Daily logs, one approval per employee week, team hour balances, section Excel reports with employee sheets, organization-wide Holidays and this Documents Wiki are also available. The action dashboard, daily-log and approval reminders, and permission-aware record search are delivered.</p>
            <p>Users, Organization, Roles &amp; Permissions, Parameters and Holidays are grouped under <strong>Parameter</strong> in navigation and the permission matrix. Actual access follows the assigned role.</p>
        </div>
        <div class="wiki-planned">

            <?php foreach (SystemWiki::Roadmap() as [$title,$scope]): ?>
            <article class="ws-card"><span class="badge badge-blue">Planned</span>
                <h3><?= H($title) ?></h3>
                <p><?= H($scope) ?></p>
            </article>

            <?php endforeach; ?>
        </div>
    </section>
</div>

<?php echo view('layouts/docs_footer', get_defined_vars());
?>
