<?php
/**
 * Filename: UpdatesController.php
 * Purpose: Handle Updates routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Auth;
use Database;
use DomainException;
use SystemUpdates;

class UpdatesController extends BaseController
{
    public function Api()
    {
        require_once (ROOTPATH . 'modules/updates').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/updates').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/updates').'/../../lib/SystemUpdates.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        $action = $_GET['action'] ?? '';
        if (!in_array($action, ['notice','history','acknowledge'], true))
        {
            JsonError('Unknown action.', 404);
        }
        $isRead = $action !== 'acknowledge';
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
            if ($action === 'notice')
            {
                JsonResponse(SystemUpdates::Notice($db, $actor));
            }
            if ($action === 'history')
            {
                JsonResponse(['releases' => SystemUpdates::Releases()]);
            }
            SystemUpdates::Acknowledge($db, $actor, ReadJsonBody());
            JsonResponse(['ok' => true]);
        }
        catch (DomainException $e)
        {
            JsonError($e->getMessage(), 409);
        }
    }
}
