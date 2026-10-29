<?php
/**
 * Filename: proginventory_helper.php
 * Purpose: Provide reusable Proginventory request validation and export helpers.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

function PiPathExcluded(string $relativePath, array $localExcludeFolders): bool
{
    if (!$localExcludeFolders)
    {
        return false;
    }
    $segments = array_slice(explode('/', str_replace('\\', '/', $relativePath)), 0, -1);
    foreach ($segments as $seg)
    {
        if (in_array($seg, $localExcludeFolders, true))
        {
            return true;
        }
    }
    return false;
}
