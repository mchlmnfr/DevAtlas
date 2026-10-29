<?php

/**
 * Filename: LogReminders.php
 * Purpose: Provide log reminders processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;

require_once __DIR__.'/ActionCenter.php';
/** Daily digests, never individual-entry approvals. No SMTP work happens here. */
class LogReminders
{
    public static function IsKind(string $kind): bool
    {
        return in_array($kind, ['daily_log_attention','weekly_approval'], true);
    }
    private static function Working(BaseConnection $db, DateTimeImmutable $now): bool
    {
        return (int)$now->format('N') <= 5 && !DailyLogCalendar::Holidays($db, $now->format('Y-m-d'), $now->format('Y-m-d'), null);
    }

    public static function Attention(BaseConnection $db, int $actor, string $from, string $to): array
    {
        $p = ActionCenter::Person($db, $actor);
        $missing = 0;
        $days = 0;
        $returned = [];
        if (!$p || !Permissions::UserCan($db, $actor, 'dailylogs', 'view'))
        {
            return ['hours' => 0,'days' => 0,'returned' => []];
        }
        if ($p['unit_id'] && Permissions::UserCan($db, $actor, 'dailylogs', 'create') && $from <= $to)
        {
            $balance = ActionCenter::Balances($db, [$p], $from, $to, $to, true)[$actor];
            $missing = $balance['due_hours'];
            $days = $balance['missing_days'];
        }
        if (Permissions::UserCan($db, $actor, 'dailylogs', 'edit'))
        {
            $returned = ActionCenter::ReturnedWeeks($db, $actor, $from, $to);
        }
        return ['hours' => $missing,'days' => $days,'returned' => $returned];
    }

    public static function Approvals(BaseConnection $db, int $actor, DateTimeImmutable $now, int $delay): array
    {
        $rows = ActionCenter::PendingWeeks($db, $actor, $now, $delay);
        $p = ActionCenter::Person($db, $actor);
        if (($p['role_key'] ?? '') !== 'admin')
        {
            return $rows;
        }
        // Administrators cover weeks with no eligible reviewer, including a non-head reviewer's own week.
        
        $out = [];
        foreach ($rows as $row)
        {
            $assignment = $db->table('daily_log_reviewers')->select('reviewer_user_id', false)->where('unit_id', $row['unit_id'])->get();
            $assigned = (int)Database::Scalar($assignment);
            $owner = (int)$row['user_id'];
            $isHeadCanSelf = Permissions::HasSelfReviewRole($db, $owner) && Permissions::UserCan($db, $owner, 'reviewlogs', 'review');
            $assignedProfile = $assigned ? ActionCenter::Person($db, $assigned) : [];
            $isAssignedCan = $assigned && (int)($assignedProfile['unit_id'] ?? 0) === (int)($row['unit_id'] ?? 0) && Permissions::UserCan($db, $assigned, 'reviewlogs', 'review') && ($assigned !== $owner || $isHeadCanSelf);
            if (!$isAssignedCan && !$isHeadCanSelf)
            {
                $out[] = $row;
            }
        }
        return $out;
    }

    public static function Relevant(BaseConnection $db, array $row, array $settings, ?DateTimeImmutable $now = null): bool
    {
        $now = ActionCenter::Now($now);
        $context = is_array($row['context']) ? $row['context'] : json_decode($row['context'] ?: '{}', true);
        if (($context['day'] ?? '') !== $now->format('Y-m-d'))
        {
            return false;
        }
        $user = (int)$row['user_id'];
        if ($row['kind'] === 'weekly_approval')
        {
            return $settings['approval_enabled'] && !!self::Approvals($db, $user, $now, (int)$settings['approval_delay_days']);
        }
        if (!$settings['daily_log_enabled'] || !isset($context['from'],$context['to']))
        {
            return false;
        }
        $attention = self::Attention($db, $user, $context['from'], $context['to']);
        return $attention['hours'] > 0 || !!$attention['returned'];
    }

    public static function Generate(BaseConnection $db, array $settings, ?DateTimeImmutable $now = null): void
    {
        $now = ActionCenter::Now($now);
        $today = $now->format('Y-m-d');
        $existing = $db->table('notifications')->select('notification_id,user_id,kind,context', false)->where(new \CodeIgniter\Database\RawSql('NOT is_read'))->whereIn('kind', ['daily_log_attention', 'weekly_approval'])->get()->getResultArray();
        
        foreach ($existing as $row)
        {
            if (!self::Relevant($db, $row, $settings, $now))
            {
                $read = $db->table('notifications')->set('is_read', true)->set('read_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('notification_id', $row['notification_id'])->update();
            }
        }
        if ((int)$now->format('G') < (int)$settings['daily_log_hour'] || !self::Working($db, $now))
        {
            return;
        }
        $from = $now->modify('-'.((int)$settings['daily_log_lookback_days'] - 1).' days')->format('Y-m-d');
        $to = $today;
        $people = Database::Column($db->table('users')->select('user_id', false)->where('is_active', true)->where(new \CodeIgniter\Database\RawSql('NOT is_locked'))->get());
        foreach ($people as $id)
        {
            $id = (int)$id;
            $context = ['day' => $today,'from' => $from,'to' => $to];
            if ($settings['daily_log_enabled'])
            {
                $a = self::Attention($db, $id, $from, $to);
                if ($a['hours'] > 0 || $a['returned'])
                {
                    $parts = [];
                    if ($a['hours'] > 0)
                    {
                        $parts[] = number_format($a['hours'], 2).' hours still needed across '.$a['days'].' working day(s).';
                    }
                    if ($a['returned'])
                    {
                        $parts[] = count($a['returned']).' employee week(s) returned for correction.';
                    }
                    Notifications::Add(
                        $db,
                        $id,
                        'daily_log_attention',
                        'Your daily logs need attention',
                        implode(' ', $parts).' As of '.$today.'. Holidays and weekends are excluded.',
                        APP_BASE_URL.'/modules/dailylogs/index.php?'.http_build_query(['date_from' => $from,'date_to' => $to,'status' => $a['hours'] > 0 ? '' : 'Returned']),
                        'daily-log:'.$today,
                        $context
                    );
                }
            }
            if ($settings['approval_enabled'])
            {
                $weeks = self::Approvals($db, $id, $now, (int)$settings['approval_delay_days']);
                if ($weeks)
                {
                    Notifications::Add(
                        $db,
                        $id,
                        'weekly_approval',
                        'Weekly approvals are waiting',
                        count($weeks).' employee week(s) are awaiting your approval as of '.$today.'. Review each whole week before approving or returning it.',
                        APP_BASE_URL.'/modules/reviewlogs/index.php?status=Submitted',
                        'weekly-approval:'.$today,
                        ['day' => $today]
                    );
                }
            }
        }
    }
}
