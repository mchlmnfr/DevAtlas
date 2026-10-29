<?php
/**
 * Filename: BookmarksController.php
 * Purpose: Handle Bookmarks routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use AccessLinks;
use Auth;
use Database;
use InvalidArgumentException;
use Permissions;

class BookmarksController extends BaseController
{
    public function Api()
    {
        /** Public read-only Quick Links; modifications require normal account access and CSRF. */
        require_once (ROOTPATH . 'modules/bookmarks') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/bookmarks') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/bookmarks') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/bookmarks') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/bookmarks') . '/../../lib/AccessLinks.php';
        Header('Cache-Control: no-store');
        $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
        if ($action === 'list_bookmarks')
        {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET')
            {
                JsonError('GET required.', 405);
            }
        }
        else
        {
            Auth::RequireApi();
            if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            {
                JsonError('POST required.', 405);
            }
            RequireCsrf();
        }
        $db = Database::GetConnection('app');
        try
        {
            switch ($action)
            {
                case 'list_bookmarks':
                    $query = $_GET['q'] ?? '';
                    if (!is_string($query) || mb_strlen($query) > 200)
                    {
                        JsonError('Search must be 200 characters or fewer.');
                    }
                    $rows = $db->table('bookmarks')->select('bookmark_id,title,url,description,tags,created_at,updated_at', false)->orderBy('title,bookmark_id', '', false)->get()->getResultArray();
                    $isCanManage = Auth::Check() && !Auth::MustChangePassword() && (Permissions::Can('bookmarks', 'create') || Permissions::Can('bookmarks', 'edit') || Permissions::Can('bookmarks', 'delete'));
                    foreach ($rows as &$row)
                    {
                        $row['bookmark_id'] = (int)$row['bookmark_id'];
                        $row['is_available'] = AccessLinks::SafeUrl($row['url']) !== null;
                        if (!$isCanManage && !$row['is_available'])
                        {
                            $row['url'] = null;
                        }
                    }
                    unset($row);
                    // Search only visible fields after masking legacy addresses.
                    $query = trim($query);
                    if ($query !== '')
                    {
                        $rows = array_values(array_filter($rows, static fn ($row) =>
                            mb_stripos(implode(' ', [$row['title'],$row['url'] ?? '',$row['description'] ?? '',$row['tags'] ?? '']), $query) !== false));
                    }
                    JsonResponse($rows);
                    // no break
                case 'save_bookmark':
                    $data = ReadJsonBody();
                    $id = (int)($data['bookmark_id'] ?? 0);
                    $fields = AccessLinks::Fields($data);
                    if ($id > 0)
                    {
                        $fields['id'] = $id;
                        
                        $stmt = $db->table('bookmarks')->set('title', ($fields)['title'])->set('url', ($fields)['url'])->set('description', ($fields)['description'])->set('tags', ($fields)['tags'])->where('bookmark_id', ($fields)['id'])->update();
                        if (!$db->affectedRows())
                        {
                            JsonError('Link not found.', 404);
                        }
                    }
                    else
                    {
                        
                        $stmt = $db->query($db->table('bookmarks')->set('title', ($fields)['title'])->set('url', ($fields)['url'])->set('description', ($fields)['description'])->set('tags', ($fields)['tags'])->getCompiledInsert() . ' RETURNING bookmark_id');
                        $id = (int)Database::Scalar($stmt);
                    }
                    JsonResponse(['ok' => true, 'bookmark_id' => $id]);
                    // no break
                case 'delete_bookmark':
                    $id = (int)(ReadJsonBody()['bookmark_id'] ?? 0);
                    
                    $stmt = $db->table('bookmarks')->where('bookmark_id', $id)->delete();
                    if (!$db->affectedRows())
                    {
                        JsonError('Link not found.', 404);
                    }
                    JsonResponse(['ok' => true]);
                    // no break
                default:
                    JsonError('Unknown action.', 400);
            }
        }
        catch (InvalidArgumentException $e)
        {
            JsonError($e->getMessage());
        }
    }

    public function Index()
    {
        require_once (ROOTPATH . 'modules/bookmarks') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/bookmarks') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/bookmarks') . '/../../lib/Auth.php';
        $currentUser = Auth::User();
        $isHasWorkspace = $currentUser !== null && !Auth::MustChangePassword();
        $isCanManage = $isHasWorkspace && (Permissions::Can('bookmarks', 'create') || Permissions::Can('bookmarks', 'edit') || Permissions::Can('bookmarks', 'delete'));
        $linksUrl = APP_BASE_URL . '/modules/bookmarks/index.php';
        $signInUrl = $currentUser ? APP_BASE_URL . '/change_password.php' : APP_BASE_URL . '/login.php?redirect=' . urlencode($linksUrl);
        $pageTitle = 'Access Links';
        $pageSubtitle = 'Quick links to tools, systems and shared resources';
        $activeNav = 'bookmarks';
        $bodyClass = 'workspace-page links-page' . ($isHasWorkspace ? '' : ' links-public');
        $pageStyles = ['assets/css/workspace.css', 'assets/css/quick-links.css'];
        $pageScripts = ['assets/js/workspace-ui.js'];
        $pageScript = 'assets/js/quick-links.js';
        Header('Cache-Control: no-store');
        return $this->Render('bookmarks/index', get_defined_vars());
    }
}
