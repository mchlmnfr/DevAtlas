<?php

/**
 * Filename: vendor_bootstrap.php
 * Purpose: Provide vendor bootstrap processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/**
 * DevAtlas :: vendor_bootstrap.php
 * Loads Composer's autoloader for features that depend on a vendored
 * package (currently: PhpSpreadsheet, used by the Diagram Generator's
 * Excel import/export). Fails with a clear, actionable message instead
 * of a raw fatal error if `composer install` hasn't been run yet.
 */

function RequireVendorAutoload(): void
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload))
    {
        JsonError(
            "This feature needs Composer dependencies that aren't installed yet. " .
            "Open a terminal in the application folder and run: composer install " .
            "(requires PHP's Composer tool and one-time internet access to download " .
            "the package; the app itself still runs fully offline afterward).",
            500
        );
    }
    require_once $autoload;
}
