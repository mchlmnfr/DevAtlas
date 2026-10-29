<?php

/**
 * Filename: ActionCenter.php
 * Purpose: Provide action center processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Keep leave hours in balances while work approval reminders exclude leave requests.
 */

use CodeIgniter\Database\BaseConnection;

/** Shared, read-only action counts. Explicit actor IDs also work in the CLI worker. */
require_once __DIR__.'/DailyLogs.php';
class ActionCenter
{
    public static function Now(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Asia/Manila'));
    }

    public static function Person(BaseConnection $db, int $actor): array
    {
        
        $s = $db->table('users')->select('user_id,unit_id,role_key,created_at', false)->where('user_id', $actor)->where('is_active', true)->where(new \CodeIgniter\Database\RawSql('NOT is_locked'))->get();
        return $s->getUnbufferedRow('array') ?: [];
    }
    public static function DueThrough(DateTimeImmutable $now, int $hour = 17): string
    {
        return ((int)$now->format('G') >= $hour ? $now : $now->modify('-1 day'))->format('Y-m-d');
    }

    /** One aggregate query, then daily caps: overtime never covers a different date. */
    public static function Balances(BaseConnection $db, array $people, string $from, string $to, string $dueTo, bool $isSinceJoined = false): array
    {
        if (!$people)
        {
            return [];
        }
        $ids = array_map('intval', array_column($people, 'user_id'));
        $sSql = "SELECT user_id,activity_date,entry_kind,SUM(duration_hours) hours FROM daily_logs WHERE status IN('Submitted','Reviewed') AND activity_date BETWEEN ? AND ? AND user_id IN(".implode(',', array_fill(0, count($ids), '?')).') GROUP BY user_id,activity_date,entry_kind';
        $s = $db->query($sSql, array_merge([$from,$to], $ids));
        $values = [];
        foreach ($s->getResultArray() as $r)
        {
            $values[$r['user_id']][$r['activity_date']][$r['entry_kind']] = (float)$r['hours'];
        }
        $holidays = DailyLogCalendar::Holidays($db, $from, $to, null);
        $out = [];
        foreach ($people as $person)
        {
            $id = (int)$person['user_id'];
            $b = ['entered_hours' => 0,'target_hours' => 0,'remaining_hours' => 0,'due_hours' => 0,'missing_days' => 0,'working_days' => 0];
            for ($d = new DateTimeImmutable($from);$d->format('Y-m-d') <= $to;$d = $d->modify('+1 day'))
            {
                $date = $d->format('Y-m-d');
                if ((int)$d->format('N') > 5 || isset($holidays[$date]) || ($isSinceJoined && $date < substr($person['created_at'], 0, 10)))
                {
                    continue;
                }
                $day = $values[$id][$date] ?? [];
                $entered = ($day['activity'] ?? 0) + min(8, $day['leave'] ?? 0);
                $remaining = max(0, 8 - $entered);
                $b['working_days']++;
                $b['target_hours'] += 8;
                $b['entered_hours'] += $entered;
                $b['remaining_hours'] += $remaining;
                if ($date <= $dueTo)
                {
                    $b['due_hours'] += $remaining;
                    if ($remaining > 0.00001)
                    {
                        $b['missing_days']++;
                    }
                }
            }
            foreach (['entered_hours','remaining_hours','due_hours'] as $key)
            {
                $b[$key] = round($b[$key], 2);
            }
            $out[$id] = $b;
        }
        return $out;
    }

    /** Scope matches weekly review: assignment for others, named-head exception for self. */
    public static function PendingWeeks(BaseConnection $db, int $actor, ?DateTimeImmutable $now = null, int $delayDays = 0): array
    {
        if (!Permissions::UserCan($db, $actor, 'reviewlogs', 'review'))
        {
            return [];
        }
        $p = self::Person($db, $actor);
        if (!$p)
        {
            return [];
        }
        $now = self::Now($now);
        $where = '';
        $params = [];
        $head = Permissions::HasSelfReviewRole($db, $actor);
        if ($p['role_key'] !== 'admin')
        {
            if (Permissions::HasDailyLogAssignment($db, $actor))
            {
                $where .= ' AND u.unit_id=?';
                $params[] = $p['unit_id'];
            }
            elseif ($head)
            {
                $where .= ' AND u.user_id=?';
                $params[] = $actor;
            }
            else
            {
                return [];
            }
        }
        if (!$head)
        {
            $where .= ' AND u.user_id<>?';
            $params[] = $actor;
        }
        $params[] = $now->format('Y-m-d');
        $params[] = $now->modify('-'.$delayDays.' days')->format('Y-m-d H:i:sP');
        $sSql = "SELECT u.user_id,u.unit_id,u.employee_id,CONCAT_WS(' ',u.first_name,u.middle_name,u.last_name) employee_name,
            un.unit_name,date_trunc('week',l.activity_date)::date week_start,COUNT(*) entries,SUM(l.duration_hours) hours,
            MAX(CASE WHEN l.status='Submitted' THEN l.updated_at END) submitted_at
            FROM daily_logs l JOIN users u ON u.user_id=l.user_id LEFT JOIN org_units un ON un.unit_id=u.unit_id
            WHERE u.is_active AND NOT u.is_locked AND l.entry_kind='activity' AND l.status<>'Draft'".$where."
            AND date_trunc('week',l.activity_date)::date<=CAST(? AS DATE)
            GROUP BY u.user_id,un.unit_name,date_trunc('week',l.activity_date)::date
            HAVING BOOL_OR(l.status='Submitted') AND NOT BOOL_OR(l.status='Returned')
            AND MAX(CASE WHEN l.status='Submitted' THEN l.updated_at END)<=CAST(? AS TIMESTAMPTZ)
            ORDER BY week_start,u.last_name,u.user_id";
        // last_name is functionally dependent on the grouped user primary key.
        $s = $db->query($sSql, $params);
        return $s->getResultArray();
    }

    public static function ReturnedWeeks(BaseConnection $db, int $actor, ?string $from = null, ?string $to = null): array
    {
        $where = '';
        $params = [$actor];
        if ($from)
        {
            $where .= ' AND activity_date>=?';
            $params[] = $from;
        }
        if ($to)
        {
            $where .= ' AND activity_date<=?';
            $params[] = $to;
        }
        $sSql = "SELECT date_trunc('week',activity_date)::date week_start,COUNT(*) entries FROM daily_logs WHERE user_id=? AND entry_kind='activity' AND status='Returned'".$where." GROUP BY date_trunc('week',activity_date)::date ORDER BY week_start";
        $s = $db->query($sSql, $params);
        return $s->getResultArray();
    }

    public static function Overview(BaseConnection $db, int $actor, ?DateTimeImmutable $now = null): array
    {
        $now = self::Now($now);
        $today = $now->format('Y-m-d');
        [$from,$weekEnd] = DailyLogCalendar::Week($today);
        $to = (new DateTimeImmutable($from))->modify('+4 days')->format('Y-m-d');
        $p = self::Person($db, $actor);
        if (!$p)
        {
            throw new DomainException('Your account is unavailable.');
        }
        $hour = (int)Database::Scalar($db->table('notification_settings')->select('daily_log_hour', false)->where('id', 1)->get());
        $due = self::DueThrough($now, $hour);
        $out = ['today' => $today,'week_start' => $from,'week_end' => $to,'cutoff_hour' => $hour,'personal' => null,'team' => null,'approvals' => null,'renewals' => null];
        if (Permissions::UserCan($db, $actor, 'dailylogs', 'view'))
        {
            $out['personal'] = self::Balances($db, [$p], $from, $to, $due)[$actor];
            $out['personal']['returned'] = self::ReturnedWeeks($db, $actor);
            
            $s = $db->table('daily_logs')->select('COUNT(*)', false)->where('user_id', $actor)->where('entry_kind', 'activity')->where('status', 'Draft')->get();
            $out['personal']['drafts'] = (int)Database::Scalar($s);
        }
        if (Permissions::UserCan($db, $actor, 'teammonitoring', 'view'))
        {
            if ($p['role_key'] === 'admin' || $p['unit_id'])
            {
                $sSql = 'SELECT u.user_id,u.created_at FROM users u WHERE u.is_active'.($p['role_key'] === 'admin' ? '' : ' AND u.unit_id=?');
                $s = $db->query($sSql, $p['role_key'] === 'admin' ? [] : [$p['unit_id']]);
                $balances = self::Balances($db, $s->getResultArray(), $from, $to, $due);
                $out['team'] = ['people' => count($balances),'complete' => count(array_filter($balances, static fn ($b) => $b['remaining_hours'] < 0.00001)),
                    'due_people' => count(array_filter($balances, static fn ($b) => $b['due_hours'] > 0.00001)),'due_hours' => round(array_sum(array_column($balances, 'due_hours')), 2)];
            }
            else
            {
                $out['team'] = ['unassigned' => true];
            }
        }
        if (Permissions::UserCan($db, $actor, 'reviewlogs', 'review'))
        {
            $rows = self::PendingWeeks($db, $actor, $now);
            $out['approvals'] = ['count' => count($rows),'weeks' => array_slice($rows, 0, 6)];
        }
        if (Permissions::UserCan($db, $actor, 'servers', 'view'))
        {
            $sSql = "SELECT expiry_id,item_name,expiry_date FROM expiry_items WHERE expiry_date<=CAST(? AS DATE)+alert_days_before ORDER BY expiry_date,expiry_id";
            $s = $db->query($sSql, [$today]);
            $rows = $s->getResultArray();
            $out['renewals'] = ['count' => count($rows),'overdue' => count(array_filter($rows, static fn ($r) => $r['expiry_date'] < $today)),'items' => array_slice($rows, 0, 4)];
        }
        return $out;
    }
}
