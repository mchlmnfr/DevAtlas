<?php
/**
 * Filename: UsermgmtController.php
 * Purpose: Handle Usermgmt routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Auth;
use Database;
use DateTimeImmutable;
use DateTimeZone;
use EmailAuth;
use InvalidArgumentException;
use Mailer;
use OutOfBoundsException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Permissions;

class UsermgmtController extends BaseController
{
    public function Api()
    {
        require_once APPPATH . 'Helpers/usermgmt_helper.php';
        /** User account administration. Organization lookups are owned by modules/organization/api.php. */
        require_once (ROOTPATH . 'modules/usermgmt') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/usermgmt') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/usermgmt') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/usermgmt') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/usermgmt') . '/../../lib/Mailer.php';
        require_once (ROOTPATH . 'modules/usermgmt') . '/../../lib/EmailAuth.php';

        Auth::RequireApi();
        $db = Database::GetConnection('app');
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        if (in_array($action, ['list_users', 'get_user', 'list_roles'], true))
        {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET')
            {
                JsonError('GET required.', 405);
            }
        }
        else
        {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            {
                JsonError('POST required.', 405);
            }
            RequireCsrf();
        }






        switch ($action)
        {
            case 'list_roles': JsonResponse($db->table('app_roles')->select('role_key,role_name', false)->orderBy('LOWER(role_name),role_key', '', false)->get()->getResultArray());
                // no break
            case 'list_users':
                {
                    $search = CleanStr($_GET['q'] ?? null);
                    $status = (string)($_GET['status'] ?? 'all');
                    if ($search !== null && mb_strlen($search) > 100)
                    {
                        JsonError('Search is too long.');
                    }
                    if (!in_array($status, ['all', 'active', 'disabled', 'locked', 'password'], true))
                    {
                        JsonError('Invalid status filter.');
                    }
                    $sql = "SELECT u.user_id, u.employee_id, u.last_name, u.first_name, u.middle_name, u.email, u.is_active,
                               u.role_key, r.role_name, u.must_change_password, u.is_locked, u.failed_login_attempts,
                               g.group_name, div.division_name, dep.department_name, un.unit_name, pos.position_title
                        FROM users u
                        JOIN app_roles r ON r.role_key=u.role_key
                        LEFT JOIN org_groups g ON g.group_id = u.group_id
                        LEFT JOIN org_divisions div ON div.division_id = u.division_id
                        LEFT JOIN org_departments dep ON dep.department_id = u.department_id
                        LEFT JOIN org_units un ON un.unit_id = u.unit_id
                        LEFT JOIN org_positions pos ON pos.position_id = u.position_id
                        WHERE 1=1";
                    $params = [];
                    if ($search)
                    {
                        $sql .= " AND (u.employee_id ILIKE :employee_search: OR u.last_name ILIKE :last_search: OR u.first_name ILIKE :first_search: OR u.email ILIKE :email_search:)";
                        foreach (['employee_search', 'last_search', 'first_search', 'email_search'] as $key)
                        {
                            $params[":" . $key] = "%$search%";
                        }
                    }
                    if ($status === 'active')
                    {
                        $sql .= " AND u.is_active = TRUE AND u.is_locked = FALSE";
                    }
                    elseif ($status === 'disabled')
                    {
                        $sql .= " AND u.is_active = FALSE";
                    }
                    elseif ($status === 'locked')
                    {
                        $sql .= " AND u.is_locked = TRUE";
                    }
                    elseif ($status === 'password')
                    {
                        $sql .= " AND u.must_change_password = TRUE";
                    }
                    $sql .= " ORDER BY u.last_name, u.first_name, u.employee_id";
                    $stmtSql = $sql;
                    $stmt = $db->query($stmtSql, $params);
                    JsonResponse($stmt->getResultArray());
                }

            case 'get_user':
                {
                    $id = (int)($_GET['user_id'] ?? 0);
                    
                    $stmt = $db->table('users')->select('user_id, employee_id, last_name, first_name, middle_name, email, birthday, is_active, group_id, division_id, department_id, unit_id, position_id, role_key, must_change_password, is_locked, failed_login_attempts', false)->where('user_id', $id)->get();
                    $user = $stmt->getUnbufferedRow('array');
                    if (!$user)
                    {
                        JsonError('User not found', 404);
                    }
                    JsonResponse($user);
                }

            case 'save_user':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['user_id'] ?? 0);

                    $fields = [
                        'employee_id' => CleanStr($data['employee_id'] ?? null),
                        'last_name' => CleanStr($data['last_name'] ?? null),
                        'first_name' => CleanStr($data['first_name'] ?? null),
                        'middle_name' => CleanStr($data['middle_name'] ?? null),
                        'email' => CleanStr($data['email'] ?? null),
                        'birthday' => UserBirthday($data['birthday'] ?? null),
                        'is_active' => PgBool($data['is_active'] ?? true),
                    ];
                    $fields = array_merge($fields, ValidateUserPlacement($db, $data));
                    if ($id && !array_key_exists('birthday', $data))
                    {
                        
                        $s = $db->table('users')->select('birthday', false)->where('user_id', $id)->get();
                        $fields['birthday'] = Database::Scalar($s) ?: null;
                    }
                    if (!$fields['employee_id'] || !$fields['last_name'] || !$fields['first_name'])
                    {
                        JsonError('Employee ID, last name and first name are required.');
                    }
                    if (mb_strlen($fields['employee_id']) > 50 || mb_strlen($fields['last_name']) > 100 ||
                        mb_strlen($fields['first_name']) > 100 || mb_strlen($fields['middle_name'] ?? '') > 100)
                    {
                        JsonError('Employee ID or name is too long.');
                    }
                    if ($fields['email'] !== null && (mb_strlen($fields['email']) > 200 || !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)))
                    {
                        JsonError('Enter a valid email address.');
                    }
                    if ($id && (int)($_SESSION['user_id'] ?? 0) === $id && $fields['is_active'] === 'false')
                    {
                        JsonError('You cannot disable your own account.');
                    }

                    $role = $data['role_key'] ?? null;
                    if ($role !== null)
                    {
                        if (!is_string($role) || !preg_match('/^[a-z][a-z0-9_]{0,19}$/D', $role))
                        {
                            JsonError('Choose a valid account role.', 422);
                        }
                        
                        $roleStmt = $db->table('app_roles')->select('1', false)->where('role_key', $role)->get();
                        if (!Database::Scalar($roleStmt))
                        {
                            JsonError('This role no longer exists. Refresh the form and try again.', 422);
                        }
                    }
                    if ($id)
                    {
                        
                        $roleStmt = $db->table('users')->select('role_key', false)->where('user_id', $id)->get();
                        $previousRole = Database::Scalar($roleStmt);
                        $role = $role ?? ($previousRole ?: 'analyst');
                    }
                    else
                    {
                        $role = $role ?? 'analyst';
                        if ($role !== 'analyst' && !Permissions::IsAdmin())
                        {
                            Permissions::Deny(true);
                        }
                    }
                    $fields['role_key'] = $role;
                    $emailResult = null;

                    try
                    {
                        $db->transBegin();
                        if ($id)
                        {
                            GuardUserChange($db, $id, $role, $fields['is_active'] === 'true');
                            $sql = "UPDATE users SET employee_id=:employee_id:, last_name=:last_name:, first_name=:first_name:,
                                middle_name=:middle_name:, group_id=:group_id:, division_id=:division_id:, department_id=:department_id:,
                                unit_id=:unit_id:, position_id=:position_id:,birthday=:birthday:,
                                auth_version=auth_version + CASE WHEN email IS DISTINCT FROM :version_email: OR is_active IS DISTINCT FROM CAST(:version_active: AS BOOLEAN)
                                    OR employee_id IS DISTINCT FROM :version_employee: OR role_key IS DISTINCT FROM :version_role: THEN 1 ELSE 0 END,
                                email=:email:, is_active=:is_active:, role_key=:role_key: WHERE user_id=:id: RETURNING auth_version";
                            $fields['id'] = $id;
                            $fields['version_email'] = $fields['email'];
                            $fields['version_active'] = $fields['is_active'];
                            $fields['version_employee'] = $fields['employee_id'];
                            $fields['version_role'] = $fields['role_key'];
                            $stmtSql = $sql;
                            $stmt = $db->query($stmtSql, $fields);
                            $version = (int)Database::Scalar($stmt);
                            if ($db->affectedRows() === 0)
                            {
                                JsonError('User not found.', 404);
                            }
                        }
                        else
                        {
                            // Custom password is optional on creation â€” fall back to the
                            // org-wide Default Password from Parameters if none was typed.
                            $password = (string)($data['password'] ?? '');
                            if ($password === '')
                            {
                                $settingsStmt = $db->table('system_settings')->select('default_password', false)->where('id', 1)->get();
                                $password = Database::Scalar($settingsStmt) ?: 'Welcome@12345';
                            }
                            elseif (strlen($password) < 8)
                            {
                                JsonError('Password must be at least 8 characters.');
                            }
                            $fields['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
                            $fields['must_change_password'] = PgBool(true);
                            // always forced on first login
                            $stmt = $db->query($db->table('users')->set($fields)->getCompiledInsert() . ' RETURNING user_id');
                            $id = Database::Scalar($stmt);

                            $db->transCommit();
                            // Best-effort welcome email â€” never blocks account creation if SMTP isn't set up.
                            if ($fields['email'])
                            {
                                $emailResult = Mailer::SendAccountCreatedEmail(
                                    ['employee_id' => $fields['employee_id'], 'first_name' => $fields['first_name'], 'last_name' => $fields['last_name'], 'email' => $fields['email']],
                                    $password
                                );
                            }
                        }
                        if (Database::InTransaction($db))
                        {
                            $db->transCommit();
                        }
                    }
                    catch (DatabaseException $e)
                    {
                        if (Database::InTransaction($db))
                        {
                            $db->transRollback();
                        }
                        if (Database::SqlState($e) === '23505')
                        {
                            JsonError('This Employee ID is already in use.');
                        }
                        if (Database::SqlState($e) === '23503')
                        {
                            JsonError('The selected role or organization item no longer exists. Refresh the form and try again.', 409);
                        }
                        throw $e;
                    }

                    $response = ['ok' => true, 'user_id' => $id];
                    if ($id && (int)($_SESSION['user_id'] ?? 0) === (int)$id)
                    {
                        if (isset($version))
                        {
                            $_SESSION['auth_version'] = $version;
                        }
                        $_SESSION['employee_id'] = $fields['employee_id'];
                        $_SESSION['full_name'] = trim($fields['first_name'] . ' ' . $fields['last_name']);
                        Auth::RefreshProfileCache($db, (int)$id);
                    }
                    if ($emailResult !== null)
                    {
                        $response['email_sent'] = $emailResult['ok'];
                        if (!$emailResult['ok'])
                        {
                            $response['email_error'] = $emailResult['error'];
                        }
                    }
                    JsonResponse($response);
                }

            case 'reset_password':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['user_id'] ?? 0);
                    $password = (string)($data['password'] ?? '');
                    if (!$id)
                    {
                        JsonError('user_id is required.');
                    }
                    if (EmailAuth::PasswordError($password, $password))
                    {
                        JsonError(EmailAuth::PasswordError($password, $password));
                    }
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    // A manual reset also forces the user to pick their own password at next login.
                    $db->transBegin();
                    GuardUserChange($db, $id);
                    
                    $stmt = $db->table('users')->set('password_hash', $hash)->set('must_change_password', true)->set('password_changed_at', null)->set('auth_version', new \CodeIgniter\Database\RawSql('auth_version+1'))->where('user_id', $id)->update();
                    if ($db->affectedRows() === 0)
                    {
                        $db->transRollback();
                        JsonError('User not found.', 404);
                    }
                    EmailAuth::Revoke($db, $id);
                    $db->transCommit();
                    JsonResponse(['ok' => true]);
                }

            case 'unlock_user':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['user_id'] ?? 0);
                    if (!$id)
                    {
                        JsonError('user_id is required.');
                    }
                    $db->transBegin();
                    GuardUserChange($db, $id);
                    
                    $stmt = $db->table('users')->set('is_locked', false)->set('failed_login_attempts', 0)->set('locked_at', null)->set('auth_version', new \CodeIgniter\Database\RawSql('auth_version+1'))->where('user_id', $id)->update();
                    if ($db->affectedRows() === 0)
                    {
                        JsonError('User not found.', 404);
                    }
                    $db->transCommit();
                    JsonResponse(['ok' => true]);
                }

            case 'set_user_active':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['user_id'] ?? 0);
                    if ($id <= 0 || !array_key_exists('is_active', $data))
                    {
                        JsonError('User and account status are required.');
                    }
                    $active = ToBool($data['is_active']);
                    if (!$active && (int)($_SESSION['user_id'] ?? 0) === $id)
                    {
                        JsonError('You cannot disable your own account.');
                    }
                    $db->transBegin();
                    GuardUserChange($db, $id, null, $active);
                    
                    $stmt = $db->table('users')->set('is_active', PgBool($active))->set('auth_version', new \CodeIgniter\Database\RawSql('auth_version + 1'))->where('user_id', $id)->update();
                    if ($db->affectedRows() === 0)
                    {
                        JsonError('User not found.', 404);
                    }
                    $db->transCommit();
                    JsonResponse(['ok' => true, 'is_active' => $active]);
                }

            case 'delete_user':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['user_id'] ?? 0);
                    // Guard against locking yourself out entirely by deleting the account you're logged in as.
                    if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $id)
                    {
                        JsonError('You cannot delete the account you are currently logged in as.');
                    }
                    $db->transBegin();
                    GuardUserChange($db, $id, null, null, true);
                    try
                    {
                        
                        $stmt = $db->table('users')->where('user_id', $id)->delete();
                        if ($db->affectedRows() === 0)
                        {
                            JsonError('User not found.', 404);
                        }
                    }
                    catch (DatabaseException $e)
                    {
                        if (Database::SqlState($e) === '23503')
                        {
                            JsonError('This user is still referenced by another record. Disable the account instead.', 409);
                        }
                        throw $e;
                    }
                    $db->transCommit();
                    JsonResponse(['ok' => true]);
                }

            default:
                JsonError('Unknown action.', 400);
        }
    }

    public function Index()
    {
        require_once APPPATH . 'Helpers/usermgmt_helper.php';
        $pageTitle = 'Users';
        $pageSubtitle = 'People, account status and access';
        $activeNav = 'usermgmt';
        $bodyClass = 'workspace-page users-page';
        $pageStyles = ['assets/css/workspace.css', 'assets/css/users.css'];
        $pageScripts = ['assets/js/workspace-ui.js'];
        $pageScript = 'assets/js/users.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('usermgmt/index', get_defined_vars());
    }
}
