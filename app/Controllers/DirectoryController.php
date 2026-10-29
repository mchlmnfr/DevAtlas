<?php
/**
 * Filename: DirectoryController.php
 * Purpose: Handle Directory routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Directory;


use Throwable;

use Auth;
use Database;
use CodeIgniter\Database\BaseConnection;
use Permissions;

class DirectoryController extends BaseController
{
    public function Api()
    {
        require_once APPPATH . 'Helpers/directory_helper.php';
        /** Public department contact reads; authenticated, CSRF-protected contact maintenance. */
        require_once (ROOTPATH . 'modules/directory') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/directory') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/directory') . '/../../lib/helpers.php';

        Header('Cache-Control: no-store');
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        if (!is_string($action))
        {
            JsonError('Choose a valid action.');
        }
        $readActions = ['list_departments', 'get_department'];
        $writeActions = ['add_email', 'update_email', 'delete_email', 'add_number', 'update_number', 'delete_number'];
        if (in_array($action, $readActions, true))
        {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET')
            {
                Header('Allow: GET');
                JsonError('GET required.', 405);
            }
        }
        elseif (in_array($action, $writeActions, true))
        {
            Auth::RequireApi();
            if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            {
                Header('Allow: POST');
                JsonError('POST required.', 405);
            }
            RequireCsrf();
        }
        else
        {
            JsonError('Unknown action.', 400);
        }

        $db = Database::GetConnection('app');







        /** Three queries for the entire directory, rather than one query per department. */


        if ($action === 'list_departments')
        {
            $id = isset($_GET['department_id']) ? DirectoryId($_GET['department_id'], 'department') : null;
            $search = isset($_GET['q']) && $_GET['q'] !== '' ? DirectoryText($_GET['q'], 'Search', 200) : null;
            JsonResponse(DirectoryDepartments($db, $id, $search));
        }
        if ($action === 'get_department')
        {
            $rows = DirectoryDepartments($db, DirectoryId($_GET['department_id'] ?? null, 'department'));
            if (!$rows)
            {
                JsonError('Department not found.', 404);
            }
            JsonResponse($rows[0]);
        }

        $data = ReadJsonBody();
        $departmentId = DirectoryId($data['department_id'] ?? null, 'department');
        $isEmail = str_ends_with($action, '_email');
        $isDelete = str_starts_with($action, 'delete_');
        $isUpdate = str_starts_with($action, 'update_');
        $idField = $isEmail ? 'email_id' : 'number_id';
        $contactId = $isDelete || $isUpdate ? DirectoryId($data[$idField] ?? null, 'contact') : 0;
        $email = $number = $label = null;
        if (!$isDelete && $isEmail)
        {
            $email = DirectoryText($data['email'] ?? null, 'Email', 200);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            {
                JsonError('Enter a valid email address.');
            }
        }
        elseif (!$isDelete)
        {
            $number = DirectoryText($data['phone_number'] ?? null, 'Phone number', 50);
            if (!preg_match('/\d/', $number) || !preg_match('/^[0-9+().#*,;xX\s-]+$/', $number))
            {
                JsonError('Use digits and phone formatting characters, such as +, spaces, brackets, or an extension.');
            }
            $label = DirectoryText($data['label'] ?? 'Local', 'Number label', 50);
        }

        $db->transBegin();
        try
        {
            // Serialize changes per department so simultaneous additions cannot create duplicates.
            
            $parent = $db->query($db->table('org_departments')->select('department_id', false)->where('department_id', $departmentId)->getCompiledSelect() . ' FOR UPDATE');
            if (!Database::Scalar($parent))
            {
                $db->transRollback();
                JsonError('This department no longer exists. Refresh the directory.', 404);
            }
            $table = $isEmail ? 'department_emails' : 'department_numbers';
            if (!$isDelete)
            {
                if ($isEmail)
                {
                    $duplicateSql = 'SELECT 1 FROM department_emails WHERE department_id=? AND LOWER(email)=LOWER(?) AND email_id<>?';
                    $duplicate = $db->query($duplicateSql, [$departmentId, $email, $contactId]);
                }
                else
                {
                    $duplicateSql = "SELECT 1 FROM department_numbers WHERE department_id=?
                        AND REGEXP_REPLACE(LOWER(phone_number), '[[:space:]().-]', '', 'g') = REGEXP_REPLACE(LOWER(?), '[[:space:]().-]', '', 'g')
                        AND LOWER(label)=LOWER(?) AND number_id<>?";
                    $duplicate = $db->query($duplicateSql, [$departmentId, $number, $label, $contactId]);
                }
                if (Database::Scalar($duplicate))
                {
                    $db->transRollback();
                    JsonError('This contact is already listed for the department.', 409);
                }
            }
            if ($isDelete)
            {
                $statementSql = "DELETE FROM {$table} WHERE {$idField}=? AND department_id=?";
                $statement = $db->query($statementSql, [$contactId, $departmentId]);
            }
            elseif ($isUpdate && $isEmail)
            {
                
                $statement = $db->table('department_emails')->set('email', $email)->where('email_id', $contactId)->where('department_id', $departmentId)->update();
            }
            elseif ($isUpdate)
            {
                
                $statement = $db->table('department_numbers')->set('phone_number', $number)->set('label', $label)->where('number_id', $contactId)->where('department_id', $departmentId)->update();
            }
            elseif ($isEmail)
            {
                
                $statement = $db->query($db->table('department_emails')->set('department_id', $departmentId)->set('email', $email)->getCompiledInsert() . ' RETURNING email_id');
                $contactId = (int)Database::Scalar($statement);
            }
            else
            {
                
                $statement = $db->query($db->table('department_numbers')->set('department_id', $departmentId)->set('phone_number', $number)->set('label', $label)->getCompiledInsert() . ' RETURNING number_id');
                $contactId = (int)Database::Scalar($statement);
            }
            if (($isDelete || $isUpdate) && $db->affectedRows() === 0)
            {
                $db->transRollback();
                JsonError('This contact no longer exists in the selected department.', 404);
            }
            $db->transCommit();
        }
        catch (Throwable $error)
        {
            if ($error instanceof \App\Http\ResponseComplete)
            {
                throw $error;
            }
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw $error;
        }
        JsonResponse(['ok' => true, $idField => $contactId, 'department' => DirectoryDepartments($db, $departmentId)[0]]);
    }

    public function Index()
    {
        require_once APPPATH . 'Helpers/directory_helper.php';
        require_once (ROOTPATH . 'modules/directory') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/directory') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/directory') . '/../../lib/Auth.php';

        $currentUser = Auth::User();
        $isHasWorkspace = $currentUser !== null && !Auth::MustChangePassword();
        $isCanManage = $isHasWorkspace && (Permissions::Can('directory', 'create') || Permissions::Can('directory', 'edit') || Permissions::Can('directory', 'delete'));
        $directoryUrl = APP_BASE_URL . '/modules/directory/index.php';
        $signInUrl = $currentUser ? APP_BASE_URL . '/change_password.php' : APP_BASE_URL . '/login.php?redirect=' . urlencode($directoryUrl);
        $pageTitle = 'Call Directory';
        $pageSubtitle = 'Shared department emails, hotlines, and extensions';
        $activeNav = 'directory';
        $bodyClass = 'directory-page';
        $pageScript = 'assets/js/directory.js';
        Header('Cache-Control: private, no-store');

        return $this->Render('directory/index', get_defined_vars());
    }
}
