<?php
/**
 * Filename: servers_helper.php
 * Purpose: Provide reusable Servers request validation and export helpers.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

function JoinEnvironments($value): ?string
{
    if (is_array($value))
    {
        $clean = array_filter(array_map('trim', $value), fn ($v) => $v !== '');
        return $clean ? implode(', ', $clean) : null;
    }
    $value = CleanStr($value);
    return $value;
}
