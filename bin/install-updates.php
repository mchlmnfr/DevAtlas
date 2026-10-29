<?php

/**
 * Filename: install-updates.php
 * Purpose: Provide install updates processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__.'/../config/Database.php';
require_once __DIR__.'/../lib/MigrationRunner.php';
set_exception_handler(static function (Throwable $e): void
{
    fwrite(STDERR, 'Updates migration failed: '.$e->getMessage()."\n");
    exit(1);
});
MigrationRunner::Run(Database::GetConnection('app'), __DIR__.'/../database/migration_026_head_reviews_updates.sql');
echo "Migration 026 applied. Head-role self-review and update history are ready.\n";
