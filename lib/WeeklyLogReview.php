<?php

/**
 * Filename: WeeklyLogReview.php
 * Purpose: Provide weekly log review processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Keep weekly decisions and review snapshots limited to work activities.
 */

use CodeIgniter\Database\BaseConnection;

class WeeklyLogReview
{
    public static function Token(array $logs): string
    {
        return hash('sha256', json_encode(array_map(static fn ($r) => [(int)$r['log_id'],(int)$r['version'],$r['status']], $logs)));
    }
    public static function Get(BaseConnection $db, int $actor, int $user, string $chosen): array
    {
        [$start,$end] = DailyLogCalendar::Week($chosen);
        [$where,$params] = DailyLogs::Scope($db, $actor);
        $sSql = "SELECT u.user_id,u.employee_id,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name,un.unit_name FROM users u LEFT JOIN org_units un ON un.unit_id=u.unit_id WHERE u.user_id=?".$where;
        $s = $db->query($sSql, array_merge([$user], $params));
        $employee = $s->getUnbufferedRow('array');
        if (!$employee)
        {
            throw new OutOfBoundsException('Employee not found in your section.');
        }
        
        $s = $db->table('daily_logs')->where('user_id', $user)->where('activity_date >=', $start)->where('activity_date <=', $end)->where('entry_kind', 'activity')->where('status <>', 'Draft')->orderBy('log_id', '', false)->get();
        $logs = $s->getResultArray();
        if (!$logs)
        {
            throw new OutOfBoundsException('No submitted entries for this employee and week.');
        }
        $status = self::Status($logs);
        $sSql = 'SELECT w.*,CONCAT_WS(\' \',u.first_name,u.last_name) reviewer_name FROM daily_log_week_reviews w LEFT JOIN users u ON u.user_id=w.reviewed_by WHERE w.user_id=? AND w.week_start=?';
        $s = $db->query($sSql, [$user,$start]);
        $decision = $s->getUnbufferedRow('array') ?: null;
        return ['employee' => $employee,'user_id' => $user,'week_start' => $start,'week_end' => $end,'status' => $status,'token' => self::Token($logs),'logs' => $logs,'decision' => $decision,'hours' => round(array_sum(array_column($logs, 'duration_hours')), 2),
            'can_review' => Permissions::Can('reviewlogs', 'review') && ($actor !== $user || Permissions::HasSelfReviewRole($db, $actor))];
    }
    public static function Status(array $logs): string
    {
        $statuses = array_column($logs, 'status');
        return in_array('Returned', $statuses, true) ? 'Returned' : (in_array('Submitted', $statuses, true) ? 'Submitted' : 'Reviewed');
    }
    public static function Listing(BaseConnection $db, int $actor, array $data): array
    {
        [$where,$params] = DailyLogs::Scope($db, $actor, (int)($data['unit_id'] ?? 0));
        if (!Permissions::HasSelfReviewRole($db, $actor))
        {
            $where .= ' AND u.user_id<>?';
            $params[] = $actor;
        }
        $q = DailyLogs::Text($data['q'] ?? '', 200);
        if ($q !== '')
        {
            $where .= " AND CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name,u.employee_id) ILIKE ?";
            $params[] = '%'.$q.'%';
        }
        foreach (['date_from' => '>=','date_to' => '<='] as $field => $op)
        {
            if (($data[$field] ?? '') !== '')
            {
                $bound = DailyLogCalendar::Week(DailyLogs::Date($data[$field]));
                $where .= ' AND l.activity_date'.$op.'?';
                $params[] = $bound[$field === 'date_from' ? 0 : 1];
            }
        }
        if (!empty($data['date_from']) && !empty($data['date_to']) && $data['date_from'] > $data['date_to'])
        {
            throw new InvalidArgumentException('Date from must be on or before date to.');
        }
        $status = DailyLogs::Text($data['status'] ?? 'Submitted', 10);
        if (!in_array($status, ['','Submitted','Reviewed','Returned'], true))
        {
            throw new InvalidArgumentException('Choose a valid weekly status.');
        }
        $sql = "SELECT u.user_id,u.employee_id,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name,un.unit_name,
            date_trunc('week',l.activity_date)::date week_start,COUNT(*) log_count,SUM(l.duration_hours) hours,
            CASE WHEN BOOL_OR(l.status='Returned') THEN 'Returned' WHEN BOOL_OR(l.status='Submitted') THEN 'Submitted' ELSE 'Reviewed' END status
            FROM daily_logs l JOIN users u ON u.user_id=l.user_id LEFT JOIN org_units un ON un.unit_id=u.unit_id WHERE l.entry_kind='activity' AND l.status<>'Draft'".$where.' GROUP BY u.user_id,un.unit_name,date_trunc(\'week\',l.activity_date)::date';
        if ($status !== '')
        {
            $sql = 'SELECT * FROM ('.$sql.') weeks WHERE status=?';
            $params[] = $status;
        }
        $sSql = 'SELECT COUNT(*) FROM ('.$sql.') filtered';
        $s = $db->query($sSql, $params);
        $total = (int)Database::Scalar($s);
        require_once ROOTPATH.'lib/ListPageSize.php';
        $size = \ListPageSize::Get();
        $pages = max(1, (int)ceil($total / $size));
        $page = min($pages, max(1, (int)($data['page'] ?? 1)));
        $sSql = $sql.' ORDER BY week_start DESC,user_id LIMIT '.$size.' OFFSET '.(($page - 1) * $size);
        $s = $db->query($sSql, $params);
        $weeks = $s->getResultArray();
        foreach ($weeks as &$week)
        {
            $full = self::Get($db, $actor, (int)$week['user_id'], $week['week_start']);
            $week['token'] = $full['token'];
            $week['week_end'] = $full['week_end'];
        }
        unset($week);
        return ['weeks' => $weeks,'total' => $total,'page' => $page,'pages' => $pages];
    }
    public static function Review(BaseConnection $db, int $actor, array $data): int
    {
        Permissions::require('reviewlogs', 'review');
        $items = $data['items'] ?? [];
        $decision = $data['decision'] ?? 'Reviewed';
        $comment = DailyLogs::Text($data['review_comment'] ?? '');
        if (!is_array($items) || !array_is_list($items) || !$items || count($items) > 100 || !in_array($decision, ['Reviewed','Returned'], true))
        {
            throw new InvalidArgumentException('Select between 1 and 100 employee weeks.');
        }
        if ($decision === 'Returned' && $comment === '')
        {
            throw new InvalidArgumentException('Explain what needs correcting when returning a week.');
        }
        $keys = [];
        $ids = [$actor];
        foreach ($items as &$item)
        {
            if (!is_array($item))
            {
                throw new InvalidArgumentException('Select a valid employee week.');
            }
            $user = (int)($item['user_id'] ?? 0);
            if ($user <= 0 || !is_string($item['token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $item['token']))
            {
                throw new InvalidArgumentException('Select an employee week, not individual logs.');
            }
            $item['week_start'] = DailyLogCalendar::Week(DailyLogs::Date($item['week_start'] ?? null))[0];
            $key = $user.':'.$item['week_start'];
            if (isset($keys[$key]))
            {
                throw new InvalidArgumentException('Select each employee week once.');
            }
            $keys[$key] = true;
            $ids[] = $user;
        }
        unset($item);
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $db->transBegin();
        try
        {
            $sSql = 'SELECT user_id FROM users WHERE user_id IN('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY user_id FOR UPDATE';
            $s = $db->query($sSql, $ids);
            $s->getResultArray();
            DailyLogs::Scope($db, $actor, 0, true);
            foreach ($items as $item)
            {
                $user = (int)$item['user_id'];
                if ($user === $actor && !Permissions::HasSelfReviewRole($db, $actor, true))
                {
                    throw new DomainException('Only Section Head and Department Head roles can approve or return their own week.');
                }
                $week = self::Get($db, $actor, $user, $item['week_start']);
                if (!hash_equals($week['token'], $item['token']))
                {
                    throw new DomainException('A selected week changed. Refresh and review its latest entries.');
                }
                if ($decision === 'Reviewed' && $week['status'] !== 'Submitted')
                {
                    throw new DomainException('Only fully resubmitted weeks can be approved.');
                }
                if ($decision === 'Returned' && $week['status'] === 'Returned')
                {
                    throw new DomainException('This week was already returned.');
                }
                
                // A pending week may contain older approvals. Review only its new submissions.
                // Returning a fully approved week is still an explicit correction workflow.
                $sourceStatus = $decision === 'Returned' && $week['status'] === 'Reviewed' ? 'Reviewed' : 'Submitted';
                $s = $db->table('daily_logs')->set('status', $decision)->set('review_comment', $comment)->set('reviewed_by', $actor)->set('reviewed_at', new \CodeIgniter\Database\RawSql('NOW()'))->set('version', new \CodeIgniter\Database\RawSql('version+1'))->set('updated_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('user_id', $user)->where('activity_date >=', $week['week_start'])->where('activity_date <=', $week['week_end'])->where('entry_kind', 'activity')->where('status', $sourceStatus)->update();
                $sSql = 'INSERT INTO daily_log_week_reviews(user_id,week_start,status,review_comment,reviewed_by,reviewed_at) VALUES(?,?,?,?,?,NOW()) ON CONFLICT(user_id,week_start) DO UPDATE SET status=EXCLUDED.status,review_comment=EXCLUDED.review_comment,reviewed_by=EXCLUDED.reviewed_by,reviewed_at=EXCLUDED.reviewed_at,version=daily_log_week_reviews.version+1,updated_at=NOW()';
                $s = $db->query($sSql, [$user,$week['week_start'],$decision,$comment,$actor]);
            }
            $db->transCommit();
            return count($items);
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
