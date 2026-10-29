<?php

/**
 * Filename: install-daily-logs.php
 * Purpose: Provide install daily logs processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Apply the daily log migrations to an existing installation. */
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
    fwrite(STDERR, "Daily log migration failed: ".$e->getMessage()."\n");
    exit(1);
});
$db = Database::GetConnection('app');
MigrationRunner::Run($db, __DIR__.'/../database/migration_020_daily_logs.sql');
MigrationRunner::Run($db, __DIR__.'/../database/migration_021_daily_log_reviewers.sql');
MigrationRunner::Run($db, __DIR__.'/../database/migration_022_section_reports.sql');
MigrationRunner::Run($db, __DIR__.'/../database/migration_023_reports_module.sql');
MigrationRunner::Run($db, __DIR__.'/../database/migration_024_weekly_approval_holidays.sql');
MigrationRunner::Run($db, __DIR__.'/../database/migration_025_wiki_holidays_modules.sql');
MigrationRunner::Run(Database::GetConnection('app'), __DIR__.'/../database/migration_026_head_reviews_updates.sql');
echo "Migration 026 applied with Wiki/holiday prerequisites. Head-role self-review and update history are ready.\n";
