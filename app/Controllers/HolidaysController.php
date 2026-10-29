<?php
/**
 * Filename: HolidaysController.php
 * Purpose: Handle Holidays routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Auth;
use DailyLogCalendar;
use DailyLogs;
use Database;
use DomainException;
use InvalidArgumentException;
use OutOfBoundsException;

class HolidaysController extends BaseController
{
    public function Api()
    {
        require_once (ROOTPATH . 'modules/holidays').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/holidays').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/holidays').'/../../lib/DailyLogs.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        $action = $_GET['action'] ?? '';
        if (!in_array($action, ['holidays','save_holiday','delete_holiday'], true))
        {
            JsonError('Unknown action.', 404);
        }
        $isRead = $action === 'holidays';
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
            $data = $isRead ? $_GET : ReadJsonBody();
            if ($isRead)
            {
                JsonResponse(DailyLogCalendar::Listing($db, (int)$_SESSION['user_id'], $data));
            }
            if ($action === 'save_holiday')
            {
                DailyLogCalendar::Save($db, $data);
            }
            else
            {
                DailyLogCalendar::Delete($db, $data);
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
        $pageTitle = 'Holidays';
        $pageSubtitle = 'Organization-wide working-day calendar';
        $activeNav = 'holidays';
        $bodyClass = 'workspace-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/workflows.css','assets/css/holidays.css'];
        $pageScript = 'assets/js/holidays.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('holidays/index', get_defined_vars());
    }
}
