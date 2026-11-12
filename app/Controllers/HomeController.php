<?php
/**
 * Filename: HomeController.php
 * Purpose: Handle Home routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Render saved dashboard widget order, visibility and spacing.
 */

namespace App\Controllers;

use AccessLinks;
use ActionCenter;
use Auth;
use Database;
use Permissions;

class HomeController extends BaseController
{
    public function Index()
    {
        $pageTitle = 'Dashboard';
        $pageSubtitle = 'Your next actions, this week and across your section';
        $activeNav = 'dashboard';
        $bodyClass = 'dashboard-page';
        $pageStyles = ['assets/css/dashboard-actions.css','assets/css/dashboard-builder.css'];
        $pageScripts = ['assets/js/dashboard.js','assets/js/dashboard-builder.js'];
        Auth::RequirePage();
        $currentUser = Auth::User();
        require_once ROOTPATH . 'lib/UserUiPreferences.php';
        $uiState = \UserUiPreferences::Load(Database::GetConnection('app'), (int)$currentUser['user_id']);
        require_once ROOTPATH.'lib/WorkspaceExperience.php';
        $experienceState = \WorkspaceExperience::Initial(Database::GetConnection('app'), (int)$currentUser['user_id'], 'dashboard', $uiState['preferences']);

        require_once (ROOTPATH . '').'/lib/ActionCenter.php';
        require_once (ROOTPATH . '').'/lib/AccessLinks.php';
        $dbStatus = Database::Ping('app');
        $actions = null;
        $inventory = [];
        $links = [];
        if ($dbStatus['ok'])
        {
            $db = Database::GetConnection('app');
            $actions = ActionCenter::Overview($db, (int)$currentUser['user_id']);
            foreach (['datadict' => ['Documented tables','database','dd_tables',''], 'diagrams' => ['ERDs','workflow','diagrams'," WHERE diagram_type='ERD'"], 'servers' => ['Active servers','server','servers',' WHERE is_active']] as $module => [$label,$icon,$table,$where])
            {
                if (Permissions::Can($module))
                {
                    $inventory[] = ['module' => $module,'label' => $label,'icon' => $icon,'count' => (int)Database::Scalar($db->query('SELECT COUNT(*) FROM '.$table.$where))];
                }
            }
            $links = $db->table('bookmarks')->select('title,url', false)->orderBy('created_at DESC', '', false)->limit((int) (4), (int) (0))->get()->getResultArray();
        }
        $dateLabel = static fn (string $date) => Date('M j', strtotime($date));
        $path = static fn (string $module, array $query = []) => APP_BASE_URL.'/modules/'.$module.'/index.php'.($query ? '?'.http_build_query($query) : '');

        return $this->Render('home/index', get_defined_vars());
    }
}

