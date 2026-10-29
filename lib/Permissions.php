<?php

/**
 * Filename: Permissions.php
 * Purpose: Provide permissions processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Authorize the shared approvals entry from independent work and leave review grants.
 */

use CodeIgniter\Database\BaseConnection;

/** Explicit role/action authorization. Browser controls only mirror these checks. */
require_once __DIR__.'/../config/Database.php';
class Permissions
{
    /** Temporary navigation visibility; grants and records remain intact. */
    public const HIDDEN_MODULES = ['applications','requirements'];
    public static function Visible(string $module): bool
    {
        return !in_array($module, self::HIDDEN_MODULES, true);
    }
    public const ACTIONS = ['view','create','edit','delete','export','review','credentials','support'];
    private static ?array $actor = null;
    private static ?array $matrix = null;
    private static ?bool $isDailyLogAssignment = null;

    public static function Capabilities(): array
    {
        return ['dashboard' => ['view'],'wiki' => ['view'],'updates' => ['view'],'holidays' => ['view'],'account' => ['view','edit'],'notifications' => ['view','edit'],'permissions' => ['view','edit'],
            'teammonitoring' => ['view'],'dailylogs' => ['view','create','edit','delete'],'leaves' => ['view','create','edit','delete','review'],'reviewlogs' => ['view','review'],'reports' => ['view','export'],
            'applications' => ['view','create','edit','delete'],'organization' => ['view','create','edit','delete'],
            'usermgmt' => ['view','create','edit','delete','support'],'settings' => ['view','edit'],'logs' => ['view','edit','delete','export'],'audit' => ['view','export'],
            'datadict' => ['view','create','edit','delete','export'],'diagrams' => ['view','create','edit','delete','export'],
            'requirements' => ['view','create','edit','delete','export'],'bookmarks' => ['view','create','edit','delete'],'directory' => ['view','create','edit','delete'],
            'servers' => ['view','create','edit','delete','export','credentials'],'codereview' => ['view','create','edit','export','review'],'proginventory' => ['view','export']];
    }

    public static function Actor(): array
    {
        if (self::$actor !== null)
        {
            return self::$actor;
        }
        $id = (int)($_SESSION['user_id'] ?? 0);
        if (!$id)
        {
            return self::$actor = [];
        }
        $stmtSql = 'SELECT u.user_id,u.role_key,r.role_name,u.is_active,u.is_locked FROM users u JOIN app_roles r ON r.role_key=u.role_key WHERE u.user_id=?';
        $stmt = Database::GetConnection('app')->query($stmtSql, [$id]);
        $row = $stmt->getUnbufferedRow('array');
        return self::$actor = $row && $row['is_active'] && !$row['is_locked'] ? $row : [];
    }
    public static function Reset(): void
    {
        self::$actor = null;
        self::$matrix = null;
        self::$isDailyLogAssignment = null;
    }
    public static function HasDailyLogAssignment(BaseConnection $db, int $userId): bool
    {
        $stmtSql = 'SELECT 1 FROM daily_log_reviewers r JOIN users u ON u.user_id=r.reviewer_user_id AND u.unit_id=r.unit_id WHERE u.user_id=? AND u.is_active AND NOT u.is_locked';
        $stmt = $db->query($stmtSql, [$userId]);
        return (bool)Database::Scalar($stmt);
    }
    public static function IsAdmin(): bool
    {
        return (self::Actor()['role_key'] ?? '') === 'admin';
    }
    /** Only these named head roles have the self-review exception; ordinary reviewers and administrators do not. */
    public static function HasSelfReviewRole(BaseConnection $db, int $userId, bool $isLock = false): bool
    {
        $sSql = 'SELECT r.role_key,r.role_name FROM users u JOIN app_roles r ON r.role_key=u.role_key WHERE u.user_id=? AND u.is_active AND NOT u.is_locked'.($isLock ? ' FOR SHARE OF r' : '');
        $s = $db->query($sSql, [$userId]);
        $role = $s->getUnbufferedRow('array');
        if (!$role)
        {
            return false;
        }
        foreach (['role_key','role_name'] as $field)
        {
            if (in_array(preg_replace('/[^a-z]/', '', strtolower($role[$field])), ['sectionhead','departmenthead'], true))
            {
                return true;
            }
        }
        return false;
    }
    public static function Can(string $module, string $permission = 'view'): bool
    {
        if (in_array($module, ['directory','bookmarks'], true) && $permission === 'view')
        {
            return true;
        }
        $actor = self::Actor();
        if (!$actor || !in_array($permission, self::ACTIONS, true))
        {
            return false;
        }
        if (in_array($module, ['account','notifications'], true) && in_array($permission, ['view','edit'], true))
        {
            return true;
        }
        if ($module === 'updates' && $permission === 'view')
        {
            return true;
        }
        if ($module === 'approvals' && $permission === 'view')
        {
            $db = Database::GetConnection('app');
            return self::UserCan($db, (int)$actor['user_id'], 'reviewlogs', 'view') || self::UserCan($db, (int)$actor['user_id'], 'leaves', 'review');
        }
        if ($actor['role_key'] === 'admin')
        {
            return true;
        }
        if (!in_array($permission, self::Capabilities()[$module] ?? [], true))
        {
            return false;
        }
        if ($module === 'permissions')
        {
            return false;
        }
        if ($module === 'reviewlogs')
        {
            self::$isDailyLogAssignment ??= self::HasDailyLogAssignment(Database::GetConnection('app'), (int)$actor['user_id']);
            if (!self::$isDailyLogAssignment && !self::HasSelfReviewRole(Database::GetConnection('app'), (int)$actor['user_id']))
            {
                return false;
            }
        }
        if (self::$matrix === null)
        {
            
            $stmt = Database::GetConnection('app')->table('role_permissions')->select('module,permission,is_allowed', false)->where('role_key', $actor['role_key'])->get();
            self::$matrix = [];
            foreach ($stmt->getResultArray() as $row)
            {
                self::$matrix[$row['module']][$row['permission']] = (bool)$row['is_allowed'];
            }
        }
        return !empty(self::$matrix[$module][$permission]) && ($permission === 'view' || !empty(self::$matrix[$module]['view']));
    }
    public static function UserCan(BaseConnection $db, int $userId, string $module, string $permission): bool
    {
        if ($module === 'approvals' && $permission === 'view')
        {
            return self::UserCan($db, $userId, 'reviewlogs', 'view') || self::UserCan($db, $userId, 'leaves', 'review');
        }
        $stmtSql = "SELECT u.role_key,COALESCE(p.is_allowed,FALSE) allowed,COALESCE(v.is_allowed,FALSE) visible FROM users u
            LEFT JOIN role_permissions p ON p.role_key=u.role_key AND p.module=? AND p.permission=?
            LEFT JOIN role_permissions v ON v.role_key=u.role_key AND v.module=? AND v.permission='view'
            WHERE u.user_id=? AND u.is_active AND NOT u.is_locked";
        $stmt = $db->query($stmtSql, [$module,$permission,$module,$userId]);
        $row = $stmt->getUnbufferedRow('array');
        return $row && (($module === 'updates' && $permission === 'view') || $row['role_key'] === 'admin' || ($row['allowed'] && $row['visible'] && ($module !== 'reviewlogs' || self::HasDailyLogAssignment($db, $userId) || self::HasSelfReviewRole($db, $userId))));
    }
    public static function ClientMatrix(): array
    {
        $out = [];
        foreach (Audit::Modules() as $module => $label)
        {
            if ($module !== 'authentication')
            {
                foreach (self::ACTIONS as $permission)
                {
                    $out[$module][$permission] = self::Can($module, $permission);
                }
            }
        }
        return ['role' => self::Actor()['role_key'] ?? null,'modules' => $out];
    }
    private static function Body(): array
    {
        if ($_POST)
        {
            return $_POST;
        }
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 6291456)
        {
            return [];
        }
        $data = json_decode(file_get_contents('php://input') ?: '{}', true);
        return is_array($data) ? $data : [];
    }
    public static function Operation(string $module, string $file, string $action, string $method, array $body = []): string
    {
        if ($module === 'updates')
        {
            return 'view';
        }
        if ($module === 'reviewlogs' && in_array($action, ['reviewer_assignments','save_reviewer'], true))
        {
            return 'admin';
        }
        if (in_array($action, ['save_holiday','delete_holiday'], true))
        {
            return 'admin';
        }
        if ($module === 'reviewlogs' && $action === 'review_weeks')
        {
            return 'review';
        }
        if ($module === 'dailylogs' && $action === 'save_log')
        {
            return (int)($body['log_id'] ?? 0) > 0 ? 'edit' : 'create';
        }
        if ($module === 'leaves' && $action === 'save_leave')
        {
            return (int)($body['log_id'] ?? 0) > 0 ? 'edit' : 'create';
        }
        if ($module === 'leaves' && $action === 'review_leave')
        {
            return 'review';
        }
        if ($module === 'permissions')
        {
            return 'admin';
        }
        if ($module === 'notifications')
        {
            return in_array($action, ['config','save_config','deliveries','run_due'], true) ? 'admin' : 'view';
        }
        if ($module === 'account')
        {
            return 'view';
        }
        if ($action === 'reveal_credential' || str_contains($action, 'credential') || $action === 'list_shareable_users')
        {
            return 'credentials';
        }
        if ($action === 'findings_update' || $action === 'assign_review' || $action === 'complete_review')
        {
            return 'review';
        }
        if ($file === 'history_api.php' && $action === 'add_note')
        {
            return 'review';
        }
        if (str_contains($file, 'export') || str_contains($action, 'export') || $file === 'download.php' || $file === 'rdp_download.php')
        {
            return 'export';
        }
        if ($action === 'scan')
        {
            return 'create';
        }
        if (str_starts_with($action, 'delete') || str_starts_with($action, 'remove') || $action === 'clear_log')
        {
            return 'delete';
        }
        if (str_starts_with($action, 'save_'))
        {
            if (in_array($action, ['save_settings','save_layout','save_config','save_permissions'], true))
            {
                return 'edit';
            }
            $ids = ['user' => 'user_id','group' => 'group_id','division' => 'division_id','department' => 'department_id','unit' => 'unit_id','position' => 'position_id',
                'server_group' => 'server_group_id','server' => 'server_id','hosted_system' => 'hosted_system_id','group_system' => 'group_system_id','protocol' => 'protocol_id',
                'expiry_item' => 'expiry_id','table' => 'table_id','column' => 'column_id','connection' => 'connection_id','diagram' => 'diagram_id','entity' => 'node_key',
                'relationship' => 'edge_id','bookmark' => 'bookmark_id','requirement' => 'requirement_id','application' => 'application_id'];
            $id = $ids[substr($action, 5)] ?? null;
            if ($id === 'node_key')
            {
                return !empty($body[$id]) ? 'edit' : 'create';
            }
            return $id && (int)($body[$id] ?? 0) > 0 ? 'edit' : 'create';
        }
        if (str_starts_with($action, 'add_') || str_starts_with($action, 'import') || in_array($action, ['generate_dictionary','excel_import','import_inventory'], true) || $file === 'excel_import.php')
        {
            return 'create';
        }
        if ($action === 'update_run' || $action === 'set_user_active' || $action === 'unlock_user' || $action === 'reset_password' || $action === 'test_log_entry' || $action === 'send_test_email')
        {
            return 'edit';
        }
        return $method === 'GET' ? 'view' : 'edit';
    }
    public static function EnforceRoute(bool $isJson): void
    {
        if (PHP_SAPI === 'cli')
        {
            return;
        }
        $route = Audit::Route($_SERVER['REQUEST_URI'] ?? '');
        if (!$route)
        {
            self::Deny($isJson);
        }
        if ($route['file'] === 'auth_email_preview.php')
        {
            if (!self::Can('usermgmt', 'support'))
            {
                self::Deny($isJson);
            }
            return;
        }
        if ($route['module'] === 'authentication')
        {
            return;
        }
        $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : (is_string($_POST['action'] ?? null) ? $_POST['action'] : '');
        $permission = self::Operation($route['module'], $route['file'], $action, $_SERVER['REQUEST_METHOD'] ?? 'GET', str_starts_with($action, 'save_') ? self::Body() : []);
        if ($route['module'] === 'diagrams' && $action === 'save_entity')
        {
            $body = self::Body();
            $key = $body['node_key'] ?? '';
            if (is_string($key) && $key !== '')
            {
                
                $stmt = Database::GetConnection('app')->table('diagram_nodes')->select('1', false)->where('diagram_id', (int)($body['diagram_id'] ?? 0))->where('node_key', $key)->get();
                $permission = Database::Scalar($stmt) ? 'edit' : 'create';
            }
        }
        if ($permission === 'admin' ? !self::IsAdmin() : !self::Can($route['module'], $permission))
        {
            self::Deny($isJson);
        }
    }
    public static function Require(string $module, string $permission = 'view'): void
    {
        if (!self::Can($module, $permission))
        {
            self::Deny(true);
        }
    }
    public static function Deny(bool $isJson): never
    {
        Audit::Result('denied');
        http_response_code(403);
        Header('Cache-Control: no-store');
        if ($isJson)
        {
            Header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Your role does not allow this action. Contact an administrator if you need access.']);
        }
        else
        {
            Header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="en"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access restricted</title><link rel="stylesheet" href="'.APP_BASE_URL.'/assets/css/app.css"><main class="content"><div class="card"><h1>Access restricted</h1><p>Your role does not allow this page. Contact an administrator if you need access.</p><a class="btn" href="'.APP_BASE_URL.'/index.php">Back to dashboard</a> <a class="btn" href="'.APP_BASE_URL.'/logout.php">Sign out</a></div></main></html>';
        }
        \FinishRequest();
    }
    /** Must be called inside the account change transaction. */
    public static function GuardAccount(BaseConnection $db, int $id, ?string $role = null, ?bool $isActive = null, bool $isDelete = false): void
    {
        if (!Database::InTransaction($db))
        {
            throw new LogicException('Account changes require a transaction.');
        }
        $db->query('SELECT pg_advisory_xact_lock(17382018)');
        
        $stmt = $db->query($db->table('users')->select('role_key,is_active', false)->where('user_id', $id)->getCompiledSelect() . ' FOR UPDATE');
        $old = $stmt->getUnbufferedRow('array');
        if (!$old)
        {
            throw new OutOfBoundsException('User not found.');
        }
        if ($old['role_key'] === 'admin' && !self::IsAdmin())
        {
            throw new InvalidArgumentException('Only Administrators can manage Administrator accounts.');
        }
        if ($role !== null && $role !== $old['role_key'] && !self::IsAdmin())
        {
            throw new InvalidArgumentException('Only administrators can change account roles.');
        }
        if ($old['role_key'] === 'admin' && $old['is_active'] && ($isDelete || $isActive === false || ($role !== null && $role !== 'admin')))
        {
            if ((int)Database::Scalar($db->table('users')->select('COUNT(*)', false)->where('role_key', 'admin')->where('is_active', true)->get()) <= 1)
            {
                throw new InvalidArgumentException('Keep at least one active Administrator account.');
            }
        }
    }
}
