<?php

/**
 * Filename: LeaveRequests.php
 * Purpose: Manage department leave calendars and section-scoped leave approvals.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Group working-day ranges into one versioned application and expose department birthday events.
 */

use CodeIgniter\Database\BaseConnection;

require_once __DIR__.'/DailyLogs.php';
class LeaveRequests
{
    public static function Profile(BaseConnection $db, int $actor): array
    {
        $sSql = 'SELECT u.user_id,u.unit_id,COALESCE(un.department_id,u.department_id) department_id,un.unit_name,d.department_name
            FROM users u LEFT JOIN org_units un ON un.unit_id=u.unit_id
            LEFT JOIN org_departments d ON d.department_id=COALESCE(un.department_id,u.department_id)
            WHERE u.user_id=? AND u.is_active AND NOT u.is_locked';
        $s = $db->query($sSql, [$actor]);
        return $s->getUnbufferedRow('array') ?: throw new DomainException('Your account is unavailable.');
    }
    public static function IsSectionHead(BaseConnection $db, int $actor): bool
    {
        $sSql = 'SELECT r.role_key,r.role_name FROM users u JOIN app_roles r ON r.role_key=u.role_key WHERE u.user_id=? AND u.is_active AND NOT u.is_locked';
        $s = $db->query($sSql, [$actor]);
        foreach ($s->getUnbufferedRow('array') ?: [] as $value)
        {
            if (preg_replace('/[^a-z]/', '', strtolower($value)) === 'sectionhead')
            {
                return true;
            }
        }
        return false;
    }
    public static function CanReview(BaseConnection $db, int $actor, array $row, ?array $profile = null): bool
    {
        $profile ??= self::Profile($db, $actor);
        return !empty($profile['unit_id']) && (int)$profile['unit_id'] === (int)$row['unit_id']
            && ((int)$row['user_id'] !== $actor || self::IsSectionHead($db, $actor))
            && Permissions::UserCan($db, $actor, 'leaves', 'review');
    }
    private static function Row(BaseConnection $db, int $id): array
    {
        $sSql = "SELECT l.*,a.date_from,a.date_to,a.version request_version,u.unit_id,COALESCE(un.department_id,u.department_id) department_id,
            CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name,un.unit_name,
            CONCAT_WS(' ',r.first_name,r.last_name) reviewer_name
            FROM daily_logs l JOIN leave_applications a ON a.request_id=l.leave_request_id JOIN users u ON u.user_id=l.user_id LEFT JOIN org_units un ON un.unit_id=u.unit_id
            LEFT JOIN users r ON r.user_id=l.reviewed_by WHERE l.log_id=? AND l.entry_kind='leave'";
        $s = $db->query($sSql, [$id]);
        $row = $s->getUnbufferedRow('array') ?: throw new OutOfBoundsException('Leave request not found.');
        
        $s = $db->table('daily_logs')->select('MIN(log_id) log_id,COUNT(*) working_days,SUM(duration_hours) total_hours', false)->where('leave_request_id', $row['leave_request_id'])->get();
        $group = $s->getUnbufferedRow('array');
        $row['log_id'] = $group['log_id'];
        $row['version'] = $row['request_version'];
        return $row + ['working_days' => (int)$group['working_days'],'total_hours' => (float)$group['total_hours']];
    }
    public static function Get(BaseConnection $db, int $actor, int $id): array
    {
        Permissions::Require('leaves', 'view');
        $profile = self::Profile($db, $actor);
        $row = self::Row($db, $id);
        $isOwner = (int)$row['user_id'] === $actor;
        $isReviewer = self::CanReview($db, $actor, $row, $profile);
        $isDepartment = !empty($profile['department_id']) && (int)$profile['department_id'] === (int)$row['department_id'];
        if ((!$isOwner && !$isDepartment && !Permissions::IsAdmin()) || (!$isOwner && ($row['status'] === 'Draft' || (!$isReviewer && !in_array($row['status'], ['Submitted','Reviewed'], true)))))
        {
            throw new OutOfBoundsException('Leave request not found in your department.');
        }
        $fields = ['log_id','user_id','activity_date','date_from','date_to','duration_hours','total_hours','working_days','leave_type','leave_period','status','version','employee_name','unit_name'];
        if ($isOwner || $isReviewer)
        {
            $fields = array_merge($fields, ['remarks','review_comment','reviewer_name','submitted_at','reviewed_at']);
        }
        return array_intersect_key($row, array_flip($fields)) + [
            'can_edit' => $isOwner && in_array($row['status'], ['Draft','Submitted','Returned'], true) && Permissions::Can('leaves', 'edit'),
            'can_delete' => $isOwner && in_array($row['status'], ['Draft','Returned'], true) && Permissions::Can('leaves', 'delete'),
            'can_review' => $isReviewer && in_array($row['status'], ['Submitted','Reviewed'], true)];
    }
    public static function Calendar(BaseConnection $db, int $actor, array $data): array
    {
        Permissions::Require('leaves', 'view');
        $profile = self::Profile($db, $actor);
        $month = $data['month'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m');
        if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/D', $month))
        {
            throw new InvalidArgumentException('Choose a valid calendar month.');
        }
        $first = new DateTimeImmutable(DailyLogs::Date($month.'-01'));
        $last = $first->modify('last day of this month');
        $from = $first->modify('-'.((int)$first->format('N') - 1).' days')->format('Y-m-d');
        $to = $last->modify('+'.(7 - (int)$last->format('N')).' days')->format('Y-m-d');
        $department = (int)($data['department_id'] ?? $profile['department_id'] ?? 0);
        if (!Permissions::IsAdmin() && $department !== (int)($profile['department_id'] ?? 0))
        {
            throw new DomainException('You can view only your own department calendar.');
        }
        $where = $department > 0 ? 'COALESCE(un.department_id,u.department_id)=?' : 'l.user_id=?';
        $sSql = "SELECT l.log_id,l.user_id,l.activity_date,l.leave_type,l.leave_period,l.duration_hours,l.status,
            CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name,un.unit_name
            FROM daily_logs l JOIN users u ON u.user_id=l.user_id LEFT JOIN org_units un ON un.unit_id=u.unit_id
            WHERE l.entry_kind='leave' AND l.status IN('Submitted','Reviewed') AND l.activity_date BETWEEN ? AND ? AND ".$where.' ORDER BY l.activity_date,u.last_name,l.log_id';
        $s = $db->query($sSql, [$from,$to,$department > 0 ? $department : $actor]);
        $leaves = $s->getResultArray();
        $mine = self::Page($db, 'l.user_id=? AND a.date_from<=? AND a.date_to>=?', [$actor,$last->format('Y-m-d'),$first->format('Y-m-d')], (int)($data['mine_page'] ?? 1));
        $pending = ['rows' => [],'total' => 0,'page' => 1,'pages' => 1];
        if ($profile['unit_id'] && Permissions::Can('leaves', 'review'))
        {
            $pending = self::Page($db, "u.unit_id=? AND l.status='Submitted'".(self::IsSectionHead($db, $actor) ? '' : ' AND l.user_id<>?'),
                self::IsSectionHead($db, $actor) ? [$profile['unit_id']] : [$profile['unit_id'],$actor], (int)($data['review_page'] ?? 1));
        }
        return ['month' => $month,'from' => $from,'to' => $to,'profile' => $profile,'department_id' => $department,
            'leaves' => $leaves,'mine' => $mine,'pending' => $pending,'holidays' => DailyLogCalendar::Holidays($db, $from, $to, null),
            'birthdays' => self::Birthdays($db, $actor, $department, $from, $to),
            'departments' => Permissions::IsAdmin() ? $db->table('org_departments')->select('department_id,department_name', false)->orderBy('department_name', '', false)->get()->getResultArray() : []];
    }
    private static function Birthdays(BaseConnection $db, int $actor, int $department, string $from, string $to): array
    {
        $where = $department > 0 ? 'COALESCE(un.department_id,u.department_id)=?' : 'u.user_id=?';
        $sSql = "SELECT CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name,TO_CHAR(u.birthday,'MM-DD') birthday_day
            FROM users u LEFT JOIN org_units un ON un.unit_id=u.unit_id WHERE u.birthday IS NOT NULL AND u.is_active AND NOT u.is_locked AND ".$where.' ORDER BY u.last_name,u.first_name';
        $s = $db->query($sSql, [$department > 0 ? $department : $actor]);
        $events = [];
        foreach ($s->getResultArray() as $person)
        {
            for ($year = (int)substr($from, 0, 4);$year <= (int)substr($to, 0, 4);$year++)
            {
                $day = $person['birthday_day'];
                if ($day === '02-29' && !checkdate(2, 29, $year))
                {
                    $day = '02-28';
                }
                $date = $year.'-'.$day;
                if ($date >= $from && $date <= $to)
                {
                    $events[] = ['date' => $date,'employee_name' => $person['employee_name']];
                }
            }
        }
        return $events;
    }
    private static function Page(BaseConnection $db, string $where, array $params, int $page): array
    {
        $sql = " FROM daily_logs l JOIN leave_applications a ON a.request_id=l.leave_request_id JOIN users u ON u.user_id=l.user_id LEFT JOIN org_units un ON un.unit_id=u.unit_id WHERE l.entry_kind='leave' AND ".$where;
        $sSql = 'SELECT COUNT(DISTINCT a.request_id)'.$sql;
        $s = $db->query($sSql, $params);
        $total = (int)Database::Scalar($s);
        require_once ROOTPATH.'lib/ListPageSize.php';
        $size = \ListPageSize::Get();
        $pages = max(1, (int)ceil($total / $size));
        $page = min($pages, max(1, $page));
        $sSql = "SELECT MIN(l.log_id) log_id,u.user_id,a.date_from,a.date_to,MIN(l.activity_date) activity_date,MIN(l.leave_type) leave_type,MIN(l.leave_period) leave_period,SUM(l.duration_hours) duration_hours,MIN(l.status) status,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name".$sql.' GROUP BY a.request_id,u.user_id ORDER BY a.date_from,a.request_id LIMIT '.$size.' OFFSET '.(($page - 1) * $size);
        $s = $db->query($sSql, $params);
        return ['rows' => $s->getResultArray(),'total' => $total,'page' => $page,'pages' => $pages];
    }
    public static function Save(BaseConnection $db, int $actor, array $data): int
    {
        $id = (int)($data['log_id'] ?? 0);
        Permissions::Require('leaves', $id ? 'edit' : 'create');
        if (isset($data['entry_kind']) && $data['entry_kind'] !== 'leave')
        {
            throw new InvalidArgumentException('Use My Daily Logs for work activities.');
        }
        $from = DailyLogs::Date($data['date_from'] ?? $data['activity_date'] ?? null);
        $to = DailyLogs::Date($data['date_to'] ?? $from);
        $dates = self::WorkingDates($db, $from, $to);
        if (!$dates && ($data['status'] ?? 'Draft') === 'Submitted')
        {
            throw new InvalidArgumentException('The date range has no working days. Weekends and organization holidays are excluded.');
        }
        $dates = $dates ?: [$from];
        $data['activity_date'] = $dates[0];
        $data['entry_kind'] = 'leave';
        $row = DailyLogs::Normalize($data);
        $db->transBegin();
        try
        {
            $db->query($db->table('users')->select('user_id', false)->where('user_id', $actor)->getCompiledSelect() . ' FOR UPDATE');
            $profile = self::Profile($db, $actor);
            $existing = [];
            if ($id)
            {
                $old = self::Row($db, $id);
                if ((int)$old['user_id'] !== $actor)
                {
                    throw new OutOfBoundsException('Leave request not found.');
                }
                if (!in_array($old['status'], ['Draft','Submitted','Returned'], true) || (int)$old['version'] !== (int)($data['version'] ?? 0))
                {
                    throw new DomainException('This request changed or is already approved. Reopen it before editing.');
                }
                if ($old['status'] === 'Submitted' && $row['status'] !== 'Submitted')
                {
                    throw new DomainException('Submitted leave must remain submitted when edited.');
                }
                
                $s = $db->table('daily_logs')->select('activity_date,log_id', false)->where('leave_request_id', $old['leave_request_id'])->get();
                $existing = array_column($s->getResultArray(), 'log_id', 'activity_date');
                $id = (int)$old['log_id'];
            }
            if ($row['status'] === 'Submitted')
            {
                if (!$profile['unit_id'])
                {
                    throw new DomainException('Assign your account to a section before submitting leave.');
                }
                foreach ($dates as $date)
                {
                    DailyLogs::ValidateDay($db, $actor, array_replace($row, ['activity_date' => $date]), (int)($existing[$date] ?? 0));
                }
            }
            if ($id)
            {
                $request = (int)$old['leave_request_id'];
                
                $s = $db->table('leave_applications')->set('date_from', $from)->set('date_to', $to)->set('version', new \CodeIgniter\Database\RawSql('version+1'))->set('updated_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('request_id', $request)->update();
                $db->table('daily_logs')->where('leave_request_id', $request)->where('log_id <>', $id)->delete();
            }
            else
            {
                
                $s = $db->query($db->table('leave_applications')->set('user_id', $actor)->set('date_from', $from)->set('date_to', $to)->getCompiledInsert() . ' RETURNING request_id');
                $request = (int)Database::Scalar($s);
            }
            $row['leave_request_id'] = $request;
            $fields = ['activity_date','entry_kind','leave_type','leave_period','duration_hours','remarks','status','leave_request_id'];
            $values = array_map(static fn ($field) => $row[$field], $fields);
            if ($id)
            {
                $sSql = 'UPDATE daily_logs SET '.implode(',', array_map(static fn ($field) => $field.'=?', $fields)).",submitted_at=CASE WHEN ?='Submitted' THEN NOW() ELSE NULL END,reviewed_by=NULL,reviewed_at=NULL,version=version+1,updated_at=NOW() WHERE log_id=? AND user_id=? AND entry_kind='leave'";
                $s = $db->query($sSql, array_merge($values, [$row['status'],$id,$actor]));
            }
            foreach ($dates as $index => $date)
            {
                if ($index === 0 && $id)
                {
                    continue;
                }
                $row['activity_date'] = $date;
                $values = array_map(static fn ($field) => $row[$field], $fields);
                $sSql = 'INSERT INTO daily_logs('.implode(',', $fields).",user_id,submitted_at) VALUES(".implode(',', array_fill(0, count($fields), '?')).",?,CASE WHEN ?='Submitted' THEN NOW() ELSE NULL END) RETURNING log_id";
                $s = $db->query($sSql, array_merge($values, [$actor,$row['status']]));
                $created = (int)Database::Scalar($s);
                $id = $id ?: $created;
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
    private static function WorkingDates(BaseConnection $db, string $from, string $to): array
    {
        $first = new DateTimeImmutable($from);
        $last = new DateTimeImmutable($to);
        if ($from > $to || $first->diff($last)->days >= 366)
        {
            throw new InvalidArgumentException('Date To must be on or after Date From. Apply for up to 366 calendar days at once.');
        }
        $holidays = DailyLogCalendar::Holidays($db, $from, $to, null);
        $dates = [];
        for ($day = $first;$day <= $last;$day = $day->modify('+1 day'))
        {
            $date = $day->format('Y-m-d');
            if ((int)$day->format('N') <= 5 && !isset($holidays[$date]))
            {
                $dates[] = $date;
            }
        }
        return $dates;
    }
    public static function Review(BaseConnection $db, int $actor, array $data): void
    {
        Permissions::Require('leaves', 'review');
        $decision = $data['decision'] ?? '';
        $comment = DailyLogs::Text($data['review_comment'] ?? '');
        if (!in_array($decision, ['Reviewed','Returned'], true) || ($decision === 'Returned' && $comment === ''))
        {
            throw new InvalidArgumentException('Choose Approve or Return. A return requires a comment.');
        }
        $id = (int)($data['log_id'] ?? 0);
        $owner = (int)self::Row($db, $id)['user_id'];
        $db->transBegin();
        try
        {
            $ids = array_values(array_unique([$actor,$owner]));
            sort($ids, SORT_NUMERIC);
            $sSql = 'SELECT user_id FROM users WHERE user_id IN('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY user_id FOR UPDATE';
            $s = $db->query($sSql, $ids);
            $s->getResultArray();
            $row = self::Row($db, $id);
            $sSql = 'SELECT r.role_key FROM app_roles r JOIN users u ON u.role_key=r.role_key WHERE u.user_id=? FOR SHARE OF r';
            $s = $db->query($sSql, [$actor]);
            $s->getResultArray();
            if (!self::CanReview($db, $actor, $row))
            {
                throw new DomainException('You may approve leave only within your section. Only a Section Head may approve their own leave.');
            }
            if ((int)$row['version'] !== (int)($data['version'] ?? 0) || !in_array($row['status'], $decision === 'Reviewed' ? ['Submitted'] : ['Submitted','Reviewed'], true))
            {
                throw new DomainException('This leave request changed. Refresh and review the latest request.');
            }
            if ($decision === 'Reviewed')
            {
                
                $s = $db->table('daily_logs')->where('leave_request_id', $row['leave_request_id'])->get();
                $entries = $s->getResultArray();
                $working = self::WorkingDates($db, $row['date_from'], $row['date_to']);
                foreach ($entries as $entry)
                {
                    if (!in_array($entry['activity_date'], $working, true))
                    {
                        throw new DomainException('A date is no longer a working day. Return the request for correction.');
                    }
                    DailyLogs::ValidateDay($db, $owner, $entry, (int)$entry['log_id']);
                }
            }
            
            $s = $db->table('daily_logs')->set('status', $decision)->set('review_comment', $comment)->set('reviewed_by', $actor)->set('reviewed_at', new \CodeIgniter\Database\RawSql('NOW()'))->set('version', new \CodeIgniter\Database\RawSql('version+1'))->set('updated_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('leave_request_id', $row['leave_request_id'])->where('entry_kind', 'leave')->update();
            $db->table('leave_applications')->set('version', new \CodeIgniter\Database\RawSql('version+1'))->set('updated_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('request_id', $row['leave_request_id'])->update();
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
    public static function Delete(BaseConnection $db, int $actor, array $data): void
    {
        Permissions::Require('leaves', 'delete');
        $db->transBegin();
        try
        {
            $db->query($db->table('users')->select('user_id', false)->where('user_id', $actor)->getCompiledSelect() . ' FOR UPDATE');
            $row = self::Row($db, (int)($data['log_id'] ?? 0));
            if ((int)$row['user_id'] !== $actor || (int)$row['version'] !== (int)($data['version'] ?? 0) || !in_array($row['status'], ['Draft','Returned'], true))
            {
                throw new DomainException('Only your unchanged draft or returned leave requests can be deleted.');
            }
            $db->table('daily_logs')->where('leave_request_id', $row['leave_request_id'])->delete();
            $db->table('leave_applications')->where('request_id', $row['leave_request_id'])->delete();
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
}
