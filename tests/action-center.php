<?php

/**
 * Filename: action-center.php
 * Purpose: Verify action center behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Deterministic real-DB checks; no SMTP calls and no changes to application records. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__.'/../lib/RecordSearch.php';
require_once __DIR__.'/../lib/LeaveRequests.php';
require_once __DIR__.'/../lib/Notifications.php';
set_exception_handler(static function (Throwable $e): void
{
    fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n");
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
$schema = 'actions_fixture_'.bin2hex(random_bytes(6));
$checks = 0;
function AcCheck(bool $isValue, string $label): void
{
    global $checks;
    $checks++;
    if (!$isValue)
    {
        throw new RuntimeException($label);
    }
}
function AcActor(int $id): void
{
    $_SESSION = ['user_id' => $id];
    Permissions::Reset();
}
try
{
    $db->query(str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/schema.sql')));
    $db->query('SET search_path TO "'.$schema.'",public');
    $migration = str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/migration_028_action_reminders.sql'));
    $db->query($migration);
    $db->query($migration);
    $admin = (int)Database::Scalar($db->query("SELECT user_id FROM users WHERE role_key='admin' LIMIT 1"));
    $g = (int)Database::Scalar($db->query("INSERT INTO org_groups(group_name)VALUES('Actions group') RETURNING group_id"));
    $v = (int)Database::Scalar($db->query("INSERT INTO org_divisions(group_id,division_name)VALUES($g,'Actions division') RETURNING division_id"));
    $d = (int)Database::Scalar($db->query("INSERT INTO org_departments(division_id,department_name)VALUES($v,'Actions department') RETURNING department_id"));
    $a = (int)Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name)VALUES($d,'Actions section A') RETURNING unit_id"));
    $b = (int)Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name)VALUES($d,'Actions section B') RETURNING unit_id"));
    $people = [];
    $sSql = "INSERT INTO users(employee_id,password_hash,first_name,last_name,unit_id,role_key,created_at)VALUES(?,'fixture',?,'AtlasFixture',?,?,'2026-10-01') RETURNING user_id";
    foreach (['employee' => [$a,'analyst'],'reviewer' => [$a,'reviewer'],'head' => [$a,'section_head'],'outsider' => [$b,'analyst'],'otherreviewer' => [$b,'reviewer']] as $name => [$unit,$role])
    {
        $s = $db->query($sSql, [$name,ucfirst($name),$unit,$role]);
        $people[$name] = (int)Database::Scalar($s);
    }
    Extract($people);
    AcActor($admin);
    DailyLogs::AssignReviewer($db, $admin, ['unit_id' => $a,'reviewer_user_id' => $reviewer,'assignment_version' => 0]);
    DailyLogs::AssignReviewer($db, $admin, ['unit_id' => $b,'reviewer_user_id' => $otherreviewer,'assignment_version' => 0]);
    $db->query("INSERT INTO daily_log_holidays(holiday_date,holiday_name)VALUES('2026-10-27','Organization holiday')");
    $work = ['activity_date' => '2026-10-26','entry_kind' => 'activity','classification' => 'SD','activity_type' => 'AN','planned_task' => 'AtlasFixture planning','actual_activity' => 'AtlasFixture analysis','duration_hours' => 12,'status' => 'Submitted'];
    AcActor($employee);
    $log = DailyLogs::Save($db, $employee, $work);
    $draft = DailyLogs::Save($db, $employee, array_replace($work, ['activity_date' => '2026-10-28','duration_hours' => 8,'status' => 'Draft','actual_activity' => 'PrivateDraftNeedle']));
    $ret = DailyLogs::Save($db, $employee, array_replace($work, ['activity_date' => '2026-10-19','duration_hours' => 8]));
    $db->query("UPDATE daily_logs SET status='Returned' WHERE log_id=$ret");
    foreach ([$reviewer,$head,$outsider] as $id)
    {
        AcActor($id);
        DailyLogs::Save($db, $id, array_replace($work, ['duration_hours' => 8]));
    }
    // Updates use a timestamp without depending on the test machine's current date.
    $db->query("UPDATE daily_logs SET updated_at='2026-10-26 09:00:00+08'");
    $now = new DateTimeImmutable('2026-10-28 18:00:00', new DateTimeZone('Asia/Manila'));
    AcActor($employee);
    $o = ActionCenter::Overview($db, $employee, $now);
    AcCheck($o['personal']['target_hours'] === 32, 'Holidays reduce the weekly target.');
    AcCheck($o['personal']['due_hours'] === 8.0, 'Overtime cannot cover a missing day; drafts do not count.');
    AcCheck($o['personal']['remaining_hours'] === 24.0, 'Future days remain in the full weekly target.');
    AcCheck(count($o['personal']['returned']) === 1 && $o['personal']['drafts'] === 1, 'Returned weeks and private drafts have separate actions.');
    AcCheck(ActionCenter::Overview($db, $employee, $now->setTime(16, 0))['personal']['due_hours'] === 0.0, 'Today does not become overdue before the configured hour.');
    AcCheck($o['team']['people'] === 3, 'Team totals cover every active member of the authorized section.');
    AcActor($reviewer);
    $q = ActionCenter::PendingWeeks($db, $reviewer, $now);
    AcCheck(count($q) === 2 && !in_array($reviewer, array_column($q, 'user_id')) && !in_array($outsider, array_column($q, 'user_id')), 'Reviewer queue excludes own weeks and other sections.');
    AcActor($head);
    AcCheck(count(ActionCenter::PendingWeeks($db, $head, $now)) === 1, 'An unassigned head sees only the self-approval exception.');
    AcActor($admin);
    AcCheck(count(LogReminders::Approvals($db, $admin, $now, 1)) === 1, 'Administrators receive the ordinary reviewer self-week fallback only.');
    AcCheck(count(ActionCenter::PendingWeeks($db, $reviewer, new DateTimeImmutable('2026-10-26 18:00:00+08'), 1)) === 0, 'Approval delay starts at the latest submitted entry, with an explicit timezone.');
    $db->query("UPDATE users SET unit_id=$b,role_key='section_head' WHERE user_id=$reviewer");
    Permissions::Reset();
    AcCheck(in_array($employee, array_map('intval', array_column(LogReminders::Approvals($db, $admin, $now, 1), 'user_id')), true), 'A transferred head cannot suppress administrator fallback for their former section.');
    $db->query("UPDATE users SET unit_id=$a,role_key='reviewer' WHERE user_id=$reviewer");
    Permissions::Reset();
    $sSql = "INSERT INTO servers(server_name,environment,ip_address,notes)VALUES('AtlasFixture Server','Dev','192.0.2.1','VaultSecretNeedle') RETURNING server_id";
    $s = $db->query($sSql);
    $server = (int)Database::Scalar($s);
    $table = (int)Database::Scalar($db->query("INSERT INTO dd_tables(table_name,display_name)VALUES('atlas_fixture','AtlasFixture Table') RETURNING table_id"));
    $db->query("INSERT INTO dd_columns(table_id,column_name,data_type)VALUES($table,'AtlasFixtureField','TEXT')");
    $db->query("INSERT INTO diagrams(diagram_type,diagram_name)VALUES('ERD','AtlasFixture ERD'),('DFD','HiddenDiagramNeedle')");
    $db->query("INSERT INTO bookmarks(title,url)VALUES('AtlasFixture Link','https://example.invalid/?token=UrlSecretNeedle')");
    AcActor($employee);
    $found = RecordSearch::Find($db, $employee, 'AtlasFixture')['results'];
    AcCheck(count($found) >= 6, 'Record search returns personnel, servers, metadata, diagrams and shared links.');
    AcCheck(!in_array('Outsider AtlasFixture', array_column($found, 'title')), 'Personnel search does not leak another section.');
    AcCheck(!array_filter($found, static fn ($r) => in_array($r['module'], ['applications','requirements'], true)), 'Hidden modules never appear in search.');
    AcCheck(!RecordSearch::Find($db, $employee, 'VaultSecretNeedle')['results'] && !RecordSearch::Find($db, $employee, 'UrlSecretNeedle')['results'], 'Search excludes private inventory notes and unsafe address fields.');
    AcCheck(!RecordSearch::Find($db, $employee, 'HiddenDiagramNeedle')['results'], 'Non-ERD legacy diagrams are excluded.');
    AcCheck(count(RecordSearch::Find($db, $employee, 'PrivateDraftNeedle')['results']) === 1, 'An employee can find their own private draft.');
    AcActor($reviewer);
    AcCheck(!RecordSearch::Find($db, $reviewer, 'PrivateDraftNeedle')['results'], 'Reviewers cannot search another employee private draft.');
    AcActor($admin);
    $db->query("INSERT INTO app_roles(role_key,role_name)VALUES('actions_limited','Actions limited');INSERT INTO role_permissions(role_key,module,permission,is_allowed)VALUES('actions_limited','dashboard','view',TRUE)");
    $db->query("UPDATE users SET role_key='actions_limited' WHERE user_id=$employee");
    Permissions::Reset();
    AcCheck(!array_filter(RecordSearch::Find($db, $employee, 'AtlasFixture')['results'], static fn ($r) => in_array($r['module'], ['servers','datadict','diagrams','dailylogs','teammonitoring','usermgmt'], true)), 'Revoked grants remove protected search results immediately.');
    $db->query("UPDATE users SET role_key='analyst' WHERE user_id=$employee");
    Permissions::Reset();
    AcCheck(!RecordSearch::Find($db, $employee, '%_')['results'], 'Search treats wildcard characters literally.');
    AcCheck(!RecordSearch::Find($db, $employee, 'a')['results'], 'Short searches do not query records.');
    $db->query('UPDATE notification_settings SET email_enabled=TRUE,daily_log_email_enabled=FALSE');
    Notifications::Generate($db, true, $now->setTime(16, 0));
    AcCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM notifications WHERE kind IN('daily_log_attention','weekly_approval')")) === 0, 'No daily digests before the configured hour.');
    Notifications::Generate($db, true, $now);
    $count = (int)Database::Scalar($db->query("SELECT COUNT(*) FROM notifications WHERE kind IN('daily_log_attention','weekly_approval')"));
    AcCheck($count > 0, 'Working-day checks create daily-log and weekly-approval digests.');
    AcCheck((int)Database::Scalar($db->query('SELECT COUNT(*) FROM notification_deliveries')) === 0, 'New log-reminder email is off even when existing email delivery is enabled.');
    Notifications::Generate($db, true, $now);
    AcCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM notifications WHERE kind IN('daily_log_attention','weekly_approval')")) === $count, 'Repeated checks do not duplicate same-day digests.');
    AcCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM notifications WHERE user_id=$reviewer AND kind='weekly_approval'")) === 1, 'One approval digest per reviewer, not one per input.');
    $db->query("UPDATE daily_logs SET status='Reviewed' WHERE user_id IN($employee,$head) AND status='Submitted';UPDATE daily_logs SET status='Reviewed' WHERE log_id=$ret");
    Notifications::Generate($db, true, $now);
    AcCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM notifications WHERE user_id=$reviewer AND kind='weekly_approval' AND NOT is_read")) === 0, 'Resolved approval digests are retired.');
    $db->query('UPDATE notification_settings SET daily_log_enabled=FALSE,approval_enabled=FALSE');
    Notifications::Generate($db, true, $now);
    AcCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM notifications WHERE kind IN('daily_log_attention','weekly_approval') AND NOT is_read")) === 0, 'Disabling reminders retires unread digests.');
    $db->query('UPDATE notification_settings SET daily_log_enabled=TRUE,approval_enabled=TRUE');
    $before = (int)Database::Scalar($db->query('SELECT COUNT(*) FROM notifications'));
    Notifications::Generate($db, true, new DateTimeImmutable('2026-10-31 18:00:00+08'));
    AcCheck((int)Database::Scalar($db->query('SELECT COUNT(*) FROM notifications')) === $before, 'Weekends do not create daily-log digests.');
    Notifications::Generate($db, true, new DateTimeImmutable('2026-10-27 18:00:00+08'));
    AcCheck((int)Database::Scalar($db->query('SELECT COUNT(*) FROM notifications')) === $before, 'Organization holidays do not create daily-log digests.');
    AcActor($employee);
    LeaveRequests::Save($db, $employee, ['activity_date' => '2026-10-28','entry_kind' => 'leave','leave_type' => 'VL','leave_period' => 'WHOLE','status' => 'Submitted']);
    AcCheck(LogReminders::Attention($db, $employee, '2026-10-26', '2026-10-28')['hours'] === 0.0, 'Submitted leave satisfies the daily target and stops missing-hour reminders.');
    $settings = $db->query('SELECT * FROM notification_settings WHERE id=1')->getUnbufferedRow('array');
    AcCheck(!LogReminders::Relevant($db, ['user_id' => $employee,'kind' => 'daily_log_attention','context' => ['day' => '2026-10-28','from' => '2026-10-26','to' => '2026-10-28']], $settings, $now), 'Resolved reminder context is rejected before email delivery.');
    $db->query("UPDATE users SET role_key='viewer' WHERE user_id=$reviewer");
    Permissions::Reset();
    AcCheck(!LogReminders::Relevant($db, ['user_id' => $reviewer,'kind' => 'weekly_approval','context' => ['day' => '2026-10-28']], $settings, $now), 'Revoking review grants invalidates queued approval messages.');
    $db->query("UPDATE users SET is_locked=TRUE WHERE user_id=$employee");
    Permissions::Reset();
    AcCheck(LogReminders::Attention($db, $employee, '2026-10-26', '2026-10-28') === ['hours' => 0,'days' => 0,'returned' => []], 'Locked users no longer receive daily-log reminders.');
    echo "Action dashboard / record search / log reminders: $checks assertions passed.\n";
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
