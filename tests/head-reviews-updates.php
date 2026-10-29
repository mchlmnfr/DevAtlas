<?php

/**
 * Filename: head-reviews-updates.php
 * Purpose: Verify head reviews updates behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Real database checks for named-head self-review and durable update acknowledgements. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__.'/../lib/DailyLogs.php';
require_once __DIR__.'/../lib/Roles.php';
require_once __DIR__.'/../lib/SystemUpdates.php';
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
$schema = 'head_updates_'.bin2hex(random_bytes(6));
$checks = 0;
$fixture = null;
function HuCheck(bool $isOk, string $message): void
{
    global $checks;
    $checks++;
    if (!$isOk)
    {
        throw new RuntimeException($message);
    }
}
function HuActor(int $id): void
{
    $_SESSION = ['user_id' => $id,'employee_id' => 'fixture','full_name' => 'Head update fixture'];
    Permissions::Reset();
}
function HuReject(callable $fn, string $message): void
{
    try
    {
        $fn();
    }
    catch (DomainException|InvalidArgumentException|OutOfBoundsException|LogicException $e)
    {
        HuCheck(true, $message);
        return;
    }
    HuCheck(false, $message);
}
function HuItem(array $week): array
{
    return array_intersect_key($week, array_flip(['user_id','week_start','token']));
}
try
{
    $db->query(str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/schema.sql')));
    $db->query('SET search_path TO "'.$schema.'",public');
    $group = Database::Scalar($db->query("INSERT INTO org_groups(group_name)VALUES('Fixture') RETURNING group_id"));
    $division = Database::Scalar($db->query("INSERT INTO org_divisions(group_id,division_name)VALUES($group,'Fixture') RETURNING division_id"));
    $department = Database::Scalar($db->query("INSERT INTO org_departments(division_id,department_name)VALUES($division,'Fixture') RETURNING department_id"));
    $unit = (int)Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name)VALUES($department,'Section A') RETURNING unit_id"));
    $other = (int)Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name)VALUES($department,'Section B') RETURNING unit_id"));
    $admin = (int)Database::Scalar($db->query("SELECT user_id FROM users WHERE role_key='admin' LIMIT 1"));
    $sSql = "INSERT INTO users(employee_id,password_hash,first_name,last_name,unit_id,role_key)VALUES(?,'fixture',?,'Fixture',?,?) RETURNING user_id";
    $people = [];
    foreach (['head' => [$unit,'section_head'],'department' => [null,'department_head'],'reviewer' => [$unit,'reviewer'],'employee' => [$unit,'analyst'],'outsider' => [$other,'analyst']] as $name => [$section,$role])
    {
        $s = $db->query($sSql, [$name,$name,$section,$role]);
        $people[$name] = (int)Database::Scalar($s);
    }
    Extract($people);
    HuActor($admin);
    DailyLogs::AssignReviewer($db, $admin, ['unit_id' => $unit,'reviewer_user_id' => $reviewer,'assignment_version' => 0]);
    $work = ['entry_kind' => 'activity','activity_date' => '2026-10-26','status' => 'Submitted','classification' => 'SD','activity_type' => 'AD','planned_task' => 'Plan','actual_activity' => 'Done','duration_hours' => 4];
    foreach ([$head,$department,$reviewer,$employee,$outsider,$admin] as $person)
    {
        HuActor($person);
        DailyLogs::Save($db, $person, $work);
    }
    HuActor($head);
    $second = DailyLogs::Save($db, $head, array_replace($work, ['activity_date' => '2026-10-28']));
    $draft = DailyLogs::Save($db, $head, ['activity_date' => '2026-10-30','status' => 'Draft']);
    HuCheck(Permissions::Can('reviewlogs', 'review') && Permissions::UserCan($db, $head, 'reviewlogs', 'review'), 'Section Head has review access without being the assigned section reviewer.');
    $list = WeeklyLogReview::Listing($db, $head, []);
    HuCheck($list['total'] === 1 && (int)$list['weeks'][0]['user_id'] === $head, 'Unassigned Section Head sees only their own employee week.');
    $week = WeeklyLogReview::Get($db, $head, $head, '2026-10-26');
    HuCheck($week['can_review'] && count($week['logs']) === 2, 'Head can review their submitted entries while drafts remain private.');
    HuReject(fn () => WeeklyLogReview::Get($db, $head, $employee, '2026-10-26'), 'Unassigned head cannot inspect another employee week.');
    HuReject(fn () => WeeklyLogReview::Listing($db, $head, ['unit_id' => $other]), 'Head cannot forge access to another section.');
    $employeeWeek = null;
    HuActor($reviewer);
    $employeeWeek = WeeklyLogReview::Get($db, $reviewer, $employee, '2026-10-26');
    HuActor($head);
    HuReject(fn () => WeeklyLogReview::Review($db, $head, ['items' => [HuItem($week),HuItem($employeeWeek)]]), 'Mixed self and unauthorized employee batch fails atomically.');
    HuCheck(WeeklyLogReview::Get($db, $head, $head, '2026-10-26')['status'] === 'Submitted', 'Failed mixed batch preserves the head submission.');
    HuCheck(WeeklyLogReview::Review($db, $head, ['items' => [HuItem($week)]]) === 1, 'Section Head can approve their whole week.');
    $approved = WeeklyLogReview::Get($db, $head, $head, '2026-10-26');
    HuCheck($approved['status'] === 'Reviewed' && (int)$approved['decision']['reviewed_by'] === $head && count(array_filter($approved['logs'], fn ($log) => (int)$log['reviewed_by'] === $head)) === 2, 'Self-approval records the head as reviewer on the week and all submitted entries.');
    HuCheck(DailyLogs::Get($db, $head, $draft)['status'] === 'Draft', 'Head self-approval leaves drafts unchanged.');
    DailyLogs::Save($db, $head, array_replace($work, ['activity_date' => '2026-10-29']));
    HuReject(fn () => WeeklyLogReview::Review($db, $head, ['items' => [HuItem($approved)]]), 'Head self-approval still rejects stale review tokens.');
    $week = WeeklyLogReview::Get($db, $head, $head, '2026-10-26');
    HuCheck($week['status'] === 'Submitted', 'A new head submission reopens the full week.');
    HuReject(fn () => WeeklyLogReview::Review($db, $head, ['items' => [HuItem($week)],'decision' => 'Returned']), 'A head returning their week still needs a correction comment.');
    WeeklyLogReview::Review($db, $head, ['items' => [HuItem($week)],'decision' => 'Returned','review_comment' => 'Correct my week']);
    HuCheck(WeeklyLogReview::Get($db, $head, $head, '2026-10-26')['status'] === 'Returned', 'Head can return their own week for corrections.');
    $db->query("UPDATE role_permissions SET is_allowed=FALSE WHERE role_key='section_head' AND module='reviewlogs' AND permission='review'");
    Permissions::Reset();
    HuCheck(!Permissions::Can('reviewlogs', 'review') && !Permissions::UserCan($db, $head, 'reviewlogs', 'review'), 'Head exception respects an explicitly revoked Review grant.');
    $db->query("UPDATE role_permissions SET is_allowed=TRUE WHERE role_key='section_head' AND module='reviewlogs' AND permission='review'");
    Permissions::Reset();
    HuActor($department);
    HuCheck(Permissions::Can('reviewlogs', 'review'), 'Department Head can access own reviews without a unit assignment.');
    $week = WeeklyLogReview::Get($db, $department, $department, '2026-10-26');
    WeeklyLogReview::Review($db, $department, ['items' => [HuItem($week)]]);
    HuCheck(WeeklyLogReview::Get($db, $department, $department, '2026-10-26')['status'] === 'Reviewed', 'Department Head can self-approve without acquiring access to anyone else.');
    HuReject(fn () => WeeklyLogReview::Get($db, $department, $employee, '2026-10-26'), 'Department Head self-review does not grant department-wide approval access.');
    HuActor($reviewer);
    $week = WeeklyLogReview::Get($db, $reviewer, $reviewer, '2026-10-26');
    HuCheck(!$week['can_review'], 'Ordinary reviewer own-week controls remain unavailable.');
    HuReject(fn () => WeeklyLogReview::Review($db, $reviewer, ['items' => [HuItem($week)]]), 'Ordinary reviewers cannot self-approve.');
    HuActor($admin);
    $week = WeeklyLogReview::Get($db, $admin, $admin, '2026-10-26');
    HuReject(fn () => WeeklyLogReview::Review($db, $admin, ['items' => [HuItem($week)]]), 'Administrator role alone cannot self-approve.');
    DailyLogs::AssignReviewer($db, $admin, ['unit_id' => $unit,'reviewer_user_id' => $head,'assignment_version' => 1]);
    HuActor($head);
    $week = WeeklyLogReview::Get($db, $head, $employee, '2026-10-26');
    WeeklyLogReview::Review($db, $head, ['items' => [HuItem($week)]]);
    HuCheck(WeeklyLogReview::Get($db, $head, $employee, '2026-10-26')['status'] === 'Reviewed', 'An assigned head can also approve another employee in their section.');
    Roles::Create($db, ['role_key' => 'head_helper','role_name' => 'Department Head Assistant']);
    $db->query("UPDATE users SET role_key='head_helper' WHERE user_id=?", [$employee]);
    HuActor($employee);
    HuCheck(!Permissions::HasSelfReviewRole($db, $employee) && !Permissions::Can('reviewlogs'), 'Unrelated role names do not inherit the head exception.');
    $notes = SystemUpdates::Releases();
    $latest = $notes[0];
    HuCheck(count($notes) >= 2 && $notes[0]['order'] > $notes[1]['order'], 'History returns the newest release first, including releases on the same date.');
    HuCheck(SystemUpdates::Notice($db, $head)['pending'], 'User initially sees the latest release notice.');
    HuReject(fn () => SystemUpdates::Acknowledge($db, $head, ['release_id' => 'forged-release']), 'Forged update acknowledgements are rejected.');
    SystemUpdates::Acknowledge($db, $head, ['release_id' => $latest['id']]);
    HuCheck(!SystemUpdates::Notice($db, $head)['pending'] && SystemUpdates::Notice($db, $department)['pending'], 'Acknowledgement persists per user without dismissing someone else notice.');
    HuReject(fn () => SystemUpdates::Acknowledge($db, $head, ['release_id' => $notes[1]['id']]), 'An old release cannot overwrite the latest acknowledgement.');
    $db->query('UPDATE user_update_reads SET release_order=?,release_id=? WHERE user_id=?', [$notes[1]['order'],$notes[1]['id'],$head]);
    HuCheck(SystemUpdates::Notice($db, $head)['pending'], 'A newer published release becomes pending after a prior release was read.');
    SystemUpdates::Acknowledge($db, $head, ['release_id' => $latest['id']]);
    $db->query('UPDATE user_update_reads SET release_order=? WHERE user_id=?', [$latest['order'] + 1,$head]);
    SystemUpdates::Acknowledge($db, $head, ['release_id' => $latest['id']]);
    HuCheck(!SystemUpdates::Notice($db, $head)['pending'] && (int)Database::Scalar($db->query('SELECT release_order FROM user_update_reads WHERE user_id='.$head)) === $latest['order'] + 1, 'An older in-flight acknowledgement cannot downgrade a newer seen release.');
    $db->query("UPDATE role_permissions SET is_allowed=FALSE WHERE role_key='section_head' AND module='reviewlogs' AND permission='review'");
    $queries = ['SELECT user_id,role_key FROM users ORDER BY user_id','SELECT * FROM daily_logs ORDER BY log_id','SELECT * FROM daily_log_week_reviews ORDER BY week_review_id','SELECT * FROM user_update_reads ORDER BY user_id','SELECT * FROM role_permissions ORDER BY permission_id'];
    $before = array_map(fn ($sql) => hash('sha256', json_encode($db->query($sql)->getResultArray())), $queries);
    $migration = str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/migration_026_head_reviews_updates.sql'));
    $db->query($migration);
    $db->query($migration);
    HuCheck($before === array_map(fn ($sql) => hash('sha256', json_encode($db->query($sql)->getResultArray())), $queries), 'Migration reapplication preserves accounts, logs, weekly decisions, notice state and role grant revocations.');
    echo "Head review / update fixtures: {$checks} assertions passed.\n";
}
finally
{
    if (Database::InTransaction($db))
    {
        $db->transRollback();
    }
    $db->query('SET search_path TO public');
    $db->query('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
    Permissions::Reset();
}
