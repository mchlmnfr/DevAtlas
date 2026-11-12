<?php
/** Install account-owned workspace documents; safe to repeat and retains existing layouts. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_set_save_handler(static fn()=>true,static fn()=>true,static fn()=>'',static fn()=>true,static fn()=>true,static fn()=>0);
require_once __DIR__.'/../config/Database.php';require_once __DIR__.'/../lib/MigrationRunner.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,'Workspace experience installation failed: '.$e->getMessage()."\n");exit(1);});
MigrationRunner::Run(Database::GetConnection('app'),__DIR__.'/../database/migration_20261112_ui_preferences.sql');
MigrationRunner::Run(Database::GetConnection('app'),__DIR__.'/../database/migration_20261112_workspace_experience.sql');
echo "Workspace experience storage is ready. Existing preferences and layouts are preserved.\n";
