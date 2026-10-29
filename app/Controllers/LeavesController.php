<?php
/**
 * Filename: LeavesController.php
 * Purpose: Handle Leaves routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Auth;
use Database;
use DomainException;
use InvalidArgumentException;
use LeaveRequests;
use OutOfBoundsException;

class LeavesController extends BaseController
{
    public function Api()
    {
        require_once (ROOTPATH . 'modules/leaves').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/leaves').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/leaves').'/../../lib/LeaveRequests.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        $action = $_GET['action'] ?? '';
        if (!in_array($action, ['calendar','get_leave','save_leave','delete_leave','review_leave'], true))
        {
            JsonError('Unknown action.', 404);
        }
        $isRead = in_array($action, ['calendar','get_leave'], true);
        if ($_SERVER['REQUEST_METHOD'] !== ($isRead ? 'GET' : 'POST'))
        {
            JsonError('Invalid request method.', 405);
        }
        if (!$isRead)
        {
            RequireCsrf();
        }
        try
        {
            $db = Database::GetConnection('app');
            $actor = (int)$_SESSION['user_id'];
            $data = $isRead ? $_GET : ReadJsonBody();
            if ($action === 'calendar')
            {
                JsonResponse(LeaveRequests::Calendar($db, $actor, $data));
            }
            if ($action === 'get_leave')
            {
                JsonResponse(LeaveRequests::Get($db, $actor, (int)($data['log_id'] ?? 0)));
            }
            if ($action === 'save_leave')
            {
                JsonResponse(['ok' => true,'log_id' => LeaveRequests::Save($db, $actor, $data)]);
            }
            if ($action === 'delete_leave')
            {
                LeaveRequests::Delete($db, $actor, $data);
            }
            if ($action === 'review_leave')
            {
                LeaveRequests::Review($db, $actor, $data);
            }
            JsonResponse(['ok' => true]);
        }
        catch (InvalidArgumentException $e)
        {
            JsonError($e->getMessage(), 422);
        }
        catch (OutOfBoundsException $e)
        {
            JsonError($e->getMessage(), 404);
        }
        catch (DomainException $e)
        {
            JsonError($e->getMessage(), 409);
        }
    }

    public function Index()
    {
        $pageTitle = 'Leaves';
        $pageSubtitle = 'Department leave calendar and birthdays';
        $activeNav = 'leaves';
        $bodyClass = 'workspace-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/workflows.css','assets/css/leaves.css'];
        $pageScript = 'assets/js/leaves.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('leaves/index', get_defined_vars());
    }
}
