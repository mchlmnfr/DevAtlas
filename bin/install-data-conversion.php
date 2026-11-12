<?php
/** CLI only: idempotently register Data Conversion grants in the DevAtlas PostgreSQL database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../lib/MigrationRunner.php';
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'Data Conversion installation failed: ' . $e->getMessage() . "\n");
    exit(1);
});
MigrationRunner::Run(Database::GetConnection('app'), __DIR__ . '/../database/migration_20261110_data_conversion.sql');
echo "Data Conversion is ready under Data Management. Administrators can grant View, Create and Export to other roles.\n";
