<?php

/**
 * Filename: notifications.php
 * Purpose: Provide notifications processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Run every 15 minutes using the operating system scheduler. CLI only. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__.'/../lib/Notifications.php';
try
{
    $db = Database::GetConnection('app');
    $result = Notifications::Generate($db);
    if (in_array('--deliver', $argv, true))
    {
        $result += Notifications::Deliver($db);
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;
}
catch (Throwable $e)
{
    Logger::Log('error', 'Notification worker failed.', ['error_class' => get_class($e),'code' => (string)$e->getCode()]);
    fwrite(STDERR, "Notification worker failed; check Error Logs.\n");
    exit(1);
}
