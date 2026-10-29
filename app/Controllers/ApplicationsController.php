<?php
/**
 * Filename: ApplicationsController.php
 * Purpose: Handle Applications routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Applications;


use Auth;
use Database;
use InvalidArgumentException;
use OutOfBoundsException;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Permissions;

class ApplicationsController extends BaseController
{
    public function Api()
    {
        require_once (ROOTPATH . 'modules/applications').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/applications').'/../../lib/Applications.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        $db = Database::GetConnection('app');
        $action = $_GET['action'] ?? '';
        $reads = ['list_applications','get_application','catalog','asset_candidates'];
        if ($_SERVER['REQUEST_METHOD'] !== (in_array($action, $reads, true) ? 'GET' : 'POST'))
        {
            JsonError('Invalid request method.', 405);
        }
        if (!in_array($action, $reads, true))
        {
            RequireCsrf();
        }
        try
        {
            $data = in_array($action, $reads, true) ? $_GET : ReadJsonBody();
            $id = (int)($data['application_id'] ?? 0);
            if ($action === 'catalog')
            {
                $types = [];
                foreach (Applications::ASSETS as $key => $asset)
                {
                    if (Permissions::Can($asset['module']))
                    {
                        $types[$key] = ucwords(str_replace('_', ' ', $key));
                    }
                }
                JsonResponse(['applications' => $db->table('applications')->select('application_id,application_code,application_name', false)->orderBy('application_name', '', false)->limit((int) (5000), (int) (0))->get()->getResultArray(),'people' => $db->table('users')->select('user_id,first_name||\' \'||last_name name', false)->where('is_active', true)->orderBy('last_name,first_name', '', false)->get()->getResultArray(),
                    'departments' => $db->table('org_departments')->select('department_id,department_name', false)->orderBy('department_name', '', false)->get()->getResultArray(),'asset_types' => $types]);
            }
            if ($action === 'list_applications')
            {
                $q = Applications::Text($data['q'] ?? '', 200);
                $life = Applications::Text($data['lifecycle'] ?? '', 20);
                $owner = (int)($data['owner'] ?? 0);
                $page = max(1, (int)($data['page'] ?? 1));
                if ($life !== '' && !in_array($life, ['Planned','Active','Maintenance','Retired'], true))
                {
                    throw new InvalidArgumentException('Choose a valid lifecycle.');
                }
                $where = ' WHERE 1=1';
                $params = [];
                if ($q !== '')
                {
                    $where .= " AND CONCAT_WS(' ',a.application_code,a.application_name,a.description) ILIKE ?";
                    $params[] = '%'.$q.'%';
                }
                if ($life !== '')
                {
                    $where .= ' AND a.lifecycle=?';
                    $params[] = $life;
                }
                if ($owner)
                {
                    $where .= ' AND(a.owner_user_id=? OR a.technical_owner_id=?)';
                    array_push($params, $owner, $owner);
                }
                $stmtSql = 'SELECT COUNT(*) FROM applications a'.$where;
                $stmt = $db->query($stmtSql, $params);
                $total = (int)Database::Scalar($stmt);
                $pages = max(1, (int)ceil($total / 25));
                $page = min($page, $pages);
                $stmtSql = "SELECT a.*,u.first_name||' '||u.last_name owner_name,(SELECT COUNT(*) FROM application_assets l WHERE l.application_id=a.application_id) asset_count FROM applications a LEFT JOIN users u ON u.user_id=a.owner_user_id".$where.' ORDER BY a.application_name,a.application_id LIMIT 25 OFFSET '.(($page - 1) * 25);
                $stmt = $db->query($stmtSql, $params);
                $stats = $db->table('applications')->select('COUNT(*) total,COUNT(*)FILTER(WHERE lifecycle=\'Active\') active,COUNT(*)FILTER(WHERE criticality=\'Critical\') critical,COUNT(*)FILTER(WHERE owner_user_id IS NULL) unowned', false)->get()->getUnbufferedRow('array');
                JsonResponse(['applications' => $stmt->getResultArray(),'total' => $total,'page' => $page,'pages' => $pages,'stats' => $stats]);
            }
            if ($action === 'asset_candidates')
            {
                JsonResponse(Applications::Candidates($db, Applications::Text($data['type'] ?? '', 30, true)));
            }
            if ($action === 'get_application')
            {
                $app = Applications::Get($db, $id);
                $app['assets'] = Applications::Links($db, $id);
                $app['reviews'] = [];
                $app['inventory_runs'] = [];
                if (Permissions::Can('codereview'))
                {
                    
                    $stmt = $db->table('review_runs')->select('review_id,title,engine,finding_count,completed_at,created_at', false)->where('application_id', $id)->orderBy('review_id DESC', '', false)->limit((int) (20), (int) (0))->get();
                    $app['reviews'] = $stmt->getResultArray();
                }
                if (Permissions::Can('proginventory'))
                {
                    
                    $stmt = $db->table('application_inventory_runs')->select('inventory_run_id,file_version,origin,file_count,created_at', false)->where('application_id', $id)->orderBy('inventory_run_id DESC', '', false)->limit((int) (20), (int) (0))->get();
                    $app['inventory_runs'] = $stmt->getResultArray();
                }
                JsonResponse($app);
            }
            if ($action === 'save_application')
            {
                JsonResponse(['ok' => true,'application_id' => Applications::Save($db, $data, (int)$_SESSION['user_id'])]);
            }
            if ($action === 'link_asset')
            {
                Applications::Link($db, $id, Applications::Text($data['type'] ?? '', 30, true), (int)($data['asset_id'] ?? 0));
                JsonResponse(['ok' => true]);
            }
            if ($action === 'remove_asset')
            {
                $db->table('application_assets')->where('application_id', $id)->where('asset_link_id', (int)($data['asset_link_id'] ?? 0))->delete();
                JsonResponse(['ok' => true]);
            }
            if ($action === 'delete_application')
            {
                
                $stmt = $db->table('applications')->where('application_id', $id)->delete();
                if (!$db->affectedRows())
                {
                    throw new OutOfBoundsException('Application not found.');
                }
                JsonResponse(['ok' => true]);
            }
            JsonError('Unknown action.');
        }
        catch (InvalidArgumentException $e)
        {
            JsonError($e->getMessage(), 422);
        }
        catch (OutOfBoundsException $e)
        {
            JsonError($e->getMessage(), 404);
        }
        catch (DatabaseException $e)
        {
            if (Database::SqlState($e) === '23505')
            {
                JsonError('This application code is already in use.', 409);
            }
            if (Database::SqlState($e) === '23503')
            {
                JsonError('A selected record no longer exists. Refresh and try again.', 409);
            }
            throw $e;
        }
    }

    public function Index()
    {
        $pageTitle = 'Application Catalog';
        $pageSubtitle = 'Ownership, lifecycle and connected assets in one place';
        $activeNav = 'applications';
        $bodyClass = 'workspace-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/workflows.css'];
        $pageScripts = ['assets/js/workspace-ui.js'];
        $pageScript = 'assets/js/applications.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('applications/index', get_defined_vars());
    }
}
