<?php

/**
 * Filename: audit.php
 * Purpose: Verify audit behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\Exceptions\DatabaseException;

/** Transactional audit fixtures; isolated schema, no real account or record changes. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__.'/../config/Database.php';
require_once __DIR__.'/../lib/AuditQuery.php';
set_exception_handler(static function (Throwable $e): void
{
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
});
set_error_handler(static function ($severity, $message, $file, $line)
{
    if (error_reporting() & $severity)
    {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
$db = Database::GetConnection('app');
$schema = 'audit_fixture_'.bin2hex(random_bytes(6));
$checks = 0;
$temp = __DIR__.'/.tmp-audit-'.getmypid();
mkdir($temp);
$logger = new ReflectionProperty(Logger::class, 'directory');
$logger->setAccessible(true);
$logger->setValue(null, $temp);
function AuCheck(bool $isOk, string $message): void
{
    global $checks;
    $checks++;
    if (!$isOk)
    {
        throw new RuntimeException($message);
    }
}
function AuReject(callable $fn, string $message): void
{
    try
    {
        $fn();
    }
    catch (InvalidArgumentException|DatabaseException $e)
    {
        AuCheck(true, $message);
        return;
    }
    AuCheck(false, $message);
}
function AuBegin(string $module, string $action): void
{
    global $db;
    foreach (['isActive' => false,'isFinished' => false,'db' => null,'context' => [],'outcome' => null] as $key => $value)
    {
        $p = new ReflectionProperty(Audit::class, $key);
        $p->setAccessible(true);
        $p->setValue(null, $value);
    }
    $_SERVER['REQUEST_URI'] = APP_BASE_URL.'/modules/'.$module.'/api.php?action='.$action.'&token=SECRET_TOKEN_SENTINEL';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = ['action' => $action,'token' => 'SECRET_TOKEN_SENTINEL'];
    $_POST = [];
    Audit::Begin(true);
    Audit::Attach($db);
}
try
{
    $db->query(str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/schema.sql')));
    $db->query('SET search_path TO "'.$schema.'",public');
    $migration = str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/migration_017_audit_logs.sql'));
    $db->query($migration);
    $db->query($migration);
    AuCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='$schema' AND t.tgname='audit_row_change'")) === 43, 'Every domain table, including leave applications, has a change trigger.');
    $_SESSION = ['user_id' => 42,'employee_id' => 'AUDIT-FIXTURE','full_name' => 'Audit Reviewer'];
    AuBegin('bookmarks', 'save_bookmark');
    Audit::Input(['password' => 'PASSWORD_SENTINEL','token' => 'SECRET_TOKEN_SENTINEL','source' => 'SOURCE_SENTINEL','bookmark_id' => 9,'engine' => 'qms']);
    $db->query("INSERT INTO bookmarks(title,url,description)VALUES(?,?,?)", ['TITLE_SENTINEL','https://user:PASSWORD_SENTINEL@example.invalid','SOURCE_SENTINEL']);
    $bookmark = (int)Database::Scalar($db->query('SELECT MAX(bookmark_id) FROM bookmarks'));
    Audit::Response(['ok' => true,'bookmark_id' => $bookmark,'password' => 'PASSWORD_SENTINEL']);
    Audit::Finish();
    $event = $db->query('SELECT * FROM audit_events ORDER BY event_id DESC LIMIT 1')->getUnbufferedRow('array');
    AuCheck($event && $event['outcome'] === 'success' && (int)$event['actor_id'] === 42, 'Request finalizes with the authenticated actor.');
    AuCheck($event['path'] === APP_BASE_URL.'/modules/bookmarks/api.php' && !str_contains(json_encode($event), 'SECRET_TOKEN_SENTINEL'), 'Raw query strings and reset tokens are excluded.');
    $change = $db->query('SELECT * FROM audit_changes ORDER BY change_id DESC LIMIT 1')->getUnbufferedRow('array');
    AuCheck($change['record_id'] === (string)$bookmark && $change['operation'] === 'INSERT', 'Created record reference and operation are captured.');
    AuCheck(!str_contains(json_encode([$event,$change]), 'PASSWORD_SENTINEL') && !str_contains(json_encode([$event,$change]), 'SOURCE_SENTINEL') && !str_contains(json_encode([$event,$change]), 'TITLE_SENTINEL'), 'No credential, record text or source payload enters the audit.');
    AuBegin('usermgmt', 'save_user');
    $db->query("UPDATE users SET is_locked=TRUE,password_hash=? WHERE user_id=(SELECT MIN(user_id) FROM users)", ['HASH_SECRET_SENTINEL']);
    Audit::Finish();
    $change = $db->query('SELECT * FROM audit_changes ORDER BY change_id DESC LIMIT 1')->getUnbufferedRow('array');
    AuCheck(str_contains($change['changed_fields'], 'password_hash') && !str_contains(json_encode($change), 'HASH_SECRET_SENTINEL'), 'Password changes retain the field name but never its hash.');
    AuCheck(json_decode($change['before_values'], true)['is_locked'] === false && json_decode($change['after_values'], true)['is_locked'] === true, 'Safe status before/after values are retained.');
    AuBegin('settings', 'save_settings');
    $db->query('UPDATE system_settings SET default_password=?,smtp_password=?,max_login_attempts=8 WHERE id=1', ['DEFAULT_SECRET_SENTINEL','SMTP_SECRET_SENTINEL']);
    Audit::Finish();
    $change = $db->query('SELECT * FROM audit_changes ORDER BY change_id DESC LIMIT 1')->getUnbufferedRow('array');
    AuCheck(!str_contains(json_encode($change), 'SECRET_SENTINEL') && json_decode($change['after_values'], true)['max_login_attempts'] === 8, 'Parameter passwords are excluded while safe policy changes remain readable.');
    AuBegin('bookmarks', 'delete_bookmark');
    $db->transBegin();
    $db->query('DELETE FROM bookmarks WHERE bookmark_id=?', [$bookmark]);
    $db->transRollback();
    Audit::Result('failed');
    Audit::Finish();
    $event = $db->query('SELECT * FROM audit_events ORDER BY event_id DESC LIMIT 1')->getUnbufferedRow('array');
    $stmtSql = 'SELECT COUNT(*) FROM audit_changes WHERE request_id=?';
    $stmt = $db->query($stmtSql, [$event['request_id']]);
    AuCheck($event['outcome'] === 'failed' && (int)Database::Scalar($stmt) === 0, 'Rolled-back writes are not presented as committed changes.');
    AuBegin('bookmarks', 'save_bookmark');
    $db->transBegin();
    $db->query("UPDATE bookmarks SET title='Uncommitted' WHERE bookmark_id=?", [$bookmark]);
    Audit::Finish();
    AuCheck(Database::Scalar($db->query('SELECT title FROM bookmarks WHERE bookmark_id='.$bookmark)) === 'TITLE_SENTINEL', 'Unfinished business transactions are rolled back before finalization.');
    AuCheck(Database::Scalar($db->query('SELECT outcome FROM audit_events ORDER BY event_id DESC LIMIT 1')) === 'failed', 'Uncommitted requests are visibly marked failed.');
    AuReject(fn () => $db->query("UPDATE audit_events SET actor_name='Changed'"), 'Finalized events cannot be rewritten.');
    AuReject(fn () => $db->query('DELETE FROM audit_events'), 'Events cannot be deleted.');
    AuReject(fn () => $db->query('DELETE FROM audit_changes'), 'Record-change history cannot be deleted.');
    AuReject(fn () => $db->query("UPDATE audit_changes SET record_id='changed'"), 'Record-change history cannot be rewritten.');
    AuReject(fn () => $db->query('TRUNCATE audit_changes'), 'Row-change history cannot be truncated.');
    AuReject(fn () => $db->query('TRUNCATE audit_events CASCADE'), 'Event history cannot be truncated.');
    $count = Audit::ClientEvents($db, ['page' => APP_BASE_URL.'/modules/codereview/index.php','events' => [['id' => Audit::Uuid(),'kind' => 'click','control' => 'crScanBtn','password' => 'CLIENT_SECRET_SENTINEL']]], ['user_id' => 42,'employee_id' => 'AUDIT-FIXTURE','full_name' => 'Audit Reviewer']);
    AuCheck($count === 1, 'Browser report uses the verified actor.');
    AuCheck(!str_contains(json_encode($db->query("SELECT * FROM audit_events WHERE source='browser'")->getResultArray()), 'CLIENT_SECRET_SENTINEL'), 'Unexpected browser payload fields are discarded.');
    $payload = ['page' => APP_BASE_URL.'/modules/codereview/index.php','events' => [['id' => Audit::Uuid(),'kind' => 'click','control' => 'crScanBtn']]];
    Audit::ClientEvents($db, $payload, ['user_id' => 42,'employee_id' => 'AUDIT-FIXTURE','full_name' => 'Audit Reviewer']);
    AuCheck(Audit::ClientEvents($db, $payload, ['user_id' => 42,'employee_id' => 'AUDIT-FIXTURE','full_name' => 'Audit Reviewer']) === 0, 'Browser retries are idempotent.');
    AuReject(fn () => Audit::ClientEvents($db, ['page' => 'https://outside.invalid/','events' => []], []), 'Unknown page paths are rejected.');
    AuReject(fn () => Audit::ClientEvents($db, ['page' => APP_BASE_URL.'/modules/codereview/index.php','events' => [['id' => [],'kind' => 'click','control' => 'crScanBtn']]], []), 'Malformed browser reports are rejected.');
    $history = AuditQuery::History($db, ['actor' => '','page' => 1,'source' => 'server']);
    AuCheck($history['stats']['total'] === 5 && count($history['events']) === 5, 'History filters and empty user selection work.');
    $detail = AuditQuery::Detail($db, (int)$history['events'][2]['event_id'], 1);
    AuCheck($detail['change_count'] === 1 && is_array($detail['changes'][0]['changed_fields']), 'Detail response decodes changed fields and status values.');
    $anchor = $history['anchor'];
    AuBegin('bookmarks', 'list_bookmarks');
    Audit::Finish();
    AuCheck(AuditQuery::History($db, ['anchor' => $anchor,'source' => 'server'])['stats']['total'] === 5, 'A snapshot boundary keeps pagination stable as more audit events arrive.');
    foreach ([['from' => '2026-02-31'],['from' => '2026-10-26','to' => '2026-10-25'],['module' => 'unknown'],['page' => []],['source' => 'bad']] as $bad)
    {
        AuReject(fn () => AuditQuery::Filters($bad), 'Invalid history filters are rejected.');
    }
    AuCheck(Audit::Route(APP_BASE_URL.'/reset_password.php?token=secret')['path'] === APP_BASE_URL.'/reset_password.php', 'Recovery URLs retain no token query.');
    AuCheck(Audit::Route(APP_BASE_URL.'/assets/test.php') === null, 'Static/unknown paths are not audit routes.');
    AuCheck(Audit::ActionLabel('reveal_credential') === 'Revealed vault credential', 'Sensitive access has a clear action label.');
    AuBegin('datadict', 'import_metadata');
    Audit::Response(['ok' => true,'results' => [['ok' => true],['ok' => false,'error' => 'BULK_SECRET_SENTINEL']]]);
    Audit::Finish();
    $partial = $db->query('SELECT * FROM audit_events ORDER BY event_id DESC LIMIT 1')->getUnbufferedRow('array');
    AuCheck($partial['outcome'] === 'partial' && json_decode($partial['details'], true)['error_count'] === 1 && !str_contains(json_encode($partial), 'BULK_SECRET_SENTINEL'), 'Mixed import results are partial without retaining raw error text.');
    AuCheck(!glob($temp.'/log-*.log'), 'Audit finalization produced no fallback errors.');
    echo "Audit regression fixtures: {$checks} assertions passed.\n";
}
finally
{
    if (Database::InTransaction($db))
    {
        $db->transRollback();
    }
    $db->query("SELECT set_config('analyst.audit_context','',false)");
    $db->query('SET search_path TO public');
    $db->query('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
    foreach (glob($temp.'/*') ?: [] as $file)
    {
        if (is_file($file))
        {
            unlink($file);
        }
    }
    if (file_exists($temp.'/.htaccess'))
    {
        unlink($temp.'/.htaccess');
    }
    rmdir($temp);
}
