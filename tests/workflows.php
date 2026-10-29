<?php

/**
 * Filename: workflows.php
 * Purpose: Verify workflows behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\Exceptions\DatabaseException;

/** Role boundaries, saved review privacy, catalog links and reminder semantics. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__.'/../lib/ReviewStore.php';
require_once __DIR__.'/../lib/CodeReview.php';
require_once __DIR__.'/../lib/Notifications.php';
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
$schema = 'workflows_fixture_'.bin2hex(random_bytes(6));
$checks = 0;
function WfCheck(bool $isOk, string $message): void
{
    global $checks;
    $checks++;
    if (!$isOk)
    {
        throw new RuntimeException($message);
    }
}
function WfReject(callable $fn, string $message): void
{
    try
    {
        $fn();
    }
    catch (InvalidArgumentException|OutOfBoundsException|DatabaseException $e)
    {
        WfCheck(true, $message);
        return;
    }
    WfCheck(false, $message);
}
function WfActor(string $role): array
{
    global $db;
    $stmtSql = 'SELECT * FROM users WHERE role_key=? ORDER BY user_id LIMIT 1';
    $stmt = $db->query($stmtSql, [$role]);
    $u = $stmt->getUnbufferedRow('array');
    $_SESSION = ['user_id' => $u['user_id'],'employee_id' => $u['employee_id'],'full_name' => $u['first_name'].' '.$u['last_name']];
    Permissions::Reset();
    return ['user_id' => $u['user_id'],'full_name' => $_SESSION['full_name']];
}
try
{
    $db->query(str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/schema.sql')));
    $db->query('SET search_path TO "'.$schema.'",public');
    $migration = str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/migration_018_workflow.sql'));
    $db->query($migration);
    $db->query($migration);
    $stmtSql = "INSERT INTO users(employee_id,password_hash,first_name,last_name,role_key,must_change_password,password_changed_at,email)VALUES(?,'fixture hash',?,'Fixture',?,FALSE,NOW(),?)";
    foreach (['analyst','reviewer','viewer'] as $role)
    {
        $stmt = $db->query($stmtSql, [$role,ucfirst($role),$role,$role.'@example.invalid']);
    }
    WfCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='$schema' AND t.tgname='audit_row_change'")) === 43, 'Domain tables, including leave applications, are covered by audit capture.');
    $admin = WfActor('admin');
    WfCheck(Permissions::Can('permissions', 'edit') && Permissions::Can('servers', 'credentials'), 'Administrator has full action access.');
    $analyst = WfActor('analyst');
    WfCheck(Permissions::Can('codereview', 'create') && Permissions::Can('teammonitoring') && !Permissions::Can('applications') && !Permissions::Can('requirements'), 'Analyst can scan and monitor their section; Applications and Requirements require explicit grants.');
    WfCheck(!Permissions::Can('usermgmt') && !Permissions::Can('servers', 'credentials') && !Permissions::Can('codereview', 'review'), 'Analyst does not inherit administration, vault or reviewer rights.');
    $reviewer = WfActor('reviewer');
    WfCheck(Permissions::Can('codereview', 'review') && !Permissions::Can('codereview', 'create'), 'Reviewer can make decisions but cannot start scans by default.');
    $viewer = WfActor('viewer');
    WfCheck(Permissions::Can('datadict') && !Permissions::Can('datadict', 'edit') && !Permissions::Can('codereview', 'export'), 'Viewer has read-only business access.');
    $_SESSION = [];
    Permissions::Reset();
    WfCheck(Permissions::Can('directory') && Permissions::Can('bookmarks') && !Permissions::Can('applications'), 'Guest access remains limited to the existing public modules.');
    WfActor('admin');
    $db->transBegin();
    WfReject(fn () => Permissions::GuardAccount($db, (int)$admin['user_id'], 'viewer'), 'The last active Administrator cannot be demoted.');
    $db->transRollback();
    WfActor('analyst');
    $db->transBegin();
    WfReject(fn () => Permissions::GuardAccount($db, (int)$admin['user_id']), 'Non-administrators cannot change an Administrator account.');
    $db->transRollback();
    WfCheck(Permissions::Operation('bookmarks', 'api.php', 'save_bookmark', 'POST', ['bookmark_id' => 'garbage']) === 'create', 'A malformed ID cannot turn a creation into an edit permission.');
    WfCheck(Permissions::Operation('codereview', 'history_api.php', 'findings_update', 'POST') === 'review', 'Finding decisions require review permission.');
    WfCheck(Permissions::Operation('servers', 'api.php', 'reveal_credential', 'GET') === 'credentials', 'Credential reveal requires its dedicated permission.');
    WfActor('admin');
    $db->query("UPDATE role_permissions SET is_allowed=TRUE WHERE role_key='viewer' AND module='datadict' AND permission='export'");
    $db->query($migration);
    WfActor('viewer');
    WfCheck(Permissions::Can('datadict', 'export'), 'Reapplying the migration preserves customized permissions.');
    WfActor('admin');
    $app = Applications::Save($db, ['application_code' => 'fixture','application_name' => 'Fixture application','owner_user_id' => $viewer['user_id']], (int)$admin['user_id']);
    WfCheck(Applications::Get($db, $app)['application_code'] === 'FIXTURE', 'Application codes are normalized.');
    WfReject(fn () => Applications::Save($db, ['application_code' => 'bad code','application_name' => 'Bad'], 1), 'Application codes are validated.');
    WfReject(fn () => Applications::Url('https://name:password@example.invalid/'), 'Application links cannot embed credentials.');
    WfReject(fn () => Applications::Url('javascript:alert(1)'), 'Application links reject executable URL schemes.');
    $db->query("INSERT INTO servers(environment,server_name,ip_address)VALUES('Dev','Fixture server','127.0.0.1')");
    $server = (int)Database::Scalar($db->query('SELECT MAX(server_id) FROM servers'));
    Applications::Link($db, $app, 'server', $server);
    Applications::Link($db, $app, 'server', $server);
    WfCheck(count(Applications::Links($db, $app)) === 1, 'Repeated asset linking is idempotent.');
    WfReject(fn () => $db->query('INSERT INTO application_assets(application_id,server_id,bookmark_id)VALUES('.$app.','.$server.',1)'), 'A catalog link cannot point to multiple asset types.');
    WfReject(fn () => Applications::Link($db, $app, 'server', 999999), 'Catalog links validate the target asset.');
    $source = '<?php $password="RAW_SECRET_SENTINEL"; echo $_GET["name"]; eval($_POST["code"]);';
    $report = CodeReview::ScanClient([['path' => 'project/test.php','content' => $source,'modified' => time()]], CodeReview::Options(['engine' => 'owasp']), 'Fixture project');
    WfCheck($report['engine'] === 'owasp' && count($report['owasp']['categories']) === 10, 'OWASP reports include all ten 2025 categories.');
    $rules = array_column($report['files'][0]['findings'], 'rule');
    WfCheck(in_array('OWASP-SECRET', $rules, true) && in_array('OWASP-XSS-PHP', $rules, true) && in_array('OWASP-EVAL', $rules, true), 'OWASP detects credential-like literals, raw output and dynamic execution.');
    WfCheck(!str_contains(json_encode($report['files'][0]['findings']), 'RAW_SECRET_SENTINEL'), 'OWASP findings never quote a matched secret.');
    WfCheck($report['owasp']['categories'][5]['coverage'] === 'Manual' && $report['owasp']['categories'][5]['status'] === 'Manual review required', 'Insecure Design is explicitly a manual check, not a pass.');
    $clean = CodeReview::ScanClient([['path' => 'clean.php','content' => '<?php /* eval($_GET["x"]); */ echo "safe";','modified' => time()]], CodeReview::Options(['engine' => 'owasp']), 'Clean');
    WfCheck($clean['finding_count'] === 0 && $clean['owasp']['categories'][0]['status'] === 'Manual review required', 'Comment-only patterns are excluded and clean scans do not certify compliance.');
    $id = ReviewStore::Save($db, $report, ['application_id' => $app,'mode' => 'client'], $admin);
    $saved = ReviewStore::Get($db, $id);
    $rows = $db->query('SELECT * FROM review_findings WHERE review_id='.$id)->getResultArray();
    WfCheck(count($rows) === $report['finding_count'], 'Every server finding is persisted.');
    WfCheck(!str_contains(json_encode([$saved,$rows]), 'RAW_SECRET_SENTINEL') && !array_key_exists('content', $saved['report_meta']['files'][0]), 'Saved reviews omit raw source and literal secret values.');
    $db->query("UPDATE review_findings SET status='Accepted',disposition_note='Verified fixture exception' WHERE finding_id=?", [$rows[0]['finding_id']]);
    $second = ReviewStore::Save($db, $report, ['application_id' => $app,'mode' => 'client'], $admin);
    WfCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM review_findings WHERE review_id=$second AND status='Accepted'")) === 1, 'An explicitly accepted matching finding carries to the next comparable run.');
    $comparison = ReviewStore::Comparison($db, $second, $id);
    WfCheck(count($comparison['continuing']) === $report['finding_count'] && !$comparison['new'] && !$comparison['not_detected'], 'Identical scans compare as continuing findings.');
    $third = ReviewStore::Save($db, $clean, ['application_id' => $app,'mode' => 'client'], $admin);
    WfCheck(count(ReviewStore::Comparison($db, $third, $id)['not_detected']) === $report['finding_count'], 'Absent findings are labelled not detected, not automatically fixed.');
    $inventory = CodeReview::ScanClient([['path' => 'values.php','content' => '<?php function ReadValue(){ $value="INVENTORY_LITERAL_SENTINEL";return $value; }','modified' => time()]], CodeReview::Options(['engine' => 'inventory']), 'Inventory');
    $inventoryId = ReviewStore::Save($db, $inventory, ['mode' => 'client'], $admin);
    WfCheck(!str_contains(json_encode(ReviewStore::Get($db, $inventoryId)), 'INVENTORY_LITERAL_SENTINEL'), 'Function inventory literal values are not persisted.');
    WfReject(fn () => ReviewStore::Comparison($db, $inventoryId, $id), 'Incompatible review engines cannot be compared.');
    $stmtSql = "INSERT INTO expiry_items(item_type,item_name,server_id,expiry_date)VALUES('SSL Certificate','Fixture renewal',?,CURRENT_DATE+14)";
    $stmt = $db->query($stmtSql, [$server]);
    Notifications::Generate($db, true);
    $count = (int)Database::Scalar($db->query('SELECT COUNT(*) FROM notifications'));
    WfCheck($count === 1 && (int)Database::Scalar($db->query('SELECT user_id FROM notifications LIMIT 1')) === $viewer['user_id'], 'Renewal reminders go to the linked application owner.');
    Notifications::Generate($db, true);
    WfCheck((int)Database::Scalar($db->query('SELECT COUNT(*) FROM notifications')) === $count, 'Repeating a reminder check does not duplicate notifications.');
    WfCheck((int)Database::Scalar($db->query('SELECT COUNT(*) FROM notification_deliveries')) === 0, 'Email delivery is off by default.');
    $db->query('UPDATE users SET is_active=FALSE WHERE user_id=?', [$viewer['user_id']]);
    Notifications::Generate($db, true);
    WfCheck((int)Database::Scalar($db->query('SELECT COUNT(*) FROM notifications WHERE user_id='.$admin['user_id'])) > 0, 'Unavailable owners fall back to active Administrators.');
    $db->query('UPDATE users SET is_active=TRUE WHERE user_id=?', [$viewer['user_id']]);
    $db->query('UPDATE notification_settings SET email_enabled=TRUE WHERE id=1');
    Notifications::Add($db, (int)$viewer['user_id'], 'renewal', 'Fixture', 'Safe text', APP_BASE_URL.'/modules/servers/index.php', 'fixture-email');
    WfCheck((int)Database::Scalar($db->query('SELECT COUNT(*) FROM notification_deliveries')) === 1, 'Enabled email creates an outbound delivery record.');
    $db->query('INSERT INTO notification_preferences(user_id,email_enabled)VALUES(?,FALSE)', [$viewer['user_id']]);
    WfCheck(Notifications::Deliver($db)['skipped'] === 1, 'A recipient opt-out suppresses queued email delivery.');
    Notifications::Add($db, (int)$viewer['user_id'], 'renewal', 'Fixture', 'Safe text', APP_BASE_URL.'/modules/servers/index.php', 'fixture-opt-out');
    WfCheck((int)Database::Scalar($db->query('SELECT COUNT(*) FROM notification_deliveries')) === 1, 'An opted-out recipient does not create more email jobs.');
    WfReject(fn () => Notifications::Add($db, 1, 'renewal', 'Bad', 'Bad', 'https://outside.invalid/', 'bad'), 'Notifications reject destinations outside the app.');
    $db->query('DELETE FROM applications WHERE application_id=?', [$app]);
    WfCheck(ReviewStore::Get($db, $id)['application_id'] === null && (int)Database::Scalar($db->query('SELECT COUNT(*) FROM servers WHERE server_id='.$server)) === 1, 'Deleting a catalog entry preserves review history and inventory records.');
    echo "Workflow / OWASP fixtures: {$checks} assertions passed.\n";
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
    Permissions::Reset();
}
