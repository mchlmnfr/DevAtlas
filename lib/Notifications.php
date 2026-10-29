<?php

/**
 * Filename: Notifications.php
 * Purpose: Provide notifications processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;

/** Personal inbox and deduplicated reminder/outbound delivery queue. */
require_once __DIR__.'/Permissions.php';
require_once __DIR__.'/Mailer.php';
require_once __DIR__.'/LogReminders.php';
class Notifications
{
    public static function Add(BaseConnection $db, int $user, string $kind, string $title, string $message, string $path, string $key, array $context = []): void
    {
        if (!str_starts_with($path, APP_BASE_URL.'/') || str_contains($path, "\n"))
        {
            throw new InvalidArgumentException('Invalid notification destination.');
        }
        $stmtSql = 'INSERT INTO notifications(user_id,kind,title,message,target_path,dedup_key,context)SELECT user_id,?,?,?,?,?,CAST(? AS JSONB) FROM users WHERE user_id=? AND is_active AND NOT is_locked ON CONFLICT(user_id,dedup_key) DO NOTHING RETURNING notification_id';
        $stmt = $db->query($stmtSql, [$kind,mb_substr($title, 0, 200),mb_substr($message, 0, 1000),$path,mb_substr($key, 0, 180),json_encode($context, JSON_THROW_ON_ERROR),$user]);
        $id = Database::Scalar($stmt);
        if ($id)
        {
            $db->query('INSERT INTO notification_deliveries(notification_id)SELECT ? WHERE (SELECT email_enabled AND (? OR daily_log_email_enabled) FROM notification_settings WHERE id=1) AND COALESCE((SELECT email_enabled FROM notification_preferences WHERE user_id=?),TRUE)', [$id,LogReminders::IsKind($kind) ? 'false' : 'true',$user]);
        }
    }
    public static function Generate(BaseConnection $db, bool $isForce = false, ?DateTimeImmutable $clock = null): array
    {
        $isOwn = !Database::InTransaction($db);
        if ($isOwn)
        {
            $db->transBegin();
        }
        $generated = 0;
        try
        {
            $settings = $db->query($db->table('notification_settings')->select('*,array_to_json(reminder_days)::TEXT days', false)->where('id', 1)->getCompiledSelect() . ' FOR UPDATE')->getUnbufferedRow('array');
            if (!$isForce && $settings['last_generated_at'] && strtotime($settings['last_generated_at']) > time() - 900)
            {
                if ($isOwn)
                {
                    $db->transCommit();
                }
                return ['generated' => 0];
            }
            $now = ActionCenter::Now($clock);
            $days = json_decode($settings['days'], true);
            $today = $now->setTime(0, 0);
            $admin = Database::Column($db->table('users')->select('user_id', false)->where('role_key', 'admin')->where('is_active', true)->where(new \CodeIgniter\Database\RawSql('NOT is_locked'))->get());
            $before = (int)Database::Scalar($db->table('notifications')->select('COUNT(*)', false)->get());
            foreach ($db->table('expiry_items')->select('expiry_id,server_id,hosted_system_id,expiry_date', false)->where(new \CodeIgniter\Database\RawSql('expiry_date IS NOT NULL'))->get()->getResultArray() as $expiry)
            {
                $left = (int)$today->diff(new DateTimeImmutable($expiry['expiry_date'], new DateTimeZone('Asia/Manila')))->format('%r%a');
                if ($left >= 0)
                {
                    $thresholds = array_values(array_filter($days, static fn ($d) => $d >= $left));
                    if (!$thresholds)
                    {
                        continue;
                    }
                    $bucket = 'before-'.min($thresholds);
                }
                else
                {
                    $bucket = 'overdue-'.intdiv(abs($left) - 1, (int)$settings['overdue_repeat_days']);
                }
                $ownersSql = 'SELECT DISTINCT u.owner FROM applications a JOIN application_assets l ON l.application_id=a.application_id LEFT JOIN hosted_systems hs ON hs.hosted_system_id=l.hosted_system_id CROSS JOIN LATERAL(VALUES(a.owner_user_id),(a.technical_owner_id))u(owner) WHERE u.owner IS NOT NULL AND ((l.server_id IS NOT NULL AND l.server_id=?) OR(l.hosted_system_id IS NOT NULL AND l.hosted_system_id=?) OR(hs.server_id=? AND CAST(? AS INTEGER) IS NULL))';
                $owners = $db->query($ownersSql, [$expiry['server_id'],$expiry['hosted_system_id'],$expiry['server_id'],$expiry['hosted_system_id']]);
                $recipients = array_values(array_filter(Database::Column($owners), static fn ($owner) => Permissions::UserCan($db, (int)$owner, 'servers', 'view'))) ?: $admin;
                foreach ($recipients as $user)
                {
                    if (Permissions::UserCan($db, (int)$user, 'servers', 'view'))
                    {
                        self::Add(
                            $db,
                            (int)$user,
                            'renewal',
                            'Renewal needs attention',
                            $left < 0 ? 'An inventoried certificate, license or service is '.abs($left).' day(s) overdue.' : 'An inventoried certificate, license or service expires in '.$left.' day(s).',
                            APP_BASE_URL.'/modules/servers/index.php?renewal_id='.$expiry['expiry_id'],
                            'renewal:'.$expiry['expiry_id'].':'.$expiry['expiry_date'].':'.$bucket
                        );
                    }
                }
            }
            
            $overdue = $db->table('review_runs')->select('review_id,assigned_to,due_at', false)->where(new \CodeIgniter\Database\RawSql('assigned_to IS NOT NULL'))->where('due_at <', $today->format('Y-m-d'))->where(new \CodeIgniter\Database\RawSql('completed_at IS NULL'))->get();
            foreach ($overdue->getResultArray() as $run)
            {
                if (!Permissions::UserCan($db, (int)$run['assigned_to'], 'codereview', 'review'))
                {
                    continue;
                }
                $late = (int)$today->diff(new DateTimeImmutable($run['due_at'], new DateTimeZone('Asia/Manila')))->format('%a');
                $bucket = intdiv(max(0, $late - 1), (int)$settings['overdue_repeat_days']);
                self::Add(
                    $db,
                    (int)$run['assigned_to'],
                    'review_overdue',
                    'Review is overdue',
                    'A review assigned to you is past its due date. Open it to review findings and record your decision.',
                    APP_BASE_URL.'/modules/codereview/history.php?review_id='.$run['review_id'],
                    'review-overdue:'.$run['review_id'].':'.$run['due_at'].':'.$bucket
                );
            }
            LogReminders::Generate($db, $settings, $now);
            $db->table('notification_settings')->set('last_generated_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('id', 1)->update();
            $generated = (int)Database::Scalar($db->table('notifications')->select('COUNT(*)', false)->get()) - $before;
            if ($isOwn)
            {
                $db->transCommit();
            }
            return ['generated' => $generated];
        }
        catch (Throwable $e)
        {
            if ($isOwn && Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw $e;
        }
    }
    public static function Deliver(BaseConnection $db, int $limit = 25): array
    {
        $result = ['sent' => 0,'failed' => 0,'skipped' => 0];
        $db->table('notification_settings')->set('last_worker_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('id', 1)->update();
        if (!Database::Scalar($db->table('notification_settings')->select('email_enabled', false)->where('id', 1)->get()))
        {
            return $result;
        }
        for ($i = 0;$i < max(1, min(100, $limit));$i++)
        {
            $db->transBegin();
            $job = Database::Scalar($db->query("SELECT d.delivery_id FROM notification_deliveries d WHERE attempts<5 AND available_at<=NOW() AND(status IN('pending','failed') OR(status='processing' AND claimed_at<NOW()-INTERVAL '15 minutes')) ORDER BY d.delivery_id FOR UPDATE SKIP LOCKED LIMIT 1"));
            if (!$job)
            {
                $db->transCommit();
                break;
            }
            $db->table('notification_deliveries')->set('status', 'processing')->set('attempts', new \CodeIgniter\Database\RawSql('attempts+1'))->set('claimed_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('delivery_id', $job)->update();
            $db->transCommit();
            $stmtSql = 'SELECT d.*,n.*,u.email,u.first_name,u.last_name,u.is_active,u.is_locked,COALESCE(p.email_enabled,TRUE) preference FROM notification_deliveries d JOIN notifications n ON n.notification_id=d.notification_id JOIN users u ON u.user_id=n.user_id LEFT JOIN notification_preferences p ON p.user_id=n.user_id WHERE delivery_id=?';
            $stmt = $db->query($stmtSql, [$job]);
            $row = $stmt->getUnbufferedRow('array');
            $module = $row['kind'] === 'renewal' ? 'servers' : ($row['kind'] === 'daily_log_attention' ? 'dailylogs' : ($row['kind'] === 'weekly_approval' ? 'reviewlogs' : 'codereview'));
            $settings = $db->table('notification_settings')->where('id', 1)->get()->getUnbufferedRow('array');
            $isLogAllowed = !LogReminders::IsKind($row['kind']) || ($settings['daily_log_email_enabled'] && LogReminders::Relevant($db, $row, $settings));
            if (!$settings['email_enabled'] || !$isLogAllowed || !$row['is_active'] || $row['is_locked'] || !$row['preference'] || !filter_var($row['email'], FILTER_VALIDATE_EMAIL) || !Permissions::UserCan($db, (int)$row['user_id'], $module, 'view'))
            {
                $status = 'skipped';
                $error = 'Recipient is unavailable, opted out or no longer has access.';
            }
            else
            {
                $sent = Mailer::SendNotification($row);
                $status = $sent['ok'] ? 'sent' : 'failed';
                $error = $sent['ok'] ? null : 'Email delivery failed. Check SMTP configuration and connectivity.';
            }
            $stmtSql = "UPDATE notification_deliveries SET status=?,sent_at=CASE WHEN ?='sent' THEN NOW() ELSE NULL END,last_error=?,available_at=NOW()+make_interval(mins=>LEAST(120,5*attempts)) WHERE delivery_id=?";
            $stmt = $db->query($stmtSql, [$status,$status,$error,$job]);
            $result[$status]++;
        }
        return $result;
    }
}
