<?php

/**
 * Filename: DailyLogReports.php
 * Purpose: Provide daily log reports processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;

require_once __DIR__.'/DailyLogs.php';
class DailyLogReports
{
    public static function HasTeamAccess(BaseConnection $db, int $actor): bool
    {
        return Permissions::UserCan($db, $actor, 'teammonitoring', 'view') || Permissions::HasDailyLogAssignment($db, $actor);
    }
    public static function Daily(BaseConnection $db, int $actor, array $data): array
    {
        Permissions::require('reports', 'view');
        $unit = (int)($data['unit_id'] ?? 0);
        if ($unit <= 0)
        {
            throw new InvalidArgumentException('Choose one unit / section.');
        }
        [$where,$params] = DailyLogs::Scope($db, $actor, $unit, false, true);
        $from = DailyLogs::Date($data['date_from'] ?? null);
        $to = DailyLogs::Date($data['date_to'] ?? null);
        if ($from > $to)
        {
            throw new InvalidArgumentException('Date from must be on or before date to.');
        }
        $status = DailyLogs::Text($data['status'] ?? '', 10);
        if (!in_array($status, ['','Submitted','Reviewed','Returned'], true))
        {
            throw new InvalidArgumentException('Choose a valid report status. Private drafts cannot be reported.');
        }
        
        $s = $db->table('org_units')->select('unit_id,unit_name', false)->where('unit_id', $unit)->get();
        $section = $s->getUnbufferedRow('array');
        if (!$section)
        {
            throw new OutOfBoundsException('Unit / section not found.');
        }
        $sSql = "SELECT u.user_id,u.employee_id,u.first_name,u.middle_name,u.last_name,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name FROM users u WHERE u.unit_id=?".$where.' ORDER BY u.last_name,u.first_name,u.user_id';
        $s = $db->query($sSql, array_merge([$unit], $params));
        $reports = [];
        foreach ($s->getResultArray() as $employee)
        {
            $employee['report_name'] = DailyLogs::ReportName($employee['first_name'], $employee['middle_name'] ?? '', $employee['last_name']);
            $employee['unit_name'] = $section['unit_name'];
            $reports[$employee['user_id']] = ['employee' => $employee,'logs' => [],'work_hours' => 0,'leave_hours' => 0];
        }
        if (!$reports)
        {
            throw new OutOfBoundsException('There are no personnel in this section.');
        }
        $sSql = "SELECT l.*,CONCAT_WS(' ',r.first_name,r.last_name) reviewer_name FROM daily_logs l JOIN users u ON u.user_id=l.user_id LEFT JOIN users r ON r.user_id=l.reviewed_by
            WHERE u.unit_id=? AND l.status<>'Draft' AND l.activity_date BETWEEN ? AND ?".$where.($status !== '' ? ' AND l.status=?' : '').' ORDER BY l.user_id,l.activity_date,l.log_id';
        $s = $db->query($sSql, array_merge([$unit,$from,$to], $params, $status !== '' ? [$status] : []));
        foreach ($s->getResultArray() as $row)
        {
            if (!isset($reports[$row['user_id']]))
            {
                continue;
            }
            $reports[$row['user_id']]['logs'][] = $row;
            $reports[$row['user_id']][$row['entry_kind'] === 'activity' ? 'work_hours' : 'leave_hours'] += (int)round((float)$row['duration_hours'] * 100);
        }
        foreach ($reports as &$report)
        {
            $report['work_hours'] /= 100;
            $report['leave_hours'] /= 100;
        }
        unset($report);
        return ['section' => $section,'date_from' => $from,'date_to' => $to,'status' => $status,'reports' => array_values($reports)];
    }
    public static function Type(array $data): string
    {
        $type = $data['report_type'] ?? 'daily';
        if (!in_array($type, ['daily','weekly'], true))
        {
            throw new InvalidArgumentException('Choose Daily Logs or Weekly Summary.');
        }
        return $type;
    }
    public static function Preview(BaseConnection $db, int $actor, array $data): array
    {
        Permissions::require('reports', 'view');
        $type = self::Type($data);
        $result = $type === 'daily' ? self::Daily($db, $actor, $data) : DailyLogs::SectionSummaries($db, $actor, $data);
        foreach ($result['reports'] as &$report)
        {
            if ($type === 'daily')
            {
                $report['log_count'] = count($report['logs']);
                unset($report['logs']);
            }
            else
            {
                $report['work_hours'] = $report['totals']['total'];
                $report['leave_hours'] = 0;
                unset($report['rows'],$report['totals']);
            }
        }
        unset($report);
        $result['report_type'] = $type;
        return $result;
    }
}
