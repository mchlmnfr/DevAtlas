<?php
/**
 * Filename: AuditController.php
 * Purpose: Handle Audit routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Audit;


use AuditQuery;
use Auth;
use Database;
use InvalidArgumentException;
use OutOfBoundsException;

class AuditController extends BaseController
{
    public function Api()
    {
        require_once (ROOTPATH . 'modules/audit').'/../../config/config.php';
        require_once (ROOTPATH . 'modules/audit').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/audit').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/audit').'/../../lib/AuditQuery.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET')
        {
            JsonError('GET required.', 405);
        }
        $db = Database::GetConnection('app');
        $action = $_GET['action'] ?? 'history';
        try
        {
            if ($action === 'history')
            {
                JsonResponse(AuditQuery::History($db, $_GET));
            }
            if ($action === 'detail')
            {
                JsonResponse(AuditQuery::Detail($db, (int)($_GET['event_id'] ?? 0), (int)($_GET['page'] ?? 1)));
            }
            if ($action === 'catalog')
            {
                $people = $db->table('audit_events')->select('DISTINCT ON(actor_id) actor_id,actor_employee_id,actor_name', false)->where(new \CodeIgniter\Database\RawSql('actor_id IS NOT NULL'))->orderBy('actor_id,event_id DESC', '', false)->get()->getResultArray();
                JsonResponse(['modules' => Audit::Modules(),'people' => $people]);
            }
            JsonError('Unknown audit action.', 400);
        }
        catch (InvalidArgumentException $e)
        {
            JsonError($e->getMessage(), 422);
        }
        catch (OutOfBoundsException $e)
        {
            JsonError($e->getMessage(), 404);
        }
    }

    public function Client()
    {
        /** Authenticated browser reports supplement authoritative server events. */
        require_once (ROOTPATH . 'modules/audit').'/../../config/config.php';
        require_once (ROOTPATH . 'modules/audit').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/audit').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/audit').'/../../lib/Audit.php';
        Auth::RequireApi();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            JsonError('POST required.', 405);
        }
        RequireCsrf();
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768)
        {
            JsonError('Activity batch too large.', 413);
        }
        $now = time();
        $limit = $_SESSION['audit_browser_limit'] ?? ['at' => $now,'count' => 0];
        if ($now - $limit['at'] >= 300)
        {
            $limit = ['at' => $now,'count' => 0];
        }
        if ($limit['count'] >= 1000)
        {
            JsonError('Browser activity rate limit reached.', 429);
        }
        try
        {
            $data = ReadJsonBody();
            $limit['count'] += count(is_array($data['events'] ?? null) ? $data['events'] : []);
            $_SESSION['audit_browser_limit'] = $limit;
            $count = Audit::ClientEvents(Database::GetConnection('app'), $data, Auth::User());
            JsonResponse(['ok' => true,'recorded' => $count]);
        }
        catch (InvalidArgumentException $e)
        {
            JsonError($e->getMessage(), 422);
        }
    }

    public function Export()
    {
        require_once (ROOTPATH . 'modules/audit').'/../../config/config.php';
        require_once (ROOTPATH . 'modules/audit').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/audit').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/audit').'/../../lib/AuditQuery.php';
        Auth::RequirePage();
        if ($_SERVER['REQUEST_METHOD'] !== 'GET')
        {
            http_response_code(405);
            exit('GET required.');
        }
        try
        {
            $report = AuditQuery::History(Database::GetConnection('app'), $_GET, true);
        }
        catch (InvalidArgumentException $e)
        {
            http_response_code(422);
            exit(H($e->getMessage()));
        }
        Header('Content-Type: text/csv; charset=UTF-8');
        Header('Content-Disposition: attachment; filename="audit-history-'.Date('Ymd-His').'.csv"');
        Header('Cache-Control: no-store');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Event ID','Time (with timezone)','Employee ID','User','Module','Action','Outcome','Source','Changed records','HTTP status','Path','Request reference']);
        foreach ($report['events'] as $event)
        {
            $values = [$event['event_id'],$event['occurred_at'],$event['actor_employee_id'] ?? 'Guest',$event['actor_name'] ?? 'Guest',$event['module_label'],$event['action_label'],$event['outcome'],$event['source'],$event['change_count'],$event['http_status'],$event['path'],$event['request_id']];
            foreach ($values as &$value)
            {
                $value = (string)($value ?? '');
                if (preg_match('/^\s*[=+@\-\t\r\n]/', $value))
                {
                    $value = "'".$value;
                }
            }
            unset($value);
            fputcsv($output, $values);
        }
        fclose($output);
    }

    public function Index()
    {
        $pageTitle = 'Audit Logs';
        $pageSubtitle = 'Account activity and recorded changes across your workspace';
        $activeNav = 'audit';
        $bodyClass = 'workspace-page audit-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/audit.css'];
        $pageScripts = ['assets/js/workspace-ui.js'];
        $pageScript = 'assets/js/audit.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('audit/index', get_defined_vars());
    }
}
