<?php
/**
 * Filename: DailylogsController.php
 * Purpose: Handle Dailylogs routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Auth;
use DailyLogCalendar;
use DailyLogs;
use Database;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use OutOfBoundsException;
use Permissions;
use WeeklyLogReview;

class DailylogsController extends BaseController
{
    public function Api()
    {
        $dailyLogModule = 'dailylogs';


        /**
         * Filename: daily_logs_api.php
         * Purpose: Provide daily logs api processing and supporting DevAtlas workflows.
         * Developer: DevAtlas maintainers
         * Date: 2026-10-28
         * Recent updates: Route leave details and changes through the dedicated leave module.
         */

        require_once (ROOTPATH . 'lib').'/Auth.php';
        require_once (ROOTPATH . 'lib').'/helpers.php';
        require_once (ROOTPATH . 'lib').'/DailyLogs.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        $db = Database::GetConnection('app');
        $actor = (int)$_SESSION['user_id'];
        $action = $_GET['action'] ?? '';
        $allowed = match ($dailyLogModule)
        {
            'dailylogs' => ['catalog','calendar','list_logs','get_log','save_log','delete_log'],'reviewlogs' => ['catalog','list_weeks','get_week','list_logs','get_log','review_weeks','reviewer_assignments','save_reviewer'],'teammonitoring' => ['catalog','monitoring','list_logs','get_log'],default => []
        };
        if (!in_array($action, $allowed, true))
        {
            JsonError('Unknown action.', 404);
        }
        $read = in_array($action, ['catalog','calendar','list_weeks','get_week','list_logs','get_log','monitoring','reviewer_assignments'], true);
        if ($_SERVER['REQUEST_METHOD'] !== ($read ? 'GET' : 'POST'))
        {
            JsonError('Invalid request method.', 405);
        }
        if (!$read)
        {
            RequireCsrf();
        }
        try
        {
            $data = $read ? $_GET : ReadJsonBody();
            $isTeam = $dailyLogModule !== 'dailylogs';
            $isMonitor = $dailyLogModule === 'teammonitoring';
            if ($action === 'reviewer_assignments')
            {
                JsonResponse(DailyLogs::ReviewerAssignments($db));
            }
            if ($action === 'save_reviewer')
            {
                DailyLogs::AssignReviewer($db, $actor, $data);
                JsonResponse(['ok' => true]);
            }
            if ($action === 'catalog')
            {
                JsonResponse(['classifications' => DailyLogs::CLASSIFICATIONS,'activities' => DailyLogs::ACTIVITIES,'activity_descriptions' => DailyLogs::ACTIVITY_DESCRIPTIONS,'leaves' => DailyLogs::LEAVES,'profile' => DailyLogs::Profile($db, $actor),'units' => $isTeam ? DailyLogs::Units($db, $actor, $isMonitor) : [],'today' => (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d')]);
            }
            if ($action === 'list_logs')
            {
                JsonResponse(DailyLogs::Listing($db, $actor, $data, $isTeam, $dailyLogModule === 'reviewlogs', $isMonitor));
            }
            if ($action === 'get_log')
            {
                $row = DailyLogs::Get($db, $actor, (int)($data['log_id'] ?? 0), $isTeam, $isMonitor);
                if ($row['entry_kind'] !== 'activity')
                {
                    throw new OutOfBoundsException('View this leave request in the Leaves module.');
                }
                JsonResponse($row);
            }
            if ($action === 'monitoring')
            {
                JsonResponse(DailyLogs::Monitoring($db, $actor, $data));
            }
            if ($action === 'save_log')
            {
                JsonResponse(['ok' => true,'log_id' => DailyLogs::Save($db, $actor, $data)]);
            }
            if ($action === 'delete_log')
            {
                DailyLogs::Delete($db, $actor, $data);
                JsonResponse(['ok' => true]);
            }
            if ($action === 'list_weeks')
            {
                JsonResponse(WeeklyLogReview::Listing($db, $actor, $data));
            }
            if ($action === 'get_week')
            {
                JsonResponse(WeeklyLogReview::Get($db, $actor, (int)($data['user_id'] ?? 0), $data['week_start'] ?? ''));
            }
            if ($action === 'review_weeks')
            {
                JsonResponse(['ok' => true,'reviewed' => DailyLogs::Review($db, $actor, $data)]);
            }
            if ($action === 'calendar')
            {
                JsonResponse(DailyLogCalendar::Day($db, $actor, $data['date'] ?? ''));
            }
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
        $dailyLogModule = 'dailylogs';

        /**
            * Filename: daily_logs_page.php
            * Purpose: Provide daily logs page processing and supporting DevAtlas workflows.
            * Developer: DevAtlas maintainers
            * Date: 2026-10-28
         * Recent updates: Display work-only entry controls and the Daily Logs approval tab.
            */

        $titles = ['dailylogs' => 'My Daily Logs','teammonitoring' => 'Team Monitoring','reviewlogs' => 'Review Daily Logs'];
        $pageTitle = isset($approvalTab) ? 'Approvals' : $titles[$dailyLogModule];
        $pageSubtitle = 'Work activities and team completion';
        $activeNav = isset($approvalTab) ? 'approvals' : $dailyLogModule;
        $bodyClass = 'workspace-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/workflows.css','assets/css/daily-logs.css','assets/css/leaves.css'];
        $pageScript = 'assets/js/daily-logs.js';
        Auth::RequirePage();
        $currentUser = Auth::User();

        $isPersonal = $dailyLogModule === 'dailylogs';
        $isMonitor = $dailyLogModule === 'teammonitoring';
        $canReview = Permissions::Can('reviewlogs', 'review');

        return $this->Render('dailylogs/index', get_defined_vars());
    }
}
