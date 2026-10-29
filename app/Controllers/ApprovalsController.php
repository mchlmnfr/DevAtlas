<?php
/**
 * Filename: ApprovalsController.php
 * Purpose: Handle Approvals routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use DailyLogs;


use Auth;
use Permissions;

class ApprovalsController extends BaseController
{
    public function DailyLogs()
    {
        $dailyLogModule = 'reviewlogs';
        $approvalTab = 'dailylogs';
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

        return $this->Render('approvals/dailylogs', get_defined_vars());
    }

    public function Leaves()
    {
        $approvalTab = 'leaves';
        $pageTitle = 'Approvals';
        $pageSubtitle = 'Leave applications awaiting section approval';
        $activeNav = 'approvals';
        $bodyClass = 'workspace-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/workflows.css','assets/css/leaves.css'];
        $pageScript = 'assets/js/leaves.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('approvals/leaves', get_defined_vars());
    }

    public function Index()
    {
        Auth::RequirePage();
        $tab = $_GET['tab'] ?? (Permissions::Can('reviewlogs') ? 'dailylogs' : 'leaves');
        if (!in_array($tab, ['dailylogs', 'leaves'], true))
        {
            return $this->response->setStatusCode(404);
        }
        Permissions::Require($tab === 'leaves' ? 'leaves' : 'reviewlogs', $tab === 'leaves' ? 'review' : 'view');
        return $tab === 'leaves' ? $this->Leaves() : $this->DailyLogs();
    }
}
