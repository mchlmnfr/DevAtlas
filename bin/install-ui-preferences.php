<?php
/**
 * Filename: install-ui-preferences.php
 * Purpose: Install the account-owned UI preference table without changing existing choices.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Add personal dashboard layouts and shared motion controls.
 */
/** CLI only: install account-owned dashboard and motion preference storage. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../lib/MigrationRunner.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Workspace personalization installation failed: ' . $error->getMessage() . "\n");
    exit(1);
});
MigrationRunner::Run(Database::GetConnection('app'), __DIR__ . '/../database/migration_20261112_ui_preferences.sql');
echo "Dashboard personalization and account motion preferences are ready. Existing preferences are preserved.\n";
