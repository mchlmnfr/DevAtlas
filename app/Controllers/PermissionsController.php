<?php
/**
 * Filename: PermissionsController.php
 * Purpose: Handle Permissions routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Permissions;


use Audit;
use Auth;
use Database;
use DomainException;
use InvalidArgumentException;
use OutOfBoundsException;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Roles;

class PermissionsController extends BaseController
{
    public function Api()
    {
        require_once (ROOTPATH . 'modules/permissions').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/permissions').'/../../lib/Permissions.php';
        require_once (ROOTPATH . 'modules/permissions').'/../../lib/Roles.php';
        require_once (ROOTPATH . 'modules/permissions').'/../../lib/helpers.php';
        Auth::RequireApi();
        if (!Permissions::IsAdmin())
        {
            Permissions::Deny(true);
        }
        Header('Cache-Control: no-store');
        $db = Database::GetConnection('app');
        $action = $_GET['action'] ?? 'matrix';
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'matrix')
        {
            JsonResponse(['roles' => Roles::Catalog($db),'modules' => Audit::Modules(),'actions' => Permissions::ACTIONS,'capabilities' => Permissions::Capabilities(),'hidden_modules' => Permissions::HIDDEN_MODULES,'permissions' => $db->table('role_permissions')->select('role_key,module,permission,is_allowed', false)->orderBy('role_key,module,permission', '', false)->get()->getResultArray()]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            JsonError('POST required.', 405);
        }
        RequireCsrf();
        $data = ReadJsonBody();
        try
        {
            switch ($action)
            {
                case 'create_role': $key = Roles::Create($db, $data);
                    JsonResponse(['ok' => true,'role_key' => $key]);
                    // no break
                case 'update_role': Roles::Update($db, $data);
                    break;
                case 'delete_role': Roles::Delete($db, $data['role_key'] ?? '');
                    break;
                case 'save_permissions': Roles::SavePermissions($db, $data);
                    break;
                default: throw new InvalidArgumentException('Unknown action.');
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
        catch (DatabaseException $e)
        {
            if (Database::SqlState($e) === '23505')
            {
                JsonError('A role with this key or name already exists. Choose a different one.', 409);
            }
            if (Database::SqlState($e) === '23503')
            {
                JsonError('This role is in use or no longer exists. Refresh and try again.', 409);
            }
            throw $e;
        }
    }

    public function Index()
    {
        $pageTitle = 'Roles & Permissions';
        $pageSubtitle = 'Create roles and control access to modules and sensitive actions';
        $activeNav = 'permissions';
        $bodyClass = 'workspace-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/workflows.css'];
        $pageScripts = ['assets/js/workspace-ui.js'];
        $pageScript = 'assets/js/permissions.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('permissions/index', get_defined_vars());
    }
}
