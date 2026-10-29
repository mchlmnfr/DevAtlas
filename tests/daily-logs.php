<?php

/**
 * Filename: daily-logs.php
 * Purpose: Verify daily logs behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Verify submitted edits retain ownership, validation and weekly snapshot protections.
 */

/** Real PostgreSQL workflow and scope checks, isolated from application records. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__.'/../lib/DailyLogs.php';
require_once __DIR__.'/../lib/LeaveRequests.php';
require_once __DIR__.'/../lib/WeeklySummaryWorkbook.php';
require_once __DIR__.'/../lib/DailyLogReports.php';
require_once __DIR__.'/../lib/DailyLogsWorkbook.php';
require_once __DIR__.'/../lib/Roles.php';
require_once __DIR__.'/../vendor/autoload.php';
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
$schema = 'daily_logs_fixture_'.bin2hex(random_bytes(6));
$checks = 0;
function DlCheck(bool $isOk, string $label): void
{
    global $checks;
    $checks++;
    if (!$isOk)
    {
        throw new RuntimeException($label);
    }
}
function DlReject(callable $fn, string $label): void
{
    try
    {
        $fn();
    }
    catch (InvalidArgumentException|OutOfBoundsException|DomainException $e)
    {
        DlCheck(true, $label);
        return;
    }
    DlCheck(false, $label);
}
function DlActor(int $id): void
{
    $_SESSION = ['user_id' => $id,'employee_id' => 'fixture','full_name' => 'Daily logs fixture'];
    Permissions::Reset();
}
function DlReview(int $id, string $decision = 'Reviewed', string $comment = ''): int
{
    global $db,$reviewer;
    DlActor($reviewer);
    $row = DailyLogs::Get($db, $reviewer, $id, true);
    return DailyLogs::Review($db, $reviewer, ['items' => [['log_id' => $id,'version' => $row['version']]],'decision' => $decision,'review_comment' => $comment]);
}
try
{
    $db->query(str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/schema.sql')));
    $db->query('SET search_path TO "'.$schema.'",public');
    $group = (int)Database::Scalar($db->query("INSERT INTO org_groups(group_name) VALUES('Fixture group') RETURNING group_id"));
    $division = (int)Database::Scalar($db->query("INSERT INTO org_divisions(group_id,division_name) VALUES($group,'Fixture division') RETURNING division_id"));
    $dept = (int)Database::Scalar($db->query("INSERT INTO org_departments(division_id,department_name) VALUES($division,'Fixture department') RETURNING department_id"));
    $unit = (int)Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name) VALUES($dept,'Section A') RETURNING unit_id"));
    $other = (int)Database::Scalar($db->query("INSERT INTO org_units(department_id,unit_name) VALUES($dept,'Section B') RETURNING unit_id"));
    $people = [];
    $sSql = "INSERT INTO users(employee_id,password_hash,first_name,last_name,unit_id,role_key)VALUES(?,'fixture',?,'Fixture',?,?) RETURNING user_id";
    foreach (['employee' => [$unit,'analyst'],'reviewer' => [$unit,'reviewer'],'backup' => [$unit,'reviewer'],'outsider' => [$other,'reviewer'],'unassigned' => [null,'reviewer']] as $name => [$unitId,$role])
    {
        $s = $db->query($sSql, [$name,$name,$unitId,$role]);
        $people[$name] = (int)Database::Scalar($s);
    }
    Extract($people);
    $db->query("UPDATE role_permissions SET is_allowed=TRUE WHERE role_key='reviewer' AND module='leaves' AND permission='review'");
    $admin = (int)Database::Scalar($db->query("SELECT user_id FROM users WHERE role_key='admin' LIMIT 1"));
    DlActor($admin);
    DailyLogs::AssignReviewer($db, $admin, ['unit_id' => $unit,'reviewer_user_id' => $reviewer,'assignment_version' => 0]);
    DailyLogs::AssignReviewer($db, $admin, ['unit_id' => $other,'reviewer_user_id' => $outsider,'assignment_version' => 0]);
    DailyLogCalendar::Save($db, ['holiday_date' => '2026-10-27','holiday_name' => 'Organization holiday']);
    $holiday = DailyLogCalendar::Listing($db, $admin, ['year' => 2026])['holidays'][0];
    DlCheck(!DailyLogCalendar::Day($db, $outsider, '2026-10-27')['working'], 'Organization holidays apply to every section.');
    DlReject(fn () => DailyLogCalendar::Save($db, ['holiday_date' => '2026-10-27','holiday_name' => 'Duplicate']), 'Duplicate holiday dates are rejected.');
    DlReject(fn () => DailyLogCalendar::Save($db, ['holiday_date' => '2026-02-30','holiday_name' => 'Invalid']), 'Invalid holiday dates are rejected.');
    DlReject(fn () => DailyLogCalendar::Save($db, ['holiday_id' => $holiday['holiday_id'],'version' => 99,'holiday_date' => '2026-10-27','holiday_name' => 'Stale']), 'Stale holiday edits are rejected.');
    DlActor($employee);
    DlReject(fn () => DailyLogCalendar::Save($db, ['holiday_date' => '2026-11-01','holiday_name' => 'Denied']), 'Non-administrators cannot maintain holidays.');
    DlCheck(DailyLogs::ReportName('Michael Adrian', 'Dimaano', 'Monfero') === 'MADMONFERO', 'Employee report names follow the requested initials and surname format.');
    $work = ['activity_date' => '2026-10-26','entry_kind' => 'activity','status' => 'Submitted','classification' => 'SD','activity_type' => 'HD','planned_task' => '=HYPERLINK("https://example.invalid")','actual_activity' => 'PRIVATE_WORK_SENTINEL','duration_hours' => 8,'remarks' => 'PRIVATE_REMARK_SENTINEL'];
    foreach (array_keys(DailyLogs::ACTIVITIES) as $type)
    {
        DlCheck(DailyLogs::Normalize(array_replace($work, ['activity_type' => $type]))['activity_type'] === $type, 'Activity accepted: '.$type);
    }
    foreach (array_keys(DailyLogs::CLASSIFICATIONS) as $type)
    {
        DlCheck(DailyLogs::Normalize(array_replace($work, ['classification' => $type]))['classification'] === $type, 'Classification accepted: '.$type);
    }
    foreach (['classification' => 'BAD','activity_type' => 'XX','duration_hours' => 'NaN','activity_date' => '2026-02-30'] as $field => $value)
    {
        DlReject(fn () => DailyLogs::Normalize(array_replace($work, [$field => $value])), 'Invalid input rejected: '.$field);
    }
    $leave = ['activity_date' => '2026-10-29','entry_kind' => 'leave','status' => 'Submitted','leave_type' => 'VL','leave_period' => 'AM','duration_hours' => 4];
    DlReject(fn () => LeaveRequests::Save($db, $employee, array_replace($leave, ['activity_date' => '2026-10-27'])), 'Leave cannot be submitted on a holiday.');
    DlReject(fn () => LeaveRequests::Save($db, $employee, array_replace($leave, ['activity_date' => '2026-10-31'])), 'Leave cannot be submitted on a weekend.');
    $ids = [];
    $ids[] = DailyLogs::Save($db, $employee, $work);
    $ids[] = DailyLogs::Save($db, $employee, array_replace($work, ['activity_date' => '2026-10-28','duration_hours' => 4.25]));
    $leaveId = LeaveRequests::Save($db, $employee, $leave);
    DlReject(fn () => LeaveRequests::Save($db, $employee, $leave), 'Overlapping leave is rejected.');
    $ids[] = DailyLogs::Save($db, $employee, array_replace($work, ['activity_date' => '2026-10-29','duration_hours' => 4]));
    $ids[] = DailyLogs::Save($db, $employee, array_replace($work, ['activity_date' => '2026-10-30']));
    $ids[] = DailyLogs::Save($db, $employee, array_replace($work, ['activity_date' => '2026-10-31','duration_hours' => 2]));
    $draft = DailyLogs::Save($db, $employee, ['activity_date' => '2026-10-30','status' => 'Draft']);
    DlActor($reviewer);
    $pending = WeeklyLogReview::Get($db, $reviewer, $employee, '2026-10-28');
    DlCheck(count($pending['logs']) === 5 && $pending['week_start'] === '2026-10-26' && $pending['week_end'] === '2026-11-01', 'Weekly review covers all Monday-Sunday submissions and hides drafts.');
    DlCheck(WeeklyLogReview::Listing($db, $reviewer, [])['total'] === 1, 'One employee week appears once in the approval queue.');
    DlReject(fn () => DailyLogs::Get($db, $reviewer, $draft, true), 'Reviewers cannot open private drafts.');
    DlActor($employee);
    $submitted = DailyLogs::Get($db, $employee, $ids[0]);
    DailyLogs::Save($db, $employee, array_replace($submitted, ['remarks' => 'Corrected submitted work']));
    $updated = DailyLogs::Get($db, $employee, $ids[0]);
    DlCheck($updated['status'] === 'Submitted' && $updated['remarks'] === 'Corrected submitted work' && (int)$updated['version'] === (int)$submitted['version'] + 1, 'Owners can edit submitted work without changing its pending status.');
    DlReject(fn () => DailyLogs::Save($db, $employee, $submitted), 'Stale submitted edits cannot overwrite newer changes.');
    DlReject(fn () => DailyLogs::Save($db, $employee, array_replace($updated, ['status' => 'Draft'])), 'Submitted edits cannot be withdrawn into drafts.');
    DlReject(fn () => DailyLogs::Save($db, $employee, array_replace($updated, ['actual_activity' => ''])), 'Submitted edits still require complete activity details.');
    DlReject(fn () => DailyLogs::Save($db, $employee, array_replace(DailyLogs::Get($db, $employee, $ids[2]), ['duration_hours' => 21])), 'Submitted edits cannot push combined work and leave beyond 24 hours per day.');
    DlReject(fn () => DailyLogs::Delete($db, $employee, $updated), 'Submitted edit access does not allow deleting submitted logs.');
    $submittedLeave = LeaveRequests::Get($db, $employee, $leaveId);
    LeaveRequests::Save($db, $employee, array_replace($submittedLeave, ['remarks' => 'Corrected submitted leave']));
    $updatedLeave = LeaveRequests::Get($db, $employee, $leaveId);
    DlCheck($updatedLeave['status'] === 'Submitted' && $updatedLeave['remarks'] === 'Corrected submitted leave', 'Owners can also edit submitted leave.');
    DlReject(fn () => LeaveRequests::Save($db, $employee, array_replace($updatedLeave, ['date_from' => '2026-10-27','date_to' => '2026-10-27'])), 'Submitted leave edits cannot move leave onto a holiday.');
    DlActor($reviewer);
    DlReject(fn () => DailyLogs::Save($db, $reviewer, $updated), 'Reviewers cannot edit another owner\'s submitted logs.');
    DlReject(fn () => DailyLogs::Review($db, $reviewer, ['items' => [['user_id' => $employee,'week_start' => $pending['week_start'],'token' => $pending['token']]]]), 'Submitted content edits invalidate a previously opened weekly approval.');
    $m = DailyLogs::Monitoring($db, $reviewer, ['week_start' => '2026-10-26','q' => 'employee'])['members'][0];
    DlCheck($m['entered_hours'] == 28.25 && $m['remaining_hours'] == 3.75 && $m['working_days'] === 4 && $m['activity_hours'] == 0, 'Monitoring shows submitted hours and the daily shortfall without treating them as approved.');
    DlCheck($m['days'][1]['holiday'] === 'Organization holiday' && $m['days'][1]['target_hours'] === 0, 'Holiday cells have zero target hours.');
    DlActor($employee);
    $ids[] = DailyLogs::Save($db, $employee, array_replace($work, ['activity_date' => '2026-10-28','duration_hours' => 3.75]));
    DlActor($reviewer);
    $item = ['user_id' => $employee,'week_start' => $pending['week_start'],'token' => $pending['token']];
    DlReject(fn () => DailyLogs::Review($db, $reviewer, ['items' => [$item]]), 'Changed weekly snapshots cannot be approved.');
    DlReject(fn () => DailyLogs::Review($db, $reviewer, ['items' => [['log_id' => $ids[0],'version' => 1]]]), 'Individual-log approval requests are rejected.');
    $pending = WeeklyLogReview::Get($db, $reviewer, $employee, '2026-10-26');
    $item = ['user_id' => $employee,'week_start' => $pending['week_start'],'token' => $pending['token']];
    DlActor($outsider);
    DlReject(fn () => WeeklyLogReview::Get($db, $outsider, $employee, '2026-10-26'), 'Review snapshots cannot cross sections.');
    DlReject(fn () => DailyLogs::Review($db, $outsider, ['items' => [$item]]), 'Weekly decisions cannot cross sections.');
    DlActor($reviewer);
    DlCheck(DailyLogs::Review($db, $reviewer, ['items' => [$item]]) === 1, 'One decision approves the entire employee week.');
    DlCheck(LeaveRequests::Get($db, $reviewer, $leaveId)['status'] === 'Submitted', 'Weekly work approval leaves submitted leave awaiting its separate decision.');
    $leaveReview = LeaveRequests::Get($db, $reviewer, $leaveId);
    LeaveRequests::Review($db, $reviewer, ['log_id' => $leaveId,'version' => $leaveReview['version'],'decision' => 'Reviewed']);
    $approved = WeeklyLogReview::Get($db, $reviewer, $employee, '2026-10-26');
    DlCheck(count(array_filter($approved['logs'], fn ($l) => $l['status'] === 'Reviewed')) === 6 && $approved['decision']['status'] === 'Reviewed', 'All submitted entries share one recorded weekly approval.');
    DlActor($employee);
    DlReject(fn () => DailyLogs::Save($db, $employee, array_replace(DailyLogs::Get($db, $employee, $ids[0]), ['status' => 'Submitted'])), 'Reviewed entries stay locked until the week is returned.');
    DlActor($reviewer);
    DlCheck(DailyLogs::Get($db, $employee, $draft)['status'] === 'Draft', 'Weekly approval leaves private drafts unchanged.');
    DlReject(fn () => DailyLogs::Review($db, $reviewer, ['items' => [['user_id' => $employee,'week_start' => '2026-10-26','token' => $approved['token']]]]), 'An unchanged approved week cannot be approved twice.');
    $m = DailyLogs::Monitoring($db, $reviewer, ['week_start' => '2026-10-26','q' => 'employee'])['members'][0];
    DlCheck($m['complete'] && $m['entered_hours'] == 32 && $m['remaining_hours'] == 0 && $m['activity_hours'] == 28 && $m['target_hours'] == 28, 'Approved work and leave reconcile to the four-day holiday-adjusted target.');
    $summary = DailyLogs::WeeklySummary($db, $reviewer, ['user_id' => $employee,'week_start' => '2026-10-26'], true, true);
    DlCheck($summary['totals']['total'] == 28 && count($summary['leaves']) === 1 && $summary['leaves'][0]['leave_type'] === 'VL', 'Weekly summary separates weekday work from leave and weekend work.');
    $data = DailyLogs::SectionSummaries($db, $reviewer, ['unit_id' => $unit,'week_start' => '2026-10-26']);
    $book = WeeklySummaryWorkbook::Build($data);
    $path = tempnam(__DIR__, '.tmp-weekly-');
    try
    {
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->Save($path);
        $book->disconnectWorksheets();
        $loaded = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $loaded->getSheetByName('EFIXTURE');
        DlCheck($loaded->getSheetCount() === 3 && $sheet->getCell('A1')->getValue() === 'WEEKLY SUMMARY' && $sheet->getCell('A2')->getValue() === 'EFIXTURE', 'Sample export creates one named sheet per section member.');
        DlCheck($sheet->getCell('E25')->getValue() == 28 && $sheet->getCell('B27')->getValue() === 'Date' && $sheet->getCell('A28')->getValue() === 'Leaves:' && $sheet->getCell('B28')->getValue() === '2026-10-29' && $sheet->getCell('C28')->getValue() === 'VL', 'Sample export retains totals and its leave table at the reference positions.');
        DlCheck($sheet->getStyle('A1')->getFill()->getStartColor()->getRGB() === 'FCD5B5' && $sheet->getStyle('B4')->getFill()->getStartColor()->getRGB() === 'C6D9F1', 'Sample export matches orange and blue reference fills.');
        $loaded->disconnectWorksheets();
    }
    finally
    {
        if (is_file($path))
        {
            unlink($path);
        }
    }
    DlActor($employee);
    $new = DailyLogs::Save($db, $employee, array_replace($work, ['activity_date' => '2026-10-30','duration_hours' => 1]));
    DlActor($reviewer);
    $reopened = WeeklyLogReview::Get($db, $reviewer, $employee, '2026-10-26');
    DlCheck($reopened['status'] === 'Submitted' && count(array_filter($reopened['logs'], fn ($l) => $l['status'] === 'Reviewed')) === 0, 'A new submission invalidates approval for the whole week.');
    DlReject(fn () => DailyLogs::Review($db, $reviewer, ['items' => [['user_id' => $employee,'week_start' => '2026-10-26','token' => $reopened['token']]],'decision' => 'Returned']), 'Returning a week requires a comment.');
    DailyLogs::Review($db, $reviewer, ['items' => [['user_id' => $employee,'week_start' => '2026-10-26','token' => $reopened['token']]],'decision' => 'Returned','review_comment' => 'Correct weekly details']);
    $returned = WeeklyLogReview::Get($db, $reviewer, $employee, '2026-10-26');
    DlCheck(count(array_filter($returned['logs'], fn ($l) => $l['status'] === 'Returned')) === 7, 'Returning one week returns all its work entries together.');
    DlCheck(LeaveRequests::Get($db, $reviewer, $leaveId)['status'] === 'Reviewed', 'Returning a work week preserves its independent leave approval.');
    DlActor($employee);
    foreach ($returned['logs'] as $log)
    {
        $log['status'] = 'Submitted';
        DailyLogs::Save($db, $employee, $log);
    }
    DlActor($reviewer);
    $pending = WeeklyLogReview::Get($db, $reviewer, $employee, '2026-10-26');
    DlCheck($pending['status'] === 'Submitted', 'Corrected entries can be resubmitted for one fresh weekly decision.');
    DailyLogs::Review($db, $reviewer, ['items' => [['user_id' => $employee,'week_start' => '2026-10-26','token' => $pending['token']]]]);
    DlActor($employee);
    $a = DailyLogs::Save($db, $employee, array_replace($work, ['activity_date' => '2026-11-02']));
    $b = DailyLogs::Save($db, $employee, array_replace($work, ['activity_date' => '2026-11-09']));
    DlActor($reviewer);
    $wa = WeeklyLogReview::Get($db, $reviewer, $employee, '2026-11-02');
    $wb = WeeklyLogReview::Get($db, $reviewer, $employee, '2026-11-09');
    DlActor($employee);
    DailyLogs::Save($db, $employee, array_replace($work, ['activity_date' => '2026-11-09','duration_hours' => 0.25]));
    DlActor($reviewer);
    DlReject(fn () => DailyLogs::Review($db, $reviewer, ['items' => [['user_id' => $employee,'week_start' => $wa['week_start'],'token' => $wa['token']],['user_id' => $employee,'week_start' => $wb['week_start'],'token' => $wb['token']]]]), 'A stale week rejects an entire bulk weekly decision.');
    DlCheck(DailyLogs::Get($db, $reviewer, $a, true)['status'] === 'Submitted', 'A failed bulk decision leaves earlier weeks unchanged.');
    $own = DailyLogs::Save($db, $reviewer, array_replace($work, ['activity_date' => '2026-11-02']));
    $ownWeek = WeeklyLogReview::Get($db, $reviewer, $reviewer, '2026-11-02');
    DlReject(fn () => DailyLogs::Review($db, $reviewer, ['items' => [['user_id' => $reviewer,'week_start' => $ownWeek['week_start'],'token' => $ownWeek['token']]]]), 'Reviewers cannot approve their own week.');
    $detail = DailyLogReports::Daily($db, $reviewer, ['unit_id' => $unit,'date_from' => '2026-10-26','date_to' => '2026-11-01']);
    $detailBook = DailyLogsWorkbook::Build($detail);
    DlCheck($detailBook->getSheetByName('EFIXTURE')->getCell('E6')->getDataType() === 's', 'Detailed report still treats activity text as literal text.');
    $detailBook->disconnectWorksheets();
    DlActor($admin);
    $migration = str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/migration_024_weekly_approval_holidays.sql'));
    $db->query($migration);
    DlCheck(count(DailyLogCalendar::Listing($db, $admin, ['year' => 2026])['holidays']) === 1, 'Reapplying migration 024 preserves holidays and weekly approvals.');
    $db->query("UPDATE role_permissions SET is_allowed=FALSE WHERE role_key='analyst' AND module IN('wiki','holidays')");
    $before = $db->query('SELECT * FROM role_permissions ORDER BY permission_id')->getResultArray();
    $calendarBefore = DailyLogCalendar::Listing($db, $admin, ['year' => 2026]);
    $migration = str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/migration_025_wiki_holidays_modules.sql'));
    $db->query($migration);
    $db->query($migration);
    DlCheck($db->query('SELECT * FROM role_permissions ORDER BY permission_id')->getResultArray() === $before, 'Migration 025 preserves explicitly revoked Wiki and Holiday grants on reapplication.');
    DlCheck(DailyLogCalendar::Listing($db, $admin, ['year' => 2026]) === $calendarBefore, 'Moving Holidays into its own module preserves existing calendar records.');
    DailyLogCalendar::Delete($db, ['holiday_id' => $holiday['holiday_id'],'version' => $holiday['version']]);
    DlCheck(DailyLogs::Monitoring($db, $admin, ['week_start' => '2026-10-26','q' => 'employee'])['members'][0]['remaining_hours'] == 8, 'Removing a holiday restores required hours without double-counting surplus on other dates.');
    Roles::Create($db, ['role_key' => 'team_observer','role_name' => 'Team Observer']);
    $db->query("UPDATE users SET role_key='team_observer' WHERE user_id=?", [$backup]);
    DlActor($backup);
    DlCheck(!Permissions::Can('teammonitoring'), 'New custom roles require an explicit monitoring grant.');
    DlCheck(Permissions::Can('wiki') && !Permissions::Can('holidays'), 'New minimal roles can read the Wiki and require a separate Holiday View grant.');
    Roles::SavePermissions($db, ['role_key' => 'team_observer','grants' => [['module' => 'teammonitoring','permission' => 'view','is_allowed' => true]]]);
    Permissions::Reset();
    DlCheck(Permissions::Can('teammonitoring') && DailyLogs::Monitoring($db, $backup, [])['total'] === 3, 'Custom role monitoring remains available without approval assignment.');
    DlReject(fn () => DailyLogs::Monitoring($db, $backup, ['unit_id' => $other]), 'Custom roles remain section-scoped.');
    DlCheck(!Permissions::Can('reviewlogs'), 'Monitoring access does not grant weekly approval rights.');
    echo "Daily log fixtures: {$checks} assertions passed.\n";
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
