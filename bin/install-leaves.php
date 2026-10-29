<?php

/**
 * Filename: install-leaves.php
 * Purpose: Install the independent leave module without changing historical requests.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Apply the leave permissions and calendar index migration.
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
    fwrite(STDERR, 'Leave migration failed: '.$e->getMessage()."\n");
    exit(1);
});
MigrationRunner::Run(Database::GetConnection('app'), __DIR__.'/../database/migration_029_leave_module.sql');
MigrationRunner::Run(Database::GetConnection('app'), __DIR__.'/../database/migration_030_leave_ranges.sql');
MigrationRunner::Run(Database::GetConnection('app'), __DIR__.'/../database/migration_031_user_birthdays.sql');
echo "Migrations 029-031 applied. Leave ranges, section approvals and calendar birthdays are ready.\n";
