<?php
/**
 * Filename: WikiController.php
 * Purpose: Handle Wiki routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Auth;
use SystemWiki;

class WikiController extends BaseController
{
    public function Index()
    {
        $pageTitle = 'Documents Wiki';
        $pageSubtitle = 'Module guides and system roadmap';
        $activeNav = 'wiki';
        $bodyClass = 'workspace-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/wiki.css'];
        $pageScript = 'assets/js/wiki.js';
        Auth::RequirePage();
        $currentUser = Auth::User();

        require_once (ROOTPATH . 'modules/wiki').'/../../lib/SystemWiki.php';
        $guides = SystemWiki::Modules();
        $groups = [];
        foreach ($guides as $guide)
        {
            $groups[$guide[1]][] = $guide;
        }

        return $this->Render('wiki/index', get_defined_vars());
    }
}
