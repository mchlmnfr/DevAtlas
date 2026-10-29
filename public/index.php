<?php
/**
 * Filename: index.php
 * Purpose: Bootstrap the CodeIgniter HTTP application.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Integrate CodeIgniter with preserved DevAtlas security contracts.
 */

use CodeIgniter\Boot;
use Config\Paths;

if (version_compare(PHP_VERSION, '8.1.0', '<'))
{
    http_response_code(503);
    exit('DevAtlas requires PHP 8.1 or newer.');
}
define('DEVATLAS_CI', true);
defined('FCPATH') || define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
require_once __DIR__ . '/../app/Config/Paths.php';
$paths = new Paths();
require_once $paths->systemDirectory . '/Boot.php';
if (defined('APP_BASE_URL'))
{
    $_SERVER['SCRIPT_NAME'] = APP_BASE_URL . '/index.php';
}
exit(Boot::bootWeb($paths));
