<?php

/**
 * Filename: config.php
 * Purpose: Provide config processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/**
 * DevAtlas :: Global configuration
 * Edit these values for your local WAMP + PostgreSQL setup.
 * No values here are ever sent externally — everything is local.
 */

require_once __DIR__ . '/LocalSecrets.php';
require_once __DIR__ . '/../lib/RouteUrls.php';

// ---- PostgreSQL connection (the app's OWN metadata database) --------
define('DB_HOST', getenv('DEVATLAS_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DEVATLAS_DB_PORT') ?: '5432');
define('DB_NAME', getenv('DEVATLAS_DB_NAME') ?: 'analyst_toolkit');
define('DB_USER', getenv('DEVATLAS_DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DEVATLAS_DB_PASS') !== false ? getenv('DEVATLAS_DB_PASS') : LocalSecrets::Get('DB_PASS'));
// Installation secrets are maintained with bin/configure-secrets.php.
define('DB_SCHEMA', 'toolkit');

// ---- Server Credentials Vault encryption key ----
// Used by lib/Crypto.php (AES-256-CBC) to encrypt passwords stored in
// server_credentials. The existing value is retained in encrypted installation
// configuration. Preserve it when moving the installation or restoring a backup.
define('APP_ENCRYPTION_KEY', getenv('DEVATLAS_VAULT_KEY') !== false ? getenv('DEVATLAS_VAULT_KEY') : LocalSecrets::Get('APP_ENCRYPTION_KEY'));

// ---- Legacy / no longer used by the app ----
// Previously the Data Dictionary's "Import from DB" feature always connected
// to a single fixed target DB defined here. It's now fully self-service from
// the UI (pick engine/host/port/user/password/dbname/schema, or save a named
// connection profile) — see modules/datadict/index.php → "Import from DB".
// These constants are kept only in case other code you add wants a default.
define('TARGET_DB_HOST', getenv('DEVATLAS_DB_HOST') ?: '127.0.0.1');
define('TARGET_DB_PORT', getenv('DEVATLAS_DB_PORT') ?: '5432');
define('TARGET_DB_NAME', getenv('DEVATLAS_DB_NAME') ?: 'analyst_toolkit');
define('TARGET_DB_USER', getenv('DEVATLAS_DB_USER') ?: 'postgres');
define('TARGET_DB_PASS', getenv('DEVATLAS_DB_PASS') !== false ? getenv('DEVATLAS_DB_PASS') : LocalSecrets::Get('TARGET_DB_PASS'));
define('TARGET_DB_SCHEMA', 'public');

// ---- App ----
define('APP_NAME', 'DevAtlas');
define('APP_BASE_URL', '/AnalystToolkit');
// matches WAMP www subfolder
define('APP_TIMEZONE', 'Asia/Singapore');

date_default_timezone_set(APP_TIMEZONE);

// CodeIgniter owns web exception handling; CLI maintenance retains its existing handlers.
if (!defined('DEVATLAS_CI'))
{
// Error visibility (turn off display_errors in production)
    ini_set('display_errors', 1);
    error_reporting(E_ALL);

// Catch anything that slips past a module's own try/catch and log it to
// logs/app-YYYY-MM-DD.log (see the "Error Logs" page in the sidebar),
// instead of it only ever showing up as a raw HTML error or in Apache's log.
    require_once __DIR__ . '/../lib/Logger.php';

    set_exception_handler(function (Throwable $e)
    {
    Logger::Log('fatal', $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
    ]);
    http_response_code(500);
    if (!headers_sent())
    {
        Header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'An unexpected server error occurred. Check the Error Logs page for details.']);
    });

    set_error_handler(function ($severity, $message, $file, $line)
    {
    if (!(error_reporting() & $severity))
    {
        return false;
    }
    // respects @-suppression
    Logger::Log('php_warning', $message, ['file' => $file, 'line' => $line]);
    return false;
    // still let PHP's normal handling (display_errors, etc.) run too
    });

}
require_once __DIR__ . '/../lib/Logger.php';

// Keepalive activity reports must never replace a newer browser login cookie.
$isAuditCollector = PHP_SAPI !== 'cli' && (in_array(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), [APP_BASE_URL.'/modules/audit/client.php', APP_BASE_URL.'/modules/audit/client'], true));
if ($isAuditCollector)
{
    $auditSession = $_COOKIE[session_name()] ?? '';
    if (!is_string($auditSession) || !preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $auditSession))
    {
        http_response_code(401);
        Header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Sign in to report activity.']);
        exit;
    }
    session_id($auditSession);
    session_start(['use_cookies' => false,'use_strict_mode' => true]);
    if (session_id() !== $auditSession)
    {
        session_destroy();
        http_response_code(401);
        Header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Your activity session has expired.']);
        exit;
    }
}
else
{
    if (session_status() !== PHP_SESSION_ACTIVE)
    {
        session_start();
    }
}

require_once __DIR__ . '/../lib/Audit.php';
Audit::Begin();
