<?php

/**
 * Filename: leaves.php
 * Purpose: Verify leave calendars, independent approvals and department and section access boundaries.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Exercise preserved leave records, custom role approval and Section Head self-approval.
 */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__.'/../lib/LeaveRequests.php';
require_once __DIR__.'/../lib/Roles.php';
require_once __DIR__.'/../lib/ActionCenter.php';
require_once __DIR__.'/../lib/RecordSearch.php';
set_exception_handler(static function (Throwable $e): void
{
    fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
});
$db = Database::GetConnection('app');
$schema = 'leaves_fixture_'.bin2hex(random_bytes(6));
$checks = 0;
function LvCheck(bool $isOk, string $label): void
{
    global $checks;
    $checks++;
    if (!$isOk)
    {
        throw new RuntimeException($label);
    }
}
function LvActor(int $user): void
{
    $_SESSION = ['user_id' => $user];
    Permissions::Reset();
}
function LvReject(callable $task, string $label): void
{
    try
    {
        $task();
    }
    catch (InvalidArgumentException|DomainException|OutOfBoundsException $e)
    {
        LvCheck(true, $label);
        return;
    }
    LvCheck(false, $label);
}
try
{
    $db->query(str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/schema.sql')));
    $db->query('SET search_path TO "'.$schema.'",public');
    $group = Database::Scalar($db->query("INSERT INTO org_groups(group_name) VALUES('Leave fixture') RETURNING group_id"));
    $division = Database::Scalar($db->query("INSERT INTO org_divisions(group_id,division_name) VALUES($group,'Leave fixture') RETURNING division_id"));
    $dept = Database::Scalar($db->query("INSERT INTO org_departments(division_id,department_name) VALUES($division,'Department A') RETURNING department_id"));
    $otherDept = Database::Scalar($db->query("INSERT INTO org_departments(division_id,department_name) VALUES($division,'Department B') RETURNING department_id"));
    $unit = Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name) VALUES($dept,'Section A') RETURNING unit_id"));
    $other = Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name) VALUES($dept,'Section B') RETURNING unit_id"));
    $far = Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name) VALUES($otherDept,'Section C') RETURNING unit_id"));
    Roles::Create($db, ['role_key' => 'leave_approver','role_name' => 'Leave Approver','copy_from' => 'analyst']);
    $db->query("UPDATE role_permissions SET is_allowed=TRUE WHERE role_key IN('leave_approver','department_head') AND module='leaves' AND permission='review'");
    $people = [];
    $sSql = "INSERT INTO users(employee_id,password_hash,first_name,last_name,unit_id,role_key) VALUES(?,'fixture',?,'Fixture',?,?) RETURNING user_id";
    foreach (['employee' => [$unit,'analyst'],'approver' => [$unit,'leave_approver'],'peer' => [$other,'analyst'],'other_approver' => [$other,'leave_approver'],'far' => [$far,'leave_approver'],'head' => [$unit,'section_head'],'department_head' => [$unit,'department_head'],'unassigned' => [null,'analyst']] as $name => [$section,$role])
    {
        $s = $db->query($sSql, [$name,$name,$section,$role]);
        $people[$name] = (int)Database::Scalar($s);
    }
    $employee = $people['employee'];
    $approver = $people['approver'];
    $request = ['activity_date' => '2026-10-26','leave_type' => 'VL','leave_period' => 'AM','status' => 'Submitted','remarks' => 'PRIVATE_LEAVE_REASON'];
    $legacy = (int)Database::Scalar($db->query("INSERT INTO daily_logs(user_id,activity_date,entry_kind,leave_type,leave_period,duration_hours,status) VALUES($employee,'2025-01-06','leave','VL','WHOLE',8,'Reviewed') RETURNING log_id"));
    $db->query(str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/migration_030_leave_ranges.sql')));
    LvActor($employee);
    $history = LeaveRequests::Get($db, $employee, $legacy);
    LvCheck((int)$history['log_id'] === $legacy && $history['status'] === 'Reviewed' && $history['date_from'] === '2025-01-06', 'Upgrading historical leave preserves its ID, date and approval.');
    LvCheck(Permissions::Can('leaves') && !Permissions::Can('leaves', 'review'), 'Personnel can view leave without approval rights.');
    $id = LeaveRequests::Save($db, $employee, $request);
    $draft = LeaveRequests::Save($db, $employee, array_replace($request, ['activity_date' => '2026-10-28','status' => 'Draft']));
    LvReject(fn () => DailyLogs::Save($db, $employee, $request + ['entry_kind' => 'leave']), 'Daily Logs no longer accepts leave applications.');
    LvReject(fn () => DailyLogs::Save($db, $employee, ['log_id' => $id,'version' => 1,'activity_date' => '2026-10-26']), 'Daily Logs cannot convert an existing leave request to work.');
    LvReject(fn () => DailyLogs::Delete($db, $employee, ['log_id' => $draft,'version' => 1]), 'Daily Logs cannot delete a leave draft.');
    LvCheck(DailyLogs::Listing($db, $employee, [])['total'] === 0, 'Leave applications no longer appear in the daily work list.');
    LvCheck(count(LeaveRequests::Calendar($db, $employee, ['month' => '2026-10'])['mine']['rows']) === 2, 'The owner sees their submitted and draft applications.');
    LvReject(fn () => LeaveRequests::Save($db, $employee, $request), 'Submitted leave periods cannot overlap.');
    LvReject(fn () => LeaveRequests::Save($db, $employee, array_replace($request, ['activity_date' => '2026-10-31'])), 'Weekend leave is rejected.');
    LvActor($people['peer']);
    $calendar = LeaveRequests::Calendar($db, $people['peer'], ['month' => '2026-10']);
    LvCheck(count($calendar['leaves']) === 1 && (int)$calendar['leaves'][0]['log_id'] === $id, 'Another section in the same department sees submitted leave but no drafts.');
    LvCheck(!str_contains(json_encode($calendar), 'PRIVATE_LEAVE_REASON'), 'Department calendars do not expose private remarks.');
    $peerView = LeaveRequests::Get($db, $people['peer'], $id);
    LvCheck(!isset($peerView['remarks']) && !$peerView['can_review'] && !$peerView['can_edit'], 'Department peers see public details without private remarks or actions.');
    LvReject(fn () => LeaveRequests::Get($db, $people['peer'], $draft), 'Peers cannot open a private leave draft by ID.');
    LvReject(fn () => LeaveRequests::Calendar($db, $people['peer'], ['month' => '2026-10','department_id' => $otherDept]), 'Forged department filters are rejected.');
    LvActor($people['far']);
    LvCheck(!LeaveRequests::Calendar($db, $people['far'], ['month' => '2026-10'])['leaves'], 'Other departments cannot see the leave calendar.');
    LvReject(fn () => LeaveRequests::Get($db, $people['far'], $id), 'Other departments cannot open leave details by ID.');
    LvReject(fn () => LeaveRequests::Review($db, $people['far'], ['log_id' => $id,'version' => 1,'decision' => 'Reviewed']), 'An approval grant cannot bypass department and section boundaries.');
    LvActor($people['other_approver']);
    LvReject(fn () => LeaveRequests::Review($db, $people['other_approver'], ['log_id' => $id,'version' => 1,'decision' => 'Reviewed']), 'An approver in another section of the same department cannot approve.');
    LvActor($approver);
    LvCheck(Permissions::Can('approvals') && !Permissions::Can('reviewlogs'), 'Leave-only approvers can open the shared approval module without daily-log review access.');
    LvCheck(count(LeaveRequests::Calendar($db, $approver, ['month' => '2026-11'])['pending']['rows']) === 1, 'The section queue includes pending applications across all months.');
    $old = LeaveRequests::Get($db, $approver, $id);
    LvCheck($old['remarks'] === 'PRIVATE_LEAVE_REASON' && $old['can_review'], 'A chosen custom role can approve and read request remarks within its section.');
    LvActor($employee);
    LeaveRequests::Save($db, $employee, array_replace($request, ['log_id' => $id,'version' => $old['version'],'remarks' => 'UPDATED_PRIVATE_REASON']));
    LvActor($approver);
    LvReject(fn () => LeaveRequests::Review($db, $approver, ['log_id' => $id,'version' => $old['version'],'decision' => 'Reviewed']), 'Edits invalidate a previously opened leave approval.');
    $latest = LeaveRequests::Get($db, $approver, $id);
    LeaveRequests::Review($db, $approver, ['log_id' => $id,'version' => $latest['version'],'decision' => 'Reviewed']);
    LvCheck(LeaveRequests::Get($db, $approver, $id)['status'] === 'Reviewed', 'The latest leave application can be approved.');
    LvActor($employee);
    $approved = LeaveRequests::Get($db, $employee, $id);
    LvReject(fn () => LeaveRequests::Save($db, $employee, $request + ['log_id' => $id,'version' => $approved['version']]), 'Approved leave stays locked.');
    $work = ['activity_date' => '2026-10-26','entry_kind' => 'activity','status' => 'Submitted','classification' => 'SD','activity_type' => 'HD','planned_task' => 'Work','actual_activity' => 'Completed work','duration_hours' => 4];
    DailyLogs::Save($db, $employee, $work);
    LvCheck(LeaveRequests::Get($db, $employee, $id)['status'] === 'Reviewed', 'Submitting new work does not reopen approved leave.');
    LvCheck(ActionCenter::Balances($db, [['user_id' => $employee]], '2026-10-26', '2026-10-26', '2026-10-26')[$employee]['remaining_hours'] == 0, 'Leave and work continue to reconcile daily required hours.');
    $results = RecordSearch::Find($db, $employee, 'UPDATED_PRIVATE_REASON')['results'];
    LvCheck(count($results) === 1 && $results[0]['module'] === 'leaves', 'Own leave record searches open the new module.');
    LvActor($approver);
    LvReject(fn () => LeaveRequests::Review($db, $approver, ['log_id' => $id,'version' => $approved['version'],'decision' => 'Returned']), 'Returning leave requires a comment.');
    LeaveRequests::Review($db, $approver, ['log_id' => $id,'version' => $approved['version'],'decision' => 'Returned','review_comment' => 'Correct the application']);
    LvActor($people['peer']);
    LvCheck(!LeaveRequests::Calendar($db, $people['peer'], ['month' => '2026-10'])['leaves'], 'Returned requests disappear from the department calendar.');
    LvReject(fn () => LeaveRequests::Get($db, $people['peer'], $id), 'Returned application details stay private.');
    LvActor($employee);
    $returned = LeaveRequests::Get($db, $employee, $id);
    LvCheck($returned['review_comment'] === 'Correct the application' && $returned['can_edit'], 'Applicants see return comments and can correct their request.');
    LeaveRequests::Delete($db, $employee, ['log_id' => $id,'version' => $returned['version']]);
    LvActor($people['head']);
    $own = LeaveRequests::Save($db, $people['head'], $request);
    LeaveRequests::Review($db, $people['head'], ['log_id' => $own,'version' => 1,'decision' => 'Reviewed']);
    LvCheck(LeaveRequests::Get($db, $people['head'], $own)['status'] === 'Reviewed', 'Section Heads can approve their own leave.');
    LvActor($approver);
    $own = LeaveRequests::Save($db, $approver, $request);
    LvReject(fn () => LeaveRequests::Review($db, $approver, ['log_id' => $own,'version' => 1,'decision' => 'Reviewed']), 'A custom approver cannot approve their own leave.');
    LvActor($people['department_head']);
    $own = LeaveRequests::Save($db, $people['department_head'], $request);
    LvReject(fn () => LeaveRequests::Review($db, $people['department_head'], ['log_id' => $own,'version' => 1,'decision' => 'Reviewed']), 'Department Head alone does not grant the Section Head leave self-approval exception.');
    LvActor($people['unassigned']);
    LvReject(fn () => LeaveRequests::Save($db, $people['unassigned'], $request), 'A section assignment is required before submitting leave.');
    LvReject(fn () => LeaveRequests::Calendar($db, $people['unassigned'], ['month' => '2026-13']), 'Invalid calendar months are rejected.');
    LvActor($employee);
    Roles::Create($db, ['role_key' => 'calendar_viewer','role_name' => 'Calendar Viewer']);
    $s = $db->query("SELECT permission,is_allowed FROM role_permissions WHERE role_key='calendar_viewer' AND module='leaves'");
    $grants = array_column($s->getResultArray(), 'is_allowed', 'permission');
    LvCheck($grants['view'] && !$grants['review'], 'New roles can view calendars without receiving approval rights.');
    $db->query("INSERT INTO daily_log_holidays(holiday_date,holiday_name) VALUES('2026-11-03','Range holiday')");
    $range = ['date_from' => '2026-11-02','date_to' => '2026-11-10','leave_type' => 'VL','leave_period' => 'WHOLE','status' => 'Submitted'];
    $rangeId = LeaveRequests::Save($db, $employee, $range);
    $group = LeaveRequests::Get($db, $employee, $rangeId);
    LvCheck($group['working_days'] === 6 && $group['total_hours'] == 48 && $group['date_from'] === '2026-11-02' && $group['date_to'] === '2026-11-10', 'A leave range skips weekends and holidays and totals its working-day hours.');
    $calendar = LeaveRequests::Calendar($db, $employee, ['month' => '2026-11']);
    LvCheck(count(array_filter($calendar['leaves'], static fn ($row) => str_starts_with($row['activity_date'], '2026-11'))) === 6 && $calendar['mine']['total'] === 1, 'One range appears on six calendar dates but remains one application.');
    LvReject(fn () => LeaveRequests::Save($db, $employee, array_replace($range, ['date_to' => '2026-11-01'])), 'Reversed leave date ranges are rejected.');
    LvReject(fn () => LeaveRequests::Save($db, $employee, array_replace($range, ['date_to' => '2027-12-01'])), 'Unbounded leave ranges are rejected.');
    LvReject(fn () => LeaveRequests::Save($db, $employee, array_replace($range, ['date_from' => '2026-11-07','date_to' => '2026-11-08'])), 'Ranges containing only weekends are rejected.');
    LvReject(fn () => LeaveRequests::Save($db, $employee, array_replace($range, ['date_from' => '2026-11-03','date_to' => '2026-11-03'])), 'Ranges containing only a holiday are rejected.');
    LvReject(fn () => LeaveRequests::Save($db, $employee, array_replace($range, ['date_from' => '2026-11-06','date_to' => '2026-11-09'])), 'Overlapping ranges are rejected.');
    $beforeRange = (int)Database::Scalar($db->query('SELECT COUNT(*) FROM daily_logs'));
    LvReject(fn () => LeaveRequests::Save($db, $employee, array_replace($range, ['date_from' => '2026-10-30','date_to' => '2026-11-02'])), 'A conflict on the last date rejects the entire range.');
    LvCheck((int)Database::Scalar($db->query('SELECT COUNT(*) FROM daily_logs')) === $beforeRange, 'Failed ranges do not leave partial day entries.');
    LeaveRequests::Save($db, $employee, array_replace($range, ['log_id' => $rangeId,'version' => $group['version'],'remarks' => 'Range updated']));
    LvActor($approver);
    LvCheck(LeaveRequests::Calendar($db, $approver, ['month' => '2026-11'])['pending']['total'] === 2, 'The approval queue counts applications rather than individual leave dates.');
    LvReject(fn () => LeaveRequests::Review($db, $approver, ['log_id' => $rangeId,'version' => $group['version'],'decision' => 'Reviewed']), 'A changed leave range invalidates an older approval version.');
    $group = LeaveRequests::Get($db, $approver, $rangeId);
    LeaveRequests::Review($db, $approver, ['log_id' => $rangeId,'version' => $group['version'],'decision' => 'Reviewed']);
    $group = LeaveRequests::Get($db, $approver, $rangeId);
    LvCheck($group['status'] === 'Reviewed' && $group['working_days'] === 6, 'One approval covers all six working days.');
    $sSql = "SELECT COUNT(*) AS total_count,COUNT(*) FILTER(WHERE status='Reviewed') AS approved_count FROM daily_logs WHERE leave_request_id=(SELECT leave_request_id FROM daily_logs WHERE log_id=?)";
    $s = $db->query($sSql, [$rangeId]);
    LvCheck(array_values($s->getUnbufferedRow('array') ?: []) === [6,6], 'Every daily entry in the approved range shares the approval status.');
    LeaveRequests::Review($db, $approver, ['log_id' => $rangeId,'version' => $group['version'],'decision' => 'Returned','review_comment' => 'Shorten the range']);
    LvActor($employee);
    $returned = LeaveRequests::Get($db, $employee, $rangeId);
    $sameId = LeaveRequests::Save($db, $employee, array_replace($range, ['log_id' => $rangeId,'version' => $returned['version'],'date_from' => '2026-11-04','date_to' => '2026-11-05']));
    $shortened = LeaveRequests::Get($db, $employee, $rangeId);
    LvCheck($sameId === $rangeId && $shortened['working_days'] === 2 && $shortened['total_hours'] == 16, 'Correcting a returned range preserves its application link and replaces its covered dates.');
    $db->query("UPDATE users SET birthday='1990-10-28' WHERE user_id=$employee");
    $db->query("UPDATE users SET birthday='1995-10-28' WHERE user_id=".$people['peer']);
    $db->query("UPDATE users SET birthday='1994-10-28' WHERE user_id=".$people['far']);
    $birthdays = LeaveRequests::Calendar($db, $employee, ['month' => '2026-10'])['birthdays'];
    LvCheck(count($birthdays) === 2 && $birthdays[0]['date'] === '2026-10-28', 'The calendar shows birthdays from all sections in the same department.');
    LvCheck(!preg_match('/1990|1995|1994|birthday_day|age/', json_encode($birthdays)), 'Calendar birthday events reveal neither birth years nor ages.');
    LvCheck(count(LeaveRequests::Calendar($db, $employee, ['month' => '2027-10'])['birthdays']) === 2, 'Birthdays recur in later calendar years.');
    LvActor($people['far']);
    LvCheck(count(LeaveRequests::Calendar($db, $people['far'], ['month' => '2026-10'])['birthdays']) === 1, 'Birthday visibility follows the department boundary.');
    LvActor($employee);
    $db->query("UPDATE users SET birthday='2000-02-29' WHERE user_id=$employee");
    LvCheck(LeaveRequests::Calendar($db, $employee, ['month' => '2027-02'])['birthdays'][0]['date'] === '2027-02-28', 'Leap-day birthdays appear on February 28 in non-leap years.');
    LvCheck(LeaveRequests::Calendar($db, $employee, ['month' => '2028-02'])['birthdays'][0]['date'] === '2028-02-29', 'Leap-day birthdays retain February 29 in leap years.');
    $db->query("UPDATE role_permissions SET is_allowed=FALSE WHERE role_key='section_head' AND module='leaves' AND permission='review'");
    $before = (int)Database::Scalar($db->query("SELECT COUNT(*) FROM daily_logs WHERE entry_kind='leave'"));
    $db->query(str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/migration_029_leave_module.sql')));
    LvActor($people['head']);
    LvCheck(!Permissions::Can('leaves', 'review'), 'Reapplying the migration preserves a revoked approval grant.');
    LvCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM daily_logs WHERE entry_kind='leave'")) === $before, 'Module installation preserves all existing leave records.');
    echo 'Leave calendar and approval fixtures: '.$checks." assertions passed.\n";
}
finally
{
    if (Database::InTransaction($db))
    {
        $db->transRollback();
    }
    $db->query('SET search_path TO public');
    $db->query('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
}
