<?php
/**
 * Filename: NotificationsController.php
 * Purpose: Handle Notifications routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Notifications;


use Auth;
use Database;
use InvalidArgumentException;
use Permissions;

class NotificationsController extends BaseController
{
    public function Api()
    {
        require_once (ROOTPATH . 'modules/notifications').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/notifications').'/../../lib/Notifications.php';
        require_once (ROOTPATH . 'modules/notifications').'/../../lib/helpers.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        $db = Database::GetConnection('app');
        $user = (int)$_SESSION['user_id'];
        $action = $_GET['action'] ?? 'inbox';
        $reads = ['inbox','count','preferences','config','deliveries'];
        if ($_SERVER['REQUEST_METHOD'] !== (in_array($action, $reads, true) ? 'GET' : 'POST'))
        {
            JsonError('Invalid request method.', 405);
        }
        if (!in_array($action, $reads, true))
        {
            RequireCsrf();
        }
        if (in_array($action, ['config','save_config','deliveries','run_due'], true) && !Permissions::IsAdmin())
        {
            Permissions::Deny(true);
        }
        try
        {
            $data = in_array($action, $reads, true) ? $_GET : ReadJsonBody();
            if ($action === 'count')
            {
                Notifications::Generate($db);
                
                $stmt = $db->table('notifications')->select('COUNT(*)', false)->where('user_id', $user)->where(new \CodeIgniter\Database\RawSql('NOT is_read'))->get();
                JsonResponse(['unread' => (int)Database::Scalar($stmt)]);
            }
            if ($action === 'inbox')
            {
                Notifications::Generate($db);
                $where = ' WHERE user_id=?';
                if (($data['unread'] ?? '') === '1')
                {
                    $where .= ' AND NOT is_read';
                }
                $countSql = 'SELECT COUNT(*) FROM notifications'.$where;
                $count = $db->query($countSql, [$user]);
                $total = (int)Database::Scalar($count);
                require_once ROOTPATH.'lib/ListPageSize.php';
        $size = \ListPageSize::Get();
        $pages = max(1, (int)ceil($total / $size));
                $page = min(max(1, (int)($data['page'] ?? 1)), $pages);
                $stmtSql = 'SELECT * FROM notifications'.$where.' ORDER BY notification_id DESC LIMIT '.$size.' OFFSET '.(($page - 1) * $size);
                $stmt = $db->query($stmtSql, [$user]);
                JsonResponse(['notifications' => $stmt->getResultArray(),'total' => $total,'pages' => $pages,'page' => $page]);
            }
            if ($action === 'mark_read')
            {
                $db->table('notifications')->set('is_read', true)->set('read_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('user_id', $user)->where('notification_id', (int)($data['notification_id'] ?? 0))->where(new \CodeIgniter\Database\RawSql('NOT is_read'))->update();
                JsonResponse(['ok' => true]);
            }
            if ($action === 'mark_all_read')
            {
                $db->table('notifications')->set('is_read', true)->set('read_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('user_id', $user)->where(new \CodeIgniter\Database\RawSql('NOT is_read'))->update();
                JsonResponse(['ok' => true]);
            }
            if ($action === 'preferences')
            {
                
                $stmt = $db->table('notification_preferences')->select('email_enabled', false)->where('user_id', $user)->get();
                $row = $stmt->getUnbufferedRow('array');
                JsonResponse(['email_enabled' => $row ? (bool)$row['email_enabled'] : true]);
            }
            if ($action === 'save_preferences')
            {
                if (!is_bool($data['email_enabled'] ?? null))
                {
                    throw new InvalidArgumentException('Choose your email preference.');
                }
                $db->query('INSERT INTO notification_preferences(user_id,email_enabled)VALUES(?,?)ON CONFLICT(user_id)DO UPDATE SET email_enabled=EXCLUDED.email_enabled', [$user,PgBool($data['email_enabled'])]);
                JsonResponse(['ok' => true]);
            }
            if ($action === 'config')
            {
                JsonResponse($db->table('notification_settings')->select('*,array_to_json(reminder_days)::TEXT days', false)->where('id', 1)->get()->getUnbufferedRow('array'));
            }
            if ($action === 'save_config')
            {
                $days = $data['reminder_days'] ?? null;
                $repeat = $data['overdue_repeat_days'] ?? null;
                if (!is_bool($data['email_enabled'] ?? null) || !is_array($days) || !array_is_list($days) || !$days || count($days) > 10 || !is_int($repeat) || $repeat < 1 || $repeat > 90)
                {
                    throw new InvalidArgumentException('Use 1â€“10 reminder intervals and an overdue interval of 1â€“90 days.');
                }
                foreach ($days as $day)
                {
                    if (!is_int($day) || $day < 0 || $day > 365)
                    {
                        throw new InvalidArgumentException('Reminder days must be whole numbers from 0 to 365.');
                    }
                }
                if ($data['email_enabled'] && !Database::Scalar($db->table('system_settings')->select('smtp_host IS NOT NULL AND smtp_host<>\'\' AND smtp_from_email IS NOT NULL AND smtp_from_email<>\'\'', false)->where('id', 1)->get()))
                {
                    throw new InvalidArgumentException('Configure and test SMTP in Parameters before enabling reminder emails.');
                }
                $days = array_unique($days);
                rsort($days);
                $current = $db->table('notification_settings')->where('id', 1)->get()->getUnbufferedRow('array');
                $log = [];
                foreach (['daily_log_enabled','approval_enabled','daily_log_email_enabled'] as $key)
                {
                    $log[$key] = $data[$key] ?? $current[$key];
                    if (!is_bool($log[$key]))
                    {
                        throw new InvalidArgumentException('Choose a valid daily-log reminder option.');
                    }
                }
                foreach (['daily_log_hour' => [0,23],'daily_log_lookback_days' => [1,90],'approval_delay_days' => [0,30]] as $key => [$min,$max])
                {
                    $log[$key] = $data[$key] ?? (int)$current[$key];
                    if (!is_int($log[$key]) || $log[$key] < $min || $log[$key] > $max)
                    {
                        throw new InvalidArgumentException('Choose a valid reminder hour, lookback period and approval delay.');
                    }
                }
                $db->transBegin();
                $previous = $db->query($db->table('notification_settings')->select('email_enabled,daily_log_email_enabled', false)->where('id', 1)->getCompiledSelect() . ' FOR UPDATE')->getUnbufferedRow('array');
                $db->query("UPDATE notification_settings SET email_enabled=?,reminder_days=CAST(? AS INTEGER[]),overdue_repeat_days=?,last_generated_at=NULL WHERE id=1", [PgBool($data['email_enabled']),'{'.implode(',', $days).'}',$repeat]);
                $db->table('notification_settings')->set('daily_log_enabled', PgBool($log['daily_log_enabled']))->set('approval_enabled', PgBool($log['approval_enabled']))->set('daily_log_email_enabled', PgBool($log['daily_log_email_enabled']))->set('daily_log_hour', $log['daily_log_hour'])->set('daily_log_lookback_days', $log['daily_log_lookback_days'])->set('approval_delay_days', $log['approval_delay_days'])->where('id', 1)->update();
                if ($data['email_enabled'] && (!$previous['email_enabled'] || ($log['daily_log_email_enabled'] && !$previous['daily_log_email_enabled'])))
                {
                    $db->query("INSERT INTO notification_deliveries(notification_id) SELECT n.notification_id FROM notifications n JOIN users u ON u.user_id=n.user_id LEFT JOIN notification_preferences p ON p.user_id=u.user_id WHERE NOT n.is_read AND n.created_at>=NOW()-INTERVAL '24 hours' AND u.is_active AND NOT u.is_locked AND COALESCE(p.email_enabled,TRUE) AND(n.kind NOT IN('daily_log_attention','weekly_approval') OR(SELECT daily_log_email_enabled FROM notification_settings WHERE id=1)) ON CONFLICT(notification_id) DO NOTHING");
                }
                $db->transCommit();
                JsonResponse(['ok' => true]);
            }
            if ($action === 'run_due')
            {
                JsonResponse(['ok' => true] + Notifications::Generate($db, true));
            }
            if ($action === 'deliveries')
            {
                JsonResponse($db->query('SELECT d.delivery_id,d.status,d.attempts,d.available_at,d.sent_at,d.last_error,n.title,n.user_id,u.employee_id FROM notification_deliveries d JOIN notifications n ON n.notification_id=d.notification_id JOIN users u ON u.user_id=n.user_id ORDER BY d.delivery_id DESC LIMIT 100')->getResultArray());
            }
            JsonError('Unknown action.');
        }
        catch (InvalidArgumentException $e)
        {
            JsonError($e->getMessage(), 422);
        }
    }

    public function Index()
    {
        $pageTitle = 'Notifications';
        $pageSubtitle = 'Your reminders, review assignments and delivery preferences';
        $activeNav = 'notifications';
        $bodyClass = 'workspace-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/workflows.css'];
        $pageScripts = ['assets/js/workspace-ui.js'];
        $pageScript = 'assets/js/notifications.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('notifications/index', get_defined_vars());
    }
}
