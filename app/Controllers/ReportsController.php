<?php
/**
 * Filename: ReportsController.php
 * Purpose: Handle Reports routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Auth;
use DailyLogReports;
use DailyLogs;
use DailyLogsWorkbook;
use Database;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use OutOfBoundsException;
use Permissions;
use WeeklySummaryWorkbook;

class ReportsController extends BaseController
{
    public function Api()
    {
        require_once (ROOTPATH . 'modules/reports').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/reports').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/reports').'/../../lib/DailyLogReports.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET')
        {
            JsonError('GET required.', 405);
        }
        $db = Database::GetConnection('app');
        $actor = (int)$_SESSION['user_id'];
        $action = $_GET['action'] ?? '';
        try
        {
            if ($action === 'catalog')
            {
                $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
                $monday = $today->modify('-'.((int)$today->format('N') - 1).' days');
                $profile = DailyLogs::Profile($db, $actor);
                $isTeam = DailyLogReports::HasTeamAccess($db, $actor) && (Permissions::IsAdmin() || !empty($profile['unit_id']));
                JsonResponse(['profile' => $profile,'units' => $isTeam ? DailyLogs::Units($db, $actor, true) : [],
                    'today' => $today->format('Y-m-d'),'week_start' => $monday->format('Y-m-d'),'week_end' => $monday->modify('+4 days')->format('Y-m-d')]);
            }
            if ($action === 'preview')
            {
                JsonResponse(DailyLogReports::Preview($db, $actor, $_GET));
            }
            if ($action === 'weekly_summary')
            {
                $id = (int)($_GET['user_id'] ?? $actor);
                JsonResponse(DailyLogs::WeeklySummary($db, $actor, array_replace($_GET, ['user_id' => $id]), $id !== $actor, true));
            }
            JsonError('Unknown action.', 404);
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

    public function Export()
    {
        require_once (ROOTPATH . 'modules/reports').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/reports').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/reports').'/../../lib/DailyLogReports.php';
        require_once (ROOTPATH . 'modules/reports').'/../../lib/vendor_bootstrap.php';
        require_once (ROOTPATH . 'modules/reports').'/../../lib/WeeklySummaryWorkbook.php';
        require_once (ROOTPATH . 'modules/reports').'/../../lib/DailyLogsWorkbook.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET')
        {
            JsonError('GET required.', 405);
        }
        Permissions::require('reports', 'export');
        try
        {
            $db = Database::GetConnection('app');
            $actor = (int)$_SESSION['user_id'];
            $type = DailyLogReports::Type($_GET);
            $data = $type === 'daily' ? DailyLogReports::Daily($db, $actor, $_GET) : DailyLogs::SectionSummaries($db, $actor, $_GET);
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
        RequireVendorAutoload();
        $book = $type === 'daily' ? DailyLogsWorkbook::Build($data) : WeeklySummaryWorkbook::Build($data);
        $date = $type === 'daily' ? $data['date_from'].'_'.$data['date_to'] : $data['week_start'];
        Header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        Header('Content-Disposition: attachment; filename="'.($type === 'daily' ? 'daily_logs' : 'weekly_summary').'_section_'.$data['section']['unit_id'].'_'.$date.'.xlsx"');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->Save('php://output');
        $book->disconnectWorksheets();
    }

    public function Index()
    {
        $pageTitle = 'Reports';
        $pageSubtitle = 'Daily logs and weekly summaries by employee';
        $activeNav = 'reports';
        $bodyClass = 'workspace-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/workflows.css','assets/css/reports.css'];
        $pageScript = 'assets/js/reports.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('reports/index', get_defined_vars());
    }
}
