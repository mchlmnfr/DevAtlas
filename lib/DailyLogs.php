<?php

/**
 * Filename: DailyLogs.php
 * Purpose: Provide daily logs processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Keep daily log writes and weekly review changes limited to work activities; leave uses its own module.
 */

use CodeIgniter\Database\BaseConnection;

/** Daily log ownership, unit scope, workflow and weekly completion calculations. */
require_once __DIR__.'/Permissions.php';
require_once __DIR__.'/DailyLogCalendar.php';
require_once __DIR__.'/WeeklyLogReview.php';
class DailyLogs
{
    public const CLASSIFICATIONS = ['SD' => 'Service Delivery','SCR' => 'System Change Request','PROJECT' => 'Project'];
    public const ACTIVITIES = ['AD' => 'Administrative Functions','AN' => 'Analysis','CR' => 'Code Review','DD' => 'Development','DP' => 'Documentation','DR' => 'Document Standards Review','ED' => 'Education','EM' => 'Meeting with 3rd Party','HD' => 'Helpdesk Functions','MR' => 'Management Reporting','PI' => 'Post Implementation','RV' => 'Review','SF' => 'Special Function','ST' => 'System Integration Testing','TD' => 'Technical Documentation','TM' => 'Technical Meeting','UA' => 'User Acceptance Testing','UM' => 'Meeting with Users','UT' => 'Unit Testing','VC' => 'Version Control'];
    public const ACTIVITY_DESCRIPTIONS = [
        'TM' => 'Discussion/Meeting within ITG',
        'UM' => 'Discussion/Meeting with users',
        'EM' => 'Discussion/Meeting with external parties outside BMI',
        'AN' => 'Business Requirement Analysis for Projects/Business Impact Analysis for Service Delivery',
        'TD' => 'Preparation of Technical Documents (System Design, Process Flow, Data Flow, Program & Report Inventory)',
        'ED' => "Knowledge Transfer Session, Mentoring (for TL's and Officers only), Research, Training (external / internal)",
        'AD' => 'Administrative Meeting within Unit, Coaching, PCF/PAR preparation',
        'DD' => 'Program Development/Coding, Unit testing session, Script creation',
        'UT' => 'Testing proper, Documentation of testing',
        'CR' => 'Code review against Coding standards',
        'DR' => 'Documentary standards validation',
        'VC' => 'Program / System Revision Monitoring',
        'HD' => 'System Support, Bug Resolution, Problem Isolation, Inquiry',
        'ST' => 'SIT Proper, Documentation of results',
        'UA' => 'Assistance on UAT, Deployment to UAT environment',
        'PI' => 'Post Implementation Review including Documentation',
        'DP' => 'Preparation of MOM, Project Dashboard update, Project Documentation (Charter, Understanding Document, Test Scripts, Deployment Documents), Daily / Monthly Reports preparation',
        'RV' => 'Documentation review, SMF review, SOS review, UD review, TD review, PD review, Cost and Benefit Analysis, Project / SCR Assessment',
        'SF' => 'Other functions not related to ITG functions such as: FRA meeting, Compliance Advocate Meeting, CIMS Meeting',
        'MR' => "ITMSC Meeting, ManCom Meeting, Board of Directors Presentation, Other Board Committees' presentation",
    ];
    public const LEAVES = ['VL' => 'Vacation Leave','SL' => 'Sick Leave','ML' => 'Maternity Leave','EL' => 'Emergency Leave','UT' => 'Undertime'];
    public const SUMMARY_ACTIVITIES = ['TM','UM','EM','AN','TD','ED','AD','DD','UT','CR','DR','VC','HD','ST','UA','PI','DP','RV','SF','MR'];
    public static function ReportName(string $first, string $middle, string $last): string
    {
        $initials = '';
        foreach (preg_split('/\s+/u', trim($first), -1, PREG_SPLIT_NO_EMPTY) as $name)
        {
            $initials .= mb_substr($name, 0, 1);
        }
        return mb_strtoupper($initials.mb_substr(trim($middle), 0, 1).trim($last), 'UTF-8');
    }
    public static function Text($value, int $max = 10000): string
    {
        if (!is_string($value) || mb_strlen($value) > $max)
        {
            throw new InvalidArgumentException('Invalid text or maximum length exceeded.');
        }
        return trim($value);
    }
    public static function Date($value): string
    {
        if (!is_string($value))
        {
            throw new InvalidArgumentException('Choose a valid activity date.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
        if (!$date || $date->format('Y-m-d') !== $value || $value < '1900-01-01' || $value > '2100-12-31')
        {
            throw new InvalidArgumentException('Choose a valid date between 1900 and 2100.');
        }
        return $value;
    }
    public static function Profile(BaseConnection $db, int $actor): array
    {
        $sSql = 'SELECT u.user_id,u.unit_id,un.unit_name FROM users u LEFT JOIN org_units un ON un.unit_id=u.unit_id WHERE u.user_id=? AND u.is_active AND NOT u.is_locked';
        $s = $db->query($sSql, [$actor]);
        $row = $s->getUnbufferedRow('array');
        if (!$row)
        {
            throw new DomainException('Your account is unavailable.');
        }
        return $row;
    }
    /** Monitoring follows role grants. Head roles may review themselves without a section assignment. */
    public static function Scope(BaseConnection $db, int $actor, int $requested = 0, bool $isLock = false, bool $isMonitor = false): array
    {
        $profile = self::Profile($db, $actor);
        if (Permissions::IsAdmin())
        {
            return $requested ? [' AND u.unit_id=?',[$requested]] : ['',[]];
        }
        $isSelfReview = !$isMonitor && Permissions::HasSelfReviewRole($db, $actor, $isLock);
        if ($isSelfReview && !$profile['unit_id'])
        {
            if ($requested)
            {
                throw new DomainException('You can only review your own logs without a unit / section assignment.');
            }
            return [' AND u.user_id=?',[$actor]];
        }
        if (!$profile['unit_id'])
        {
            throw new DomainException('Assign your account to a unit / section before accessing team logs.');
        }
        if ($requested && $requested !== (int)$profile['unit_id'])
        {
            throw new DomainException('You can only access your own unit / section.');
        }
        if ($isMonitor && Permissions::UserCan($db, $actor, 'teammonitoring', 'view'))
        {
            return [' AND u.unit_id=?',[(int)$profile['unit_id']]];
        }
        $sSql = 'SELECT reviewer_user_id FROM daily_log_reviewers WHERE unit_id=?'.($isLock ? ' FOR SHARE' : '');
        $s = $db->query($sSql, [(int)$profile['unit_id']]);
        if ((int)Database::Scalar($s) !== $actor)
        {
            if ($isSelfReview)
            {
                return [' AND u.user_id=?',[$actor]];
            }
            throw new DomainException('Only the assigned reviewer can access team logs for this unit / section.');
        }
        return [' AND u.unit_id=?',[(int)$profile['unit_id']]];
    }
    public static function Units(BaseConnection $db, int $actor, bool $isMonitor = false): array
    {
        [$where,$params] = self::Scope($db, $actor, 0, false, $isMonitor);
        $sSql = 'SELECT DISTINCT un.unit_id,un.unit_name FROM org_units un JOIN users u ON u.unit_id=un.unit_id WHERE TRUE'.$where.' ORDER BY un.unit_name';
        $s = $db->query($sSql, $params);
        return $s->getResultArray();
    }
    public static function Get(BaseConnection $db, int $actor, int $id, bool $isTeam = false, bool $isMonitor = false): array
    {
        [$where,$params] = $isTeam ? self::Scope($db, $actor, 0, false, $isMonitor) : [' AND u.user_id=?',[$actor]];
        $sSql = "SELECT l.*,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name,u.employee_id,un.unit_name,
            CONCAT_WS(' ',r.first_name,r.last_name) reviewer_name FROM daily_logs l JOIN users u ON u.user_id=l.user_id
            LEFT JOIN org_units un ON un.unit_id=u.unit_id LEFT JOIN users r ON r.user_id=l.reviewed_by WHERE l.log_id=?".$where.($isTeam ? " AND l.status<>'Draft'" : '');
        $s = $db->query($sSql, array_merge([$id], $params));
        $row = $s->getUnbufferedRow('array');
        if (!$row)
        {
            throw new OutOfBoundsException('Log not found or unavailable in your scope.');
        }
        return $row;
    }
    public static function Normalize(array $d): array
    {
        $kind = $d['entry_kind'] ?? 'activity';
        $status = $d['status'] ?? 'Draft';
        if (!in_array($kind, ['activity','leave'], true) || !in_array($status, ['Draft','Submitted'], true))
        {
            throw new InvalidArgumentException('Choose a valid entry type and save action.');
        }
        $row = ['activity_date' => self::Date($d['activity_date'] ?? null),'entry_kind' => $kind,'status' => $status,
            'classification' => null,'activity_type' => null,'leave_type' => null,'leave_period' => null,
            'planned_task' => self::Text($d['planned_task'] ?? ''),'actual_activity' => self::Text($d['actual_activity'] ?? ''),'remarks' => self::Text($d['remarks'] ?? '')];
        $hours = $d['duration_hours'] ?? 0;
        if ($hours === '')
        {
            $hours = 0;
        }
        if (!is_scalar($hours) || !is_numeric($hours) || !is_finite((float)$hours) || (float)$hours < 0 || (float)$hours > 24 || abs(round((float)$hours, 2) - (float)$hours) > 0.000001)
        {
            throw new InvalidArgumentException('Hours must be between 0 and 24, with at most two decimal places.');
        }
        $row['duration_hours'] = (float)$hours;
        if ($kind === 'activity')
        {
            foreach (['classification' => self::CLASSIFICATIONS,'activity_type' => self::ACTIVITIES] as $field => $options)
            {
                $v = $d[$field] ?? '';
                if (!is_string($v) || ($v !== '' && !isset($options[$v])))
                {
                    throw new InvalidArgumentException('Choose a valid classification and activity type.');
                }
                $row[$field] = $v === '' ? null : $v;
            }
            if ($status === 'Submitted' && (!$row['classification'] || !$row['activity_type'] || $row['planned_task'] === '' || $row['actual_activity'] === ''))
            {
                throw new InvalidArgumentException('Complete classification, activity type, planned task and actual activity before submitting.');
            }
        }
        else
        {
            $type = $d['leave_type'] ?? '';
            $period = $d['leave_period'] ?? '';
            if (!is_string($type) || ($type !== '' && !isset(self::LEAVES[$type])))
            {
                throw new InvalidArgumentException('Choose a valid leave type.');
            }
            $row['leave_type'] = $type === '' ? null : $type;
            $row['planned_task'] = '';
            $row['actual_activity'] = '';
            if ($type === 'UT')
            {
                if ($row['duration_hours'] > 8)
                {
                    throw new InvalidArgumentException('Undertime must not exceed 8 hours.');
                }
            }
            else
            {
                if (!is_string($period) || !in_array($period, ['','AM','PM','WHOLE'], true))
                {
                    throw new InvalidArgumentException('Choose AM, PM or whole day.');
                }
                $row['leave_period'] = $period === '' ? null : $period;
                $row['duration_hours'] = $period === 'WHOLE' ? 8 : ($period !== '' ? 4 : 0);
            }
            if ($status === 'Submitted' && (!$type || ($type !== 'UT' && !$period)))
            {
                throw new InvalidArgumentException('Complete the leave type and period before submitting.');
            }
        }
        if ($status === 'Submitted' && $row['duration_hours'] <= 0)
        {
            throw new InvalidArgumentException('Enter duration greater than zero before submitting.');
        }
        return $row;
    }
    public static function Save(BaseConnection $db, int $actor, array $data): int
    {
        $id = (int)($data['log_id'] ?? 0);
        Permissions::require('dailylogs', $id ? 'edit' : 'create');
        $row = self::Normalize($data);
        if ($row['entry_kind'] !== 'activity')
        {
            throw new InvalidArgumentException('Apply for leave in the Leaves module.');
        }
        $db->transBegin();
        try
        {
            // Serialize changes for one owner, including competing submissions and reviews.
            $db->query($db->table('users')->select('user_id', false)->where('user_id', $actor)->getCompiledSelect() . ' FOR UPDATE');
            if ($id)
            {
                
                $s = $db->query($db->table('daily_logs')->where('log_id', $id)->where('user_id', $actor)->getCompiledSelect() . ' FOR UPDATE');
                $old = $s->getUnbufferedRow('array');
                if (!$old)
                {
                    throw new OutOfBoundsException('Log not found.');
                }
                if ($old['entry_kind'] !== 'activity')
                {
                    throw new DomainException('Manage this leave request in the Leaves module.');
                }
                if (!in_array($old['status'], ['Draft','Submitted','Returned'], true))
                {
                    throw new DomainException('Only draft, submitted or returned logs can be edited.');
                }
                if ($old['status'] === 'Submitted' && $row['status'] !== 'Submitted')
                {
                    throw new DomainException('Submitted logs must remain submitted when edited.');
                }
                if ((int)($data['version'] ?? 0) !== (int)$old['version'])
                {
                    throw new DomainException('This log changed. Reopen it before saving.');
                }
            }
            if ($row['status'] === 'Submitted')
            {
                if ($row['entry_kind'] === 'leave' && !DailyLogCalendar::Day($db, $actor, $row['activity_date'])['working'])
                {
                    throw new InvalidArgumentException('Leave and undertime must be recorded on a working day, excluding holidays.');
                }
                self::ValidateDay($db, $actor, $row, $id);
            }
            $fields = ['activity_date','entry_kind','classification','activity_type','planned_task','actual_activity','duration_hours','leave_type','leave_period','remarks','status'];
            $values = array_map(fn ($f) => $row[$f], $fields);
            if ($id)
            {
                $sSql = 'UPDATE daily_logs SET '.implode(',', array_map(fn ($f) => $f.'=?', $fields)).",submitted_at=CASE WHEN ?='Submitted' THEN NOW() ELSE NULL END,reviewed_by=NULL,reviewed_at=NULL,version=version+1,updated_at=NOW() WHERE log_id=? AND user_id=?";
                $s = $db->query($sSql, array_merge($values, [$row['status'],$id,$actor]));
            }
            else
            {
                $sSql = 'INSERT INTO daily_logs('.implode(',', $fields).",user_id,submitted_at) VALUES(".implode(',', array_fill(0, count($fields), '?')).",?,CASE WHEN ?='Submitted' THEN NOW() ELSE NULL END) RETURNING log_id";
                $s = $db->query($sSql, array_merge($values, [$actor,$row['status']]));
                $id = (int)Database::Scalar($s);
            }
            if ($row['status'] === 'Submitted')
            {
                [$weekStart,$weekEnd] = DailyLogCalendar::Week($row['activity_date']);
                
                $s = $db->table('daily_logs')->set('status', 'Submitted')->set('reviewed_by', null)->set('reviewed_at', null)->set('review_comment', '')->set('version', new \CodeIgniter\Database\RawSql('version+1'))->set('updated_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('user_id', $actor)->where('activity_date >=', $weekStart)->where('activity_date <=', $weekEnd)->where('entry_kind', 'activity')->where('status', 'Reviewed')->update();
                $sSql = "INSERT INTO daily_log_week_reviews(user_id,week_start,status) VALUES(?,?,'Submitted') ON CONFLICT(user_id,week_start) DO UPDATE SET status='Submitted',reviewed_by=NULL,reviewed_at=NULL,version=daily_log_week_reviews.version+1,updated_at=NOW()";
                $s = $db->query($sSql, [$actor,$weekStart]);
            }
            $db->transCommit();
            return $id;
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw $e;
        }
    }
    public static function ValidateDay(BaseConnection $db, int $actor, array $row, int $id): void
    {
        
        $s = $db->table('daily_logs')->where('user_id', $actor)->where('activity_date', $row['activity_date'])->where('log_id <>', $id)->whereIn('status', ['Submitted', 'Reviewed'])->get();
        $others = $s->getResultArray();
        $hours = $row['duration_hours'];
        $leave = $row['entry_kind'] === 'leave' ? $hours : 0;
        foreach ($others as $old)
        {
            $hours += (float)$old['duration_hours'];
            if ($old['entry_kind'] === 'leave')
            {
                $leave += (float)$old['duration_hours'];
                if ($row['entry_kind'] === 'leave' && $row['leave_type'] !== 'UT' && $old['leave_type'] !== 'UT' && ($row['leave_period'] === 'WHOLE' || $old['leave_period'] === 'WHOLE' || $row['leave_period'] === $old['leave_period']))
                {
                    throw new InvalidArgumentException('This leave period overlaps an existing submitted or approved leave.');
                }
            }
        }
        if ($leave > 8.00001)
        {
            throw new InvalidArgumentException('Submitted leave and undertime cannot exceed 8 hours per day.');
        }
        if ($hours > 24.00001)
        {
            throw new InvalidArgumentException('Submitted entries cannot exceed 24 hours per day.');
        }
    }
    public static function Delete(BaseConnection $db, int $actor, array $data): void
    {
        Permissions::require('dailylogs', 'delete');
        $db->transBegin();
        try
        {
            $db->query($db->table('users')->select('user_id', false)->where('user_id', $actor)->getCompiledSelect() . ' FOR UPDATE');
            
            $s = $db->table('daily_logs')->where('log_id', (int)($data['log_id'] ?? 0))->where('user_id', $actor)->where('version', (int)($data['version'] ?? 0))->where('entry_kind', 'activity')->whereIn('status', ['Draft', 'Returned'])->delete();
            if (!$db->affectedRows())
            {
                throw new DomainException('Only your unchanged draft or returned logs can be deleted.');
            }
            $db->transCommit();
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw $e;
        }
    }
    public static function Listing(BaseConnection $db, int $actor, array $data, bool $isTeam = false, bool $isExcludeSelf = false, bool $isMonitor = false): array
    {
        [$where,$params] = $isTeam ? self::Scope($db, $actor, (int)($data['unit_id'] ?? 0), false, $isMonitor) : [' AND u.user_id=?',[$actor]];
        if ($isTeam)
        {
            $where .= " AND l.status<>'Draft'";
        }
        if ($isTeam && $isExcludeSelf)
        {
            $where .= ' AND u.user_id<>?';
            $params[] = $actor;
        }
        $status = self::Text($data['status'] ?? ($isTeam ? 'Submitted' : ''), 10);
        if ($status !== '' && !in_array($status, ['Draft','Submitted','Reviewed','Returned'], true))
        {
            throw new InvalidArgumentException('Choose a valid status.');
        }
        if ($status !== '')
        {
            $where .= ' AND l.status=?';
            $params[] = $status;
        }
        $q = self::Text($data['q'] ?? '', 200);
        if ($q !== '')
        {
            $where .= " AND CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name,u.employee_id) ILIKE ?";
            $params[] = '%'.$q.'%';
        }
        $from = ($data['date_from'] ?? '') !== '' ? self::Date($data['date_from']) : null;
        $to = ($data['date_to'] ?? '') !== '' ? self::Date($data['date_to']) : null;
        if ($from && $to && $from > $to)
        {
            throw new InvalidArgumentException('Date from must be on or before date to.');
        }
        foreach (['>=' => $from,'<=' => $to] as $op => $date)
        {
            if ($date)
            {
                $where .= ' AND l.activity_date'.$op.'?';
                $params[] = $date;
            }
        }
        if ($isTeam && !empty($data['user_id']))
        {
            $where .= ' AND u.user_id=?';
            $params[] = (int)$data['user_id'];
        }
        $sql = " FROM daily_logs l JOIN users u ON u.user_id=l.user_id LEFT JOIN org_units un ON un.unit_id=u.unit_id WHERE l.entry_kind='activity'".$where;
        $sSql = 'SELECT COUNT(*)'.$sql;
        $s = $db->query($sSql, $params);
        $total = (int)Database::Scalar($s);
        $pages = max(1, (int)ceil($total / 25));
        $page = min($pages, max(1, (int)($data['page'] ?? 1)));
        $sSql = "SELECT l.log_id,l.user_id,l.version,l.activity_date,l.entry_kind,l.activity_type,l.classification,l.duration_hours,l.leave_type,l.leave_period,l.status,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name,u.employee_id,un.unit_name".$sql.' ORDER BY l.activity_date DESC,l.log_id DESC LIMIT 25 OFFSET '.(($page - 1) * 25);
        $s = $db->query($sSql, $params);
        return ['logs' => $s->getResultArray(),'total' => $total,'page' => $page,'pages' => $pages];
    }
    /** Reviews are always scoped to a complete employee week. */
    public static function Review(BaseConnection $db, int $actor, array $data): int
    {
        return WeeklyLogReview::Review($db, $actor, $data);
    }
    public static function ReviewerAssignments(BaseConnection $db): array
    {
        if (!Permissions::IsAdmin())
        {
            throw new DomainException('Only administrators can manage section reviewers.');
        }
        $units = $db->query("SELECT un.unit_id,un.unit_name,d.department_name,r.reviewer_user_id,COALESCE(r.version,0) assignment_version,
            CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) reviewer_name,u.employee_id,
            COALESCE(u.is_active AND NOT u.is_locked AND u.unit_id=un.unit_id AND (u.role_key='admin' OR EXISTS(
                SELECT 1 FROM role_permissions v JOIN role_permissions p ON p.role_key=v.role_key
                WHERE v.role_key=u.role_key AND v.module='reviewlogs' AND v.permission='view' AND v.is_allowed
                AND p.module='reviewlogs' AND p.permission='review' AND p.is_allowed)),FALSE) reviewer_eligible
            FROM org_units un JOIN org_departments d ON d.department_id=un.department_id
            LEFT JOIN daily_log_reviewers r ON r.unit_id=un.unit_id LEFT JOIN users u ON u.user_id=r.reviewer_user_id
            ORDER BY d.department_name,un.unit_name,un.unit_id")->getResultArray();
        $candidates = $db->query("SELECT u.user_id,u.unit_id,u.employee_id,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name
            FROM users u WHERE u.is_active AND NOT u.is_locked AND u.unit_id IS NOT NULL AND (u.role_key='admin' OR EXISTS(
                SELECT 1 FROM role_permissions v JOIN role_permissions p ON p.role_key=v.role_key
                WHERE v.role_key=u.role_key AND v.module='reviewlogs' AND v.permission='view' AND v.is_allowed
                AND p.module='reviewlogs' AND p.permission='review' AND p.is_allowed)) ORDER BY u.last_name,u.first_name,u.user_id")->getResultArray();
        return ['units' => $units,'candidates' => $candidates];
    }
    public static function AssignReviewer(BaseConnection $db, int $actor, array $data): void
    {
        if (!Permissions::IsAdmin())
        {
            throw new DomainException('Only administrators can assign section reviewers.');
        }
        $unit = (int)($data['unit_id'] ?? 0);
        $reviewer = (int)($data['reviewer_user_id'] ?? 0);
        $expected = (int)($data['assignment_version'] ?? -1);
        if ($unit <= 0 || $reviewer < 0 || $expected < 0)
        {
            throw new InvalidArgumentException('Choose a unit and a valid reviewer assignment.');
        }
        $db->transBegin();
        try
        {
            
            $s = $db->query($db->table('org_units')->select('unit_id', false)->where('unit_id', $unit)->getCompiledSelect() . ' FOR UPDATE');
            if (!Database::Scalar($s))
            {
                throw new OutOfBoundsException('Unit / section not found.');
            }
            
            $s = $db->table('daily_log_reviewers')->select('version', false)->where('unit_id', $unit)->get();
            $version = (int)Database::Scalar($s);
            if ($version !== $expected)
            {
                throw new DomainException('This assignment changed. Refresh the reviewer list before saving.');
            }
            if ($reviewer)
            {
                
                $s = $db->query($db->table('users')->select('unit_id', false)->where('user_id', $reviewer)->where('is_active', true)->where(new \CodeIgniter\Database\RawSql('NOT is_locked'))->getCompiledSelect() . ' FOR SHARE');
                if ((int)Database::Scalar($s) !== $unit)
                {
                    throw new InvalidArgumentException('Choose an active reviewer who belongs to this unit / section.');
                }
                // Inspect role grants directly: a new reviewer need not already hold an assignment.
                $sSql = "SELECT 1 FROM users u WHERE user_id=? AND (role_key='admin' OR EXISTS(
                    SELECT 1 FROM role_permissions v JOIN role_permissions p ON p.role_key=v.role_key
                    WHERE v.role_key=u.role_key AND v.module='reviewlogs' AND v.permission='view' AND v.is_allowed
                    AND p.module='reviewlogs' AND p.permission='review' AND p.is_allowed))";
                $s = $db->query($sSql, [$reviewer]);
                if (!Database::Scalar($s))
                {
                    throw new InvalidArgumentException('The reviewer needs Review Daily Logs view and review permissions.');
                }
            }
            // Keep the version when clearing an assignment so older open forms stay stale.
            $sSql = 'INSERT INTO daily_log_reviewers(unit_id,reviewer_user_id,assigned_by)VALUES(?,?,?) ON CONFLICT(unit_id) DO UPDATE SET reviewer_user_id=EXCLUDED.reviewer_user_id,assigned_by=EXCLUDED.assigned_by,version=daily_log_reviewers.version+1,updated_at=NOW()';
            $s = $db->query($sSql, [$unit,$reviewer ?: null,$actor]);
            $db->transCommit();
            Permissions::Reset();
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw $e;
        }
    }
    /** Approved work only, with the same Monday-Friday period as team monitoring. */
    public static function WeeklySummary(BaseConnection $db, int $actor, array $data, bool $isTeam = false, bool $isMonitor = false): array
    {
        [$where,$params] = $isTeam ? self::Scope($db, $actor, 0, false, $isMonitor) : [' AND u.user_id=?',[$actor]];
        $id = $isTeam ? (int)($data['user_id'] ?? 0) : $actor;
        if ($id <= 0)
        {
            throw new InvalidArgumentException('Choose an employee for the weekly summary.');
        }
        $chosen = self::Date($data['week_start'] ?? (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d'));
        $date = new DateTimeImmutable($chosen, new DateTimeZone('Asia/Manila'));
        $start = $date->modify('-'.((int)$date->format('N') - 1).' days');
        $end = $start->modify('+4 days');
        // Join the scope to the aggregate too, so each read rechecks current membership.
        $sSql = "SELECT u.user_id,u.employee_id,u.first_name,u.middle_name,u.last_name,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name,un.unit_name
            FROM users u LEFT JOIN org_units un ON un.unit_id=u.unit_id WHERE u.user_id=?".$where;
        $s = $db->query($sSql, array_merge([$id], $params));
        $employee = $s->getUnbufferedRow('array');
        if (!$employee)
        {
            throw new OutOfBoundsException('Employee not found or unavailable in your scope.');
        }
        $employee['report_name'] = self::ReportName($employee['first_name'], $employee['middle_name'] ?? '', $employee['last_name']);
        $sSql = "SELECT l.activity_type,l.classification,SUM(l.duration_hours) hours FROM daily_logs l
            JOIN users u ON u.user_id=l.user_id WHERE u.user_id=? AND l.status='Reviewed' AND l.entry_kind='activity'
            AND l.activity_date BETWEEN ? AND ?".$where.' GROUP BY l.activity_type,l.classification';
        $s = $db->query($sSql, array_merge([$id,$start->format('Y-m-d'),$end->format('Y-m-d')], $params));
        $values = [];
        foreach ($s->getResultArray() as $row)
        {
            $values[$row['activity_type']][$row['classification']] = (int)round((float)$row['hours'] * 100);
        }
        
        $s = $db->table('daily_logs')->select('activity_date,leave_type,leave_period,duration_hours', false)->where('user_id', $id)->where('status', 'Reviewed')->where('entry_kind', 'leave')->where('activity_date >=', $start->format('Y-m-d'))->where('activity_date <=', $end->format('Y-m-d'))->orderBy('activity_date,log_id', '', false)->get();
        return ['employee' => $employee,'week_start' => $start->format('Y-m-d'),'week_end' => $end->format('Y-m-d'),'leaves' => $s->getResultArray()] + self::SummaryValues($values);
    }
    private static function SummaryValues(array $values): array
    {
        $rows = [];
        $totals = ['SD' => 0,'SCR' => 0,'PROJECT' => 0,'total' => 0];
        foreach (self::SUMMARY_ACTIVITIES as $type)
        {
            $row = ['activity_type' => $type,'activity_name' => self::ACTIVITIES[$type]];
            $sum = 0;
            foreach (self::CLASSIFICATIONS as $classification => $label)
            {
                $value = $values[$type][$classification] ?? 0;
                $row[$classification] = $value / 100;
                $totals[$classification] += $value;
                $sum += $value;
            }
            $row['total'] = $sum / 100;
            $totals['total'] += $sum;
            $rows[] = $row;
        }
        foreach ($totals as &$value)
        {
            $value /= 100;
        }
        unset($value);
        return ['rows' => $rows,'totals' => $totals];
    }
    /** Every section member, independent of the monitoring list's search and pagination. */
    public static function SectionSummaries(BaseConnection $db, int $actor, array $data): array
    {
        Permissions::require('reports', 'view');
        $unit = (int)($data['unit_id'] ?? 0);
        if ($unit <= 0)
        {
            throw new InvalidArgumentException('Choose one unit / section to export.');
        }
        [$where,$params] = self::Scope($db, $actor, $unit, false, true);
        
        $s = $db->table('org_units')->select('unit_id,unit_name', false)->where('unit_id', $unit)->get();
        $section = $s->getUnbufferedRow('array');
        if (!$section)
        {
            throw new OutOfBoundsException('Unit / section not found.');
        }
        $chosen = self::Date($data['week_start'] ?? (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d'));
        $date = new DateTimeImmutable($chosen, new DateTimeZone('Asia/Manila'));
        $start = $date->modify('-'.((int)$date->format('N') - 1).' days')->format('Y-m-d');
        $end = (new DateTimeImmutable($start))->modify('+4 days')->format('Y-m-d');
        $sSql = "SELECT u.user_id,u.employee_id,u.first_name,u.middle_name,u.last_name,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name
            FROM users u WHERE u.unit_id=?".$where.' ORDER BY u.last_name,u.first_name,u.user_id';
        $s = $db->query($sSql, array_merge([$unit], $params));
        $employees = $s->getResultArray();
        if (!$employees)
        {
            throw new OutOfBoundsException('There are no personnel in this section.');
        }
        $sSql = "SELECT l.user_id,l.activity_type,l.classification,SUM(l.duration_hours) hours FROM daily_logs l JOIN users u ON u.user_id=l.user_id
            WHERE u.unit_id=? AND l.status='Reviewed' AND l.entry_kind='activity' AND l.activity_date BETWEEN ? AND ?".$where.' GROUP BY l.user_id,l.activity_type,l.classification';
        $s = $db->query($sSql, array_merge([$unit,$start,$end], $params));
        $values = [];
        foreach ($s->getResultArray() as $row)
        {
            $values[$row['user_id']][$row['activity_type']][$row['classification']] = (int)round((float)$row['hours'] * 100);
        }
        $sSql = "SELECT l.user_id,l.activity_date,l.leave_type,l.leave_period,l.duration_hours FROM daily_logs l JOIN users u ON u.user_id=l.user_id WHERE u.unit_id=? AND l.status='Reviewed' AND l.entry_kind='leave' AND l.activity_date BETWEEN ? AND ?".$where.' ORDER BY l.activity_date,l.log_id';
        $s = $db->query($sSql, array_merge([$unit,$start,$end], $params));
        $leaves = [];
        foreach ($s->getResultArray() as $row)
        {
            $leaves[$row['user_id']][] = $row;
        }
        $reports = [];
        foreach ($employees as $employee)
        {
            $employee['report_name'] = self::ReportName($employee['first_name'], $employee['middle_name'] ?? '', $employee['last_name']);
            $employee['unit_name'] = $section['unit_name'];
            $reports[] = ['employee' => $employee,'leaves' => $leaves[$employee['user_id']] ?? []] + self::SummaryValues($values[$employee['user_id']] ?? []);
        }
        return ['section' => $section,'week_start' => $start,'week_end' => $end,'reports' => $reports];
    }
    public static function Monitoring(BaseConnection $db, int $actor, array $data): array
    {
        [$where,$params] = self::Scope($db, $actor, (int)($data['unit_id'] ?? 0), false, true);
        $q = self::Text($data['q'] ?? '', 200);
        if ($q !== '')
        {
            $where .= " AND CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name,u.employee_id) ILIKE ?";
            $params[] = '%'.$q.'%';
        }
        [$start,$end] = DailyLogCalendar::Week($data['week_start'] ?? (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d'));
        $days = [];
        for ($i = 0;$i < 5;$i++)
        {
            $days[] = (new DateTimeImmutable($start))->modify('+'.$i.' days')->format('Y-m-d');
        }
        $sql = " FROM users u LEFT JOIN org_units un ON un.unit_id=u.unit_id LEFT JOIN org_positions p ON p.position_id=u.position_id WHERE u.is_active".$where;
        $sSql = 'SELECT COUNT(*)'.$sql;
        $s = $db->query($sSql, $params);
        $total = (int)Database::Scalar($s);
        $pages = max(1, (int)ceil($total / 25));
        $page = min($pages, max(1, (int)($data['page'] ?? 1)));
        $sSql = "SELECT u.user_id,u.unit_id,u.employee_id,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name,un.unit_name,p.position_title".$sql.' ORDER BY u.last_name,u.first_name,u.user_id LIMIT 25 OFFSET '.(($page - 1) * 25);
        $s = $db->query($sSql, $params);
        $members = $s->getResultArray();
        $totals = [];
        $calendars = [];
        if ($members)
        {
            $ids = array_column($members, 'user_id');
            $sSql = "SELECT user_id,activity_date,entry_kind,status,SUM(duration_hours) hours FROM daily_logs WHERE status IN('Submitted','Reviewed') AND activity_date BETWEEN ? AND ? AND user_id IN(".implode(',', array_fill(0, count($ids), '?')).') GROUP BY user_id,activity_date,entry_kind,status';
            $s = $db->query($sSql, array_merge([$days[0],$days[4]], $ids));
            foreach ($s->getResultArray() as $r)
            {
                $totals[$r['user_id']][$r['activity_date']][$r['entry_kind']][$r['status']] = (float)$r['hours'];
            }
        }
        foreach ($members as &$m)
        {
            $unit = (int)($m['unit_id'] ?? 0);
            if (!isset($calendars[$unit]))
            {
                $calendars[$unit] = DailyLogCalendar::Holidays($db, $days[0], $days[4], $unit ?: null);
            }
            $m['days'] = [];
            $m['activity_hours'] = 0;
            $m['leave_hours'] = 0;
            $m['target_hours'] = 0;
            $m['entered_hours'] = 0;
            $m['entered_work_hours'] = 0;
            $m['remaining_hours'] = 0;
            $m['working_days'] = 0;
            $m['input_target_hours'] = 0;
            $isComplete = true;
            foreach ($days as $day)
            {
                $r = $totals[$m['user_id']][$day] ?? [];
                $work = $r['activity']['Reviewed'] ?? 0;
                $leave = min(8, $r['leave']['Reviewed'] ?? 0);
                $entered = $work + ($r['activity']['Submitted'] ?? 0);
                $inputLeave = min(8, $leave + ($r['leave']['Submitted'] ?? 0));
                $holiday = $calendars[$unit][$day]['holiday_name'] ?? null;
                $base = $holiday ? 0 : 8;
                $target = max(0, $base - $leave);
                $inputTarget = max(0, $base - $inputLeave);
                $remaining = $base ? max(0, $inputTarget - $entered) : 0;
                $isDone = !$base || $work + 0.00001 >= $target;
                $m['days'][] = ['date' => $day,'working' => !!$base,'holiday' => $holiday,'activity_hours' => $work,'leave_hours' => $leave,'target_hours' => $target,'entered_hours' => $base ? $entered + $inputLeave : 0,'entered_work_hours' => $entered,'remaining_hours' => $remaining,'complete' => $isDone];
                if ($base)
                {
                    $m['activity_hours'] += $work;
                    $m['leave_hours'] += $leave;
                    $m['entered_hours'] += $entered + $inputLeave;
                    $m['entered_work_hours'] += $entered;
                    $m['working_days']++;
                }
                $m['target_hours'] += $target;
                $m['input_target_hours'] += $inputTarget;
                $m['remaining_hours'] += $remaining;
                $isComplete = $isComplete && $isDone;
            }
            $m['complete'] = $isComplete;
            $m['input_complete'] = $m['remaining_hours'] < 0.00001;
        }
        unset($m);
        return ['members' => $members,'days' => $days,'week_start' => $days[0],'week_end' => $days[4],'total' => $total,'page' => $page,'pages' => $pages];
    }
}
