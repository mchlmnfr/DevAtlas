<?php
/**
 * Filename: approval_tabs.php
 * Purpose: Display permission-aware links to the separate daily-log and leave approval queues.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<nav class="approval-tabs" aria-label="Approval queues">

    <?php if (Permissions::Can('reviewlogs')): ?>
    <a
        class="btn <?= $approvalTab === 'dailylogs' ? 'btn-primary' : '' ?>"
        href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/approvals/index.php')) ?>?tab=dailylogs"
        <?= $approvalTab === 'dailylogs' ? 'aria-current="page"' : '' ?>
    ><?= UiIcon('clipboard-check') ?> Daily Logs</a>

    <?php endif; ?>

    <?php if (Permissions::Can('leaves', 'review')): ?>
    <a
        class="btn <?= $approvalTab === 'leaves' ? 'btn-primary' : '' ?>"
        href="<?= H(RouteUrls::Url(APP_BASE_URL . '/modules/approvals/index.php')) ?>?tab=leaves"
        <?= $approvalTab === 'leaves' ? 'aria-current="page"' : '' ?>
    ><?= UiIcon('calendar-days') ?> Leave</a>

    <?php endif; ?>
</nav>
