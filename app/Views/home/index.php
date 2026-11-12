<?php
/**
 * Filename: index.php
 * Purpose: Render the Home workspace.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Render customizable widgets without changing their data permissions.
 */
$widgetPositions = [];
foreach ($uiState['preferences']['widgets'] as $position => $widget) $widgetPositions[$widget['id']] = ['position' => $position, 'visible' => $widget['visible']];
$widgetAttributes = static function (string $id, string $label) use ($widgetPositions): string {
    $widget = $widgetPositions[$id];
    return 'data-dashboard-widget="' . H($id) . '" data-widget-label="' . H($label) . '" style="order:' . $widget['position'] . '"' . ($widget['visible'] ? '' : ' hidden');
};
?>
<?= view('layouts/header', get_defined_vars()) ?>

<div class="dashboard-v2 action-dashboard" id="actionDashboard" data-density="<?= H($uiState['preferences']['density']) ?>" data-preferences-url="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/account/ui_preferences.php')) ?>">
    <div class="dashboard-welcome">
        <div>
            <div class="dashboard-overline">YOUR WORKSPACE</div>
            <h2 id="greetingText" data-greeting-name="<?= H($currentUser['full_name'] ?: $currentUser['employee_id']) ?>">Welcome back, <?= H($currentUser['full_name'] ?: $currentUser['employee_id']) ?></h2>
            <p id="greetingDate">Your next actions at a glance.</p>
        </div>
        <div class="dashboard-welcome-actions">
            <button type="button" class="btn" id="dashboardCustomize" aria-haspopup="dialog" aria-controls="dashboardCustomizer"><?= UiIcon('settings-2') ?> Customize</button>

            <?php if (Permissions::Can('dailylogs', 'create')): ?>
            <a class="btn btn-primary" href="<?= H($path('dailylogs', ['new' => 1])) ?>"><?= UiIcon('plus') ?> Add daily log</a>

            <?php endif; ?>

            <?php if (Permissions::Can('reports')): ?>
            <a class="btn" href="<?= H($path('reports')) ?>"><?= UiIcon('file-chart-column') ?> Reports</a>

            <?php endif; ?>
        </div>
    </div>

    <?php if (!$actions): ?>
    <section class="card">
        <h2>Workspace unavailable</h2>
        <p>We could not connect to the workspace database. Please try again shortly.</p>
    </section>

    <?php else: $personal = $actions['personal'];
    $team = $actions['team'];
    $approvals = $actions['approvals'];
    $renewals = $actions['renewals'];
    ?>
    <div class="action-period"><?= UiIcon('calendar-days') ?><strong><?= H($dateLabel($actions['week_start']).' – '.$dateLabel($actions['week_end'])) ?></strong><span>Current work week · Asia/Manila</span><small>Today's hours become due at <?= sprintf('%02d:00', $actions['cutoff_hour']) ?>.</small></div>
    <div class="dashboard-grid" id="dashboardGrid">
    <div class="action-metrics" <?= $widgetAttributes('metrics', 'Summary cards') ?>>

        <?php if ($personal): ?>
        <a class="card action-metric <?= $personal['due_hours'] ? 'needs-attention' : '' ?>" href="<?= H($path('dailylogs', ['date_from' => $actions['week_start'],'date_to' => $actions['today']])) ?>"><span class="action-icon"><?= UiIcon('clock-3') ?></span><span>Hours to catch up</span><strong data-action="due-hours"><?= number_format($personal['due_hours'], 2) ?></strong><small>Due working days in this week</small></a>
        <a class="card action-metric <?= $personal['returned'] ? 'needs-attention' : '' ?>" href="<?= H($path('dailylogs', ['status' => 'Returned'])) ?>"><span class="action-icon"><?= UiIcon('rotate-ccw') ?></span><span>Returned weeks</span><strong data-action="returned"><?= count($personal['returned']) ?></strong><small>Correct entries and resubmit</small></a>

        <?php endif; ?>

        <?php if ($approvals !== null): ?>
        <a class="card action-metric <?= $approvals['count'] ? 'needs-attention' : '' ?>" href="<?= H($path('reviewlogs', ['status' => 'Submitted'])) ?>"><span class="action-icon"><?= UiIcon('clipboard-check') ?></span><span>Weeks awaiting approval</span><strong data-action="approvals"><?= $approvals['count'] ?></strong><small>Employee weeks you can review</small></a>

        <?php endif; ?>

        <?php if ($renewals !== null): ?>
        <a class="card action-metric <?= $renewals['count'] ? 'needs-attention' : '' ?>" href="<?= H($path('servers', ['tab' => 'renewals','status' => 'attention'])) ?>"><span class="action-icon"><?= UiIcon('calendar-days') ?></span><span>Renewals needing attention</span><strong data-action="renewals"><?= $renewals['count'] ?></strong><small><?= $renewals['overdue'] ?> overdue · based on alert settings</small></a>

        <?php endif; ?>
    </div>

        <?php if ($personal): $filled = max(0, $personal['target_hours'] - $personal['remaining_hours']); ?>
        <section class="card action-panel" aria-labelledby="personalWeekTitle" <?= $widgetAttributes('personal', 'My week') ?>>
            <div class="action-panel-head">
                <div><span class="dashboard-overline">MY DAILY LOGS</span>
                    <h2 id="personalWeekTitle">Your week, in hours</h2>
                </div><a href="<?= H($path('dailylogs')) ?>">Open logs <?= UiIcon('arrow-right') ?></a>
            </div>
            <div class="action-hours"><strong data-action="entered-hours"><?= number_format($personal['entered_hours'], 2) ?><small> / <?= number_format($personal['target_hours'], 2) ?> hrs</small></strong><span><?= number_format($personal['remaining_hours'], 2) ?> hrs still needed this week</span></div>
            <progress
                class="action-progress"
                value="<?= $personal['target_hours'] ? $filled : 1 ?>"
                max="<?= $personal['target_hours'] ?: 1 ?>"
                aria-label="Working-day hours completed this week"
            ></progress>
            <p class="action-note">Submitted and approved work and leave count toward each working day. Holidays and weekends are excluded. Upcoming days are included in the weekly target.</p>
            <div class="action-inline"><a class="btn btn-sm" href="<?= H($path('dailylogs', ['status' => 'Draft'])) ?>"><?= UiIcon('notebook-pen') ?> <?= $personal['drafts'] ?> draft entries</a>

                <?php if ($personal['due_hours'] == 0 && !$personal['returned']): ?>
                <span class="action-success"><?= UiIcon('circle-check') ?> Nothing overdue this week</span>

                <?php endif; ?>
            </div>

            <?php if ($personal['returned']): ?>
            <div class="action-list" aria-label="Returned employee weeks">

                <?php foreach (array_slice($personal['returned'], 0, 3) as $week): ?>
                <a href="<?= H($path('dailylogs', ['status' => 'Returned','date_from' => $week['week_start'],'date_to' => (new DateTimeImmutable($week['week_start']))->modify('+6 days')->format('Y-m-d')])) ?>"><span><strong>Week of <?= H($dateLabel($week['week_start'])) ?></strong><small><?= $week['entries'] ?> returned entries</small></span><?= UiIcon('arrow-right') ?></a>

                <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </section>

        <?php endif; ?>

        <?php if ($approvals !== null): ?>
        <section class="card action-panel" aria-labelledby="approvalQueueTitle" <?= $widgetAttributes('approvals', 'Weekly approvals') ?>>
            <div class="action-panel-head">
                <div><span class="dashboard-overline">REVIEW QUEUE</span>
                    <h2 id="approvalQueueTitle">Weekly approvals</h2>
                </div><a href="<?= H($path('reviewlogs', ['status' => 'Submitted'])) ?>">View all <?= UiIcon('arrow-right') ?></a>
            </div>

            <?php if (!$approvals['count']): ?>
            <div class="action-empty"><?= UiIcon('circle-check') ?><strong>Your review queue is clear.</strong>
                <p>Submitted employee weeks will appear here when you can review them.</p>
            </div>

            <?php else: ?>
            <p class="action-note">Oldest weeks first. Open a week to review its entries before deciding.</p>
            <div class="action-list">

                <?php foreach ($approvals['weeks'] as $week): ?>
                <a data-approval-week href="<?= H($path('reviewlogs', ['user_id' => $week['user_id'],'q' => $week['employee_id'],'week_start' => $week['week_start'],'status' => 'Submitted'])) ?>"><span><strong><?= H($week['employee_name']) ?></strong><small><?= H($week['unit_name'] ?: 'Unassigned') ?> · Week of <?= H($dateLabel($week['week_start'])) ?></small></span><span class="action-row-meta"><?= number_format((float)$week['hours'], 2) ?> hrs <?= UiIcon('chevron-right') ?></span></a>

                <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </section>

        <?php endif; ?>

        <?php if ($team !== null): ?>
        <section class="card action-panel" aria-labelledby="sectionStatusTitle" <?= $widgetAttributes('team', 'Team progress') ?>>
            <div class="action-panel-head">
                <div><span class="dashboard-overline">TEAM MONITORING</span>
                    <h2 id="sectionStatusTitle"><?= Permissions::IsAdmin() ? 'Organization' : 'Your section' ?> this week</h2>
                </div><a href="<?= H($path('teammonitoring', ['week_start' => $actions['week_start']])) ?>">View team <?= UiIcon('arrow-right') ?></a>
            </div>

            <?php if (!empty($team['unassigned'])): ?>
            <p class="action-note">Ask an administrator to assign your account to a unit / section to see team progress.</p>

            <?php else: ?>
            <div class="action-team-stats">
                <div><strong data-action="team-people"><?= $team['people'] ?></strong><span>Personnel</span></div>
                <div><strong><?= $team['complete'] ?></strong><span>Weekly hours complete</span></div>
                <div><strong><?= $team['due_people'] ?></strong><span>With hours due</span></div>
            </div>
            <p class="action-note"><?= number_format($team['due_hours'], 2) ?> hours are missing across due working days. Weekly completion uses entered hours; approval remains a separate step.</p>

            <?php endif; ?>
        </section>

        <?php endif; ?>

        <?php if ($renewals !== null): ?>
        <section class="card action-panel" <?= $widgetAttributes('renewals', 'Renewals') ?>>
            <div class="action-panel-head">
                <div><span class="dashboard-overline">INVENTORY</span>
                    <h2>Upcoming and overdue renewals</h2>
                </div><a href="<?= H($path('servers', ['tab' => 'renewals','status' => 'attention'])) ?>">View renewals <?= UiIcon('arrow-right') ?></a>
            </div>

            <?php if (!$renewals['count']): ?>
            <div class="action-empty"><?= UiIcon('shield-check') ?><strong>No renewals currently need attention.</strong></div>

            <?php else: ?>
            <div class="action-list">

                <?php foreach ($renewals['items'] as $item): ?>
                <a href="<?= H($path('servers', ['renewal_id' => $item['expiry_id']])) ?>"><span><strong><?= H($item['item_name']) ?></strong><small><?= $item['expiry_date'] < $actions['today'] ? 'Overdue' : 'Due' ?> <?= H(Date('M j, Y', strtotime($item['expiry_date']))) ?></small></span><?= UiIcon('arrow-right') ?></a>

                <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </section>

        <?php endif; ?>
    <?php if ($inventory): ?>
    <section class="card action-panel" <?= $widgetAttributes('records', 'Workspace records') ?>>
        <div class="action-panel-head">
            <h2>Workspace records</h2>
        </div>
        <div class="action-record-counts">

            <?php foreach ($inventory as $item): ?>
            <a href="<?= H($path($item['module'])) ?>"><?= UiIcon($item['icon']) ?><span><?= H($item['label']) ?></span><strong><?= $item['count'] ?></strong></a>

            <?php endforeach; ?>
        </div>
    </section>

    <?php endif; ?>
    <section class="card action-panel" <?= $widgetAttributes('links', 'Saved access links') ?>>
        <div class="action-panel-head">
            <h2>Saved access links</h2><a href="<?= H($path('bookmarks')) ?>">View all <?= UiIcon('arrow-right') ?></a>
        </div>
        <div class="action-links">

            <?php foreach ($links as $link): $url = AccessLinks::SafeUrl($link['url']);
    if (!$url)
    {
        continue;
    }
    ?>
            <a
                href="<?= H($url) ?>"
                target="_blank"
                rel="noopener noreferrer"
            ><?= UiIcon('external-link') ?><span><?= H($link['title']) ?></span><?= UiIcon('arrow-up-right') ?></a>

            <?php endforeach; ?>

            <?php if (!$links): ?>
            <p class="action-note">No shared access links yet.</p>

            <?php endif; ?>
        </div>
    </section>

    </div><!-- /dashboardGrid -->
    <section class="card dashboard-no-widgets" id="dashboardNoWidgets" hidden>
        <h2>Make this space yours</h2><p>Your dashboard widgets are hidden. Choose Customize to bring back the ones you need.</p>
        <button type="button" class="btn btn-primary" data-dashboard-customize>Choose widgets</button>
    </section>
    <?php endif; ?>
    <script type="application/json" id="dashboardPreferenceState"><?= json_encode($uiState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?= view('home/customizer', get_defined_vars()) ?>
</div>

<?php echo view('layouts/footer', get_defined_vars());
?>

