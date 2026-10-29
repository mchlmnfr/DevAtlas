<?php

/**
 * Filename: helpers.php
 * Purpose: Provide helpers processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/**
 * DevAtlas :: Shared helper functions
 */

require_once __DIR__ . '/Logger.php';

function JsonResponse($data, int $status = 200): void
{
    if (class_exists('Audit', false))
    {
        Audit::Response($data);
    }
    http_response_code($status);
    Header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    FinishRequest();
}

function JsonError(string $message, int $status = 400): void
{
    Logger::Log($status >= 500 ? 'error' : 'warning', $message, [
        'status' => $status,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    ]);
    JsonResponse(['error' => $message], $status);
}

function ReadJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw)
    {
        return [];
    }
    $data = json_decode($raw, true);
    $data = is_array($data) ? $data : [];
    if (class_exists('Audit', false))
    {
        Audit::Input($data);
    }
    return $data;
}

/** CSRF token helpers (session-based, no external dependency) */
function CsrfToken(): string
{
    if (empty($_SESSION['csrf_token']))
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function CsrfVerify(?string $token): bool
{
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/** Require CSRF header on all state-changing API calls */
function RequireCsrf(): void
{
    $headers = getallheaders();
    $token = $headers['X-CSRF-Token'] ?? $headers['X-Csrf-Token'] ?? '';
    if (!CsrfVerify($token))
    {
        JsonError('Invalid or missing CSRF token.', 419);
    }
}

function H(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Give changed local assets a new URL while keeping browser caching enabled. */
function AssetUrl(string $path): string
{
    static $urls = [];
    $path = ltrim($path, '/');
    if (!isset($urls[$path]))
    {
        $file = __DIR__ . '/../' . $path;
        $version = is_file($file) ? substr(hash_file('sha256', $file), 0, 12) : '1';
        $urls[$path] = APP_BASE_URL . '/' . $path . '?v=' . $version;
    }
    return $urls[$path];
}

function CleanStr($v): ?string
{
    if ($v === null)
    {
        return null;
    }
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function ToBool($v): bool
{
    return in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true);
}

/**
 * Converts a PHP bool to a Postgres-safe literal for PDO binding.
 * PDO (with emulated prepares off) binds PHP booleans as strings by
 * casting them: true -> "1" (fine), false -> "" (NOT a valid Postgres
 * boolean literal -> "invalid input syntax for type boolean" error).
 * Always pass boolean values destined for a boolean column through
 * this function before putting them in a PDO execute() params array.
 */
function PgBool($v): string
{
    return ToBool($v) ? 'true' : 'false';
}

/** Simple slug for node keys used inside GraphML export */
function SafeKey(string $prefix, $id): string
{
    return $prefix . '_' . preg_replace('/[^A-Za-z0-9_]/', '', (string)$id);
}

require_once __DIR__.'/Icons.php';

/** Complete legacy-compatible output while allowing framework filters to run. */
function FinishRequest(): never
{
    if (defined('DEVATLAS_CI'))
    {
        throw new \App\Http\ResponseComplete();
    }
    exit;
}
