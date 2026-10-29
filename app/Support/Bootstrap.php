<?php
/**
 * Filename: Bootstrap.php
 * Purpose: Normalize compatibility requests before CodeIgniter builds the request URI.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Share the configured application base path across Apache and fixtures.
 */
if (defined('DEVATLAS_CI'))
{
    $_SERVER['SCRIPT_NAME'] = APP_BASE_URL . '/index.php';
}
