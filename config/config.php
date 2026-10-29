<?php

/**
 * Filename: config.php
 * Purpose: Expose environment configuration to existing DevAtlas services.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Move installation settings to .env without changing their constant names or vault key.
 */

/**
 * DevAtlas :: Global configuration
 * Set installation values in the ignored root .env file.
 * No values here are ever sent externally — everything is local.
 */

require_once __DIR__ . '/Environment.php';
require_once __DIR__ . '/../lib/RouteUrls.php';
Environment::Load();

// ---- PostgreSQL connection (the app's OWN metadata database) --------
define('DB_HOST', Environment::Get('DB_HOST'));
define('DB_PORT', Environment::Get('DB_PORT'));
define('DB_NAME', Environment::Get('DB_NAME'));
define('DB_USER', Environment::Get('DB_USER'));
define('DB_PASS', Environment::Get('DB_PASS', true));
define('DB_SCHEMA', Environment::Get('DB_SCHEMA'));

// ---- Server Credentials Vault encryption key ----
// Used by lib/Crypto.php (AES-256-CBC) to encrypt passwords stored in
// server_credentials. Preserve this exact value when moving configuration;
// replacing it would make existing vault records unreadable.
define('APP_ENCRYPTION_KEY', Environment::Get('APP_ENCRYPTION_KEY'));

// ---- Legacy / no longer used by the app ----
// Previously the Data Dictionary's "Import from DB" feature always connected
// to a single fixed target DB defined here. It's now fully self-service from
// the UI (pick engine/host/port/user/password/dbname/schema, or save a named
// connection profile) — see modules/datadict/index.php → "Import from DB".
// These constants are kept only in case other code you add wants a default.
define('TARGET_DB_HOST', Environment::Get('TARGET_DB_HOST'));
define('TARGET_DB_PORT', Environment::Get('TARGET_DB_PORT'));
define('TARGET_DB_NAME', Environment::Get('TARGET_DB_NAME'));
define('TARGET_DB_USER', Environment::Get('TARGET_DB_USER'));
define('TARGET_DB_PASS', Environment::Get('TARGET_DB_PASS', true));
define('TARGET_DB_SCHEMA', Environment::Get('TARGET_DB_SCHEMA'));

// ---- App ----
define('APP_NAME', Environment::Get('APP_NAME'));
define('APP_BASE_URL', rtrim(Environment::Get('APP_BASE_URL', true), '/'));
define('APP_TIMEZONE', Environment::Get('APP_TIMEZONE'));

date_default_timezone_set(APP_TIMEZONE);

// CodeIgniter owns web exception handling; CLI maintenance retains its existing handlers.
if (!defined('DEVATLAS_CI'))
{
// Keep standalone commands consistent with the configured framework environment.
    ini_set('display_errors', Environment::Get('CI_ENVIRONMENT') === 'development' ? '1' : '0');
    error_reporting(E_ALL);

// Catch anything that slips past a module's own try/catch and log it to
// CodeIgniter's configured file handler (normally writable/logs/log-YYYY-MM-DD.log),
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
