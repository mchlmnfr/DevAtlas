<?php
/**
 * Filename: search_results.php
 * Purpose: Return escaped, permission-scoped record fragments to HTMX search.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Render CodeIgniter layouts and align first-party template markup.
 */
?>
<div data-search-query="<?= H(mb_strtolower(trim($query))) ?>">

    <?php foreach ($results as $record): ?>
    <a
        data-record
        data-title="<?= H($record['title']) ?>"
        data-detail="<?= H($record['detail']) ?>"
        data-type="<?= H($record['type']) ?>"
        data-icon="<?= H($record['icon']) ?>"
        href="<?= H($record['href']) ?>"
    ><?= H($record['title']) ?></a>

    <?php endforeach; ?>
</div>
