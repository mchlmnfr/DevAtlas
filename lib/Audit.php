<?php

/**
 * Filename: Audit.php
 * Purpose: Provide audit processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Recognize separate leave applications and the unified approvals workspace.
 */

use CodeIgniter\Database\BaseConnection;

/** Request and browser audit context. Only explicitly allowed metadata is retained. */
class Audit
{
    private static bool $isActive = false;
    private static bool $isFinished = false;
    private static ?BaseConnection $db = null;
    private static array $context = [];
    private static ?string $outcome = null;
    private static float $started = 0;
    public static function Modules(): array
    {
        return ['updates' => "What's New",'wiki' => 'Documents Wiki','holidays' => 'Holidays','reports' => 'Reports','teammonitoring' => 'Team Monitoring','leaves' => 'Leaves','dailylogs' => 'My Daily Logs','approvals' => 'Approvals','reviewlogs' => 'Daily Logs Approval','applications' => 'Application Catalog','notifications' => 'Notifications','permissions' => 'Roles & Permissions','dashboard' => 'Dashboard','authentication' => 'Authentication','account' => 'Account',
            'datadict' => 'Data Dictionary','diagrams' => 'ERD Generator','codereview' => 'Code Review','requirements' => 'Requirements',
            'organization' => 'Organization','usermgmt' => 'Users','settings' => 'Parameters','servers' => 'Server Inventory',
            'proginventory' => 'Program Inventory','directory' => 'Call Directory','bookmarks' => 'Quick Links','logs' => 'Error Logs','audit' => 'Audit Logs'];
    }
    public static function Uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
    public static function Route(string $uri): ?array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, APP_BASE_URL.'/'))
        {
            return null;
        }
        $relative = RouteUrls::LegacyPath(substr($path, strlen(APP_BASE_URL) + 1));
        if ($relative === '')
        {
            $relative = 'index.php';
        }
        if (preg_match('#^modules/([a-z]+)/$#D', $relative))
        {
            $relative .= 'index.php';
        }
        if (preg_match('#^modules/([a-z]+)/([a-z_]+\.php)$#D', $relative, $parts) && isset(self::Modules()[$parts[1]]))
        {
            return ['module' => $parts[1],'file' => $parts[2],'path' => $path];
        }
        $roots = ['index.php' => 'dashboard','login.php' => 'authentication','logout.php' => 'authentication','forgot_password.php' => 'authentication',
            'reset_password.php' => 'authentication','change_password.php' => 'account','auth_email_preview.php' => 'authentication'];
        return isset($roots[$relative]) ? ['module' => $roots[$relative],'file' => $relative,'path' => $path] : null;
    }
    private static function Action(array $route, string $method): string
    {
        $file = $route['file'];
        $submitted = $_GET['action'] ?? $_POST['action'] ?? '';
        if ($file === 'api.php' || str_ends_with($file, '_api.php'))
        {
            return is_string($submitted) && preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $submitted) ? $submitted : 'unknown_action';
        }
        if ($file === 'login.php' && $method === 'POST')
        {
            return match ($submitted)
            {
                'request_code' => 'request_signin_code','verify_code' => 'signin_code','change_account' => 'change_signin_account',default => 'signin_password'
            };
        }
        if ($file === 'logout.php')
        {
            return 'sign_out';
        }
        if ($file === 'auth_email_preview.php')
        {
            return 'view_protected_email';
        }
        if ($file === 'change_password.php' && $method === 'POST')
        {
            return 'change_password';
        }
        if ($file === 'forgot_password.php' && $method === 'POST')
        {
            return 'request_password_reset';
        }
        if ($file === 'reset_password.php' && $method === 'POST')
        {
            return 'reset_password';
        }
        if ($file === 'upload_avatar.php')
        {
            return 'upload_avatar';
        }
        if ($file === 'index.php' || in_array($file, ['login.php','forgot_password.php','reset_password.php','change_password.php'], true))
        {
            return 'view_page';
        }
        return substr($file, 0, -4);
    }
    public static function Begin(bool $isForce = false): void
    {
        if (self::$isActive || (!$isForce && PHP_SAPI === 'cli'))
        {
            return;
        }
        $route = self::Route($_SERVER['REQUEST_URI'] ?? '');
        if (!$route || ($route['module'] === 'audit' && $route['file'] === 'client.php'))
        {
            return;
        }
        self::$isActive = true;
        self::$isFinished = false;
        self::$started = microtime(true);
        self::$context = ['request_id' => self::Uuid(),'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),'actor_id' => null,
            'actor_employee_id' => null,'actor_name' => null,'session_ref' => null,'module' => $route['module'],
            'action' => self::Action($route, $_SERVER['REQUEST_METHOD'] ?? 'GET'),'http_method' => substr($_SERVER['REQUEST_METHOD'] ?? 'GET', 0, 12),
            'path' => $route['path'],'ip_address' => filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: null,'details' => []];
        self::IdentifySession();
        self::Input($_GET);
        self::Input($_POST);
        register_shutdown_function([self::class,'finish']);
    }
    private static function IdentifySession(): void
    {
        if (!self::$isActive || empty($_SESSION['user_id']))
        {
            return;
        }
        self::$context['actor_id'] = (int)$_SESSION['user_id'];
        self::$context['actor_employee_id'] = mb_substr((string)($_SESSION['employee_id'] ?? ''), 0, 50);
        self::$context['actor_name'] = mb_substr((string)($_SESSION['full_name'] ?? $_SESSION['employee_id'] ?? ''), 0, 200);
        self::$context['session_ref'] = substr(hash_hmac('sha256', session_id(), APP_ENCRYPTION_KEY), 0, 24);
    }
    public static function Identify(): void
    {
        self::IdentifySession();
        if (self::$db && self::$isActive)
        {
            self::Attach(self::$db);
        }
    }
    public static function Attach(BaseConnection $db): void
    {
        if (!self::$isActive || self::$isFinished)
        {
            return;
        }
        self::$db = $db;
        $db->query("SELECT set_config('analyst.audit_context',?,false)", [json_encode(self::$context, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)]);
    }
    public static function Input(array $data): void
    {
        if (!self::$isActive)
        {
            return;
        }
        foreach (['reviewer_user_id','log_id','user_id','table_id','column_id','diagram_id','bookmark_id','group_id','division_id','department_id','unit_id','position_id',
            'server_id','server_group_id','hosted_system_id','group_system_id','credential_id','protocol_id','expiry_id','share_id','requirement_id','link_id','connection_id','application_id','review_id','finding_id','asset_link_id','notification_id','assigned_to','owner_user_id','technical_owner_id'] as $key)
        {
            if (isset($data[$key]) && is_scalar($data[$key]) && preg_match('/^[1-9][0-9]{0,17}$/D', (string)$data[$key]))
            {
                self::$context['details'][$key] = (string)$data[$key];
            }
        }
        if (in_array(self::$context['module'], ['permissions','usermgmt'], true) && isset($data['role_key']) && is_string($data['role_key']) && preg_match('/^[a-z][a-z0-9_]{0,19}$/D', $data['role_key']))
        {
            self::$context['details']['role_key'] = $data['role_key'];
        }
        foreach (['mode' => ['client','server','code','password'],'engine' => ['standard','security','qms','inventory','owasp'],
            'section' => ['policy','smtp','recovery','all'],'qms_scope' => ['existing','new','modified']] as $key => $allowed)
        {
            if (isset($data[$key]) && in_array($data[$key], $allowed, true))
            {
                self::$context['details'][$key] = $data[$key];
            }
        }
        if (self::$db)
        {
            self::Attach(self::$db);
        }
    }
    public static function Response($data): void
    {
        if (!self::$isActive || !is_array($data))
        {
            return;
        }
        self::Input($data);
        foreach (['file_count','finding_count','function_count','node_count','edge_count','imported','skipped'] as $key)
        {
            if (isset($data[$key]) && is_numeric($data[$key]) && $data[$key] >= 0)
            {
                self::$context['details'][$key] = (int)$data[$key];
            }
        }
        if (($data['ok'] ?? null) === false)
        {
            self::Result('failed');
        }
        if (!empty($data['errors']) && is_array($data['errors']))
        {
            self::$context['details']['error_count'] = count($data['errors']);
            self::Result(!empty($data['imported']) ? 'partial' : 'failed');
        }
        // Bulk imports may return HTTP 200 with a mix of successful and rejected objects.
        if (isset($data['results']) && is_array($data['results']))
        {
            $succeeded = 0;
            $failed = 0;
            foreach ($data['results'] as $item)
            {
                if (is_array($item))
                {
                    if (($item['ok'] ?? null) === true)
                    {
                        $succeeded++;
                    }
                    if (($item['ok'] ?? null) === false)
                    {
                        $failed++;
                    }
                }
            }
            if ($failed)
            {
                self::$context['details']['error_count'] = $failed;
                self::Result($succeeded ? 'partial' : 'failed');
            }
        }
    }
    public static function Result(string $outcome): void
    {
        if (self::$isActive && in_array($outcome, ['success','failed','denied','partial'], true))
        {
            self::$outcome = $outcome;
        }
    }
    public static function Context(): array
    {
        return self::$context;
    }
    /** Finish after output/exit; changes left in an unfinished transaction are not reported as committed. */
    public static function Finish(): void
    {
        if (!self::$isActive || self::$isFinished)
        {
            return;
        }
        self::$isFinished = true;
        try
        {
            if (!class_exists('Database', false))
            {
                require_once __DIR__.'/../config/Database.php';
            }
            $db = self::$db ?? Database::GetConnection('app');
            if (Database::InTransaction($db))
            {
                $db->transRollback();
                self::$outcome = 'failed';
                self::$context['details']['uncommitted_transaction'] = true;
            }
            self::IdentifySession();
            $status = http_response_code() ?: 200;
            $last = error_get_last();
            if ($last && in_array($last['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true))
            {
                $status = 500;
            }
            $outcome = self::$outcome ?? (in_array($status, [401,403,419], true) ? 'denied' : ($status >= 400 ? 'failed' : 'success'));
            $ctx = self::$context;
            $db->query("INSERT INTO audit_events(request_id,occurred_at,source,actor_id,actor_employee_id,actor_name,session_ref,module,action,outcome,http_method,path,ip_address,http_status,duration_ms,details,finalized_at)
                VALUES(?,?,'server',?,?,?,?,?,?,?,?,?,?,?,?,CAST(? AS JSONB),clock_timestamp())
                ON CONFLICT(request_id) DO UPDATE SET actor_id=EXCLUDED.actor_id,actor_employee_id=EXCLUDED.actor_employee_id,
                actor_name=EXCLUDED.actor_name,session_ref=EXCLUDED.session_ref,outcome=EXCLUDED.outcome,http_status=EXCLUDED.http_status,
                duration_ms=EXCLUDED.duration_ms,details=EXCLUDED.details,finalized_at=EXCLUDED.finalized_at", [$ctx['request_id'],$ctx['occurred_at'],$ctx['actor_id'],$ctx['actor_employee_id'],$ctx['actor_name'],$ctx['session_ref'],
                    $ctx['module'],$ctx['action'],$outcome,$ctx['http_method'],$ctx['path'],$ctx['ip_address'],$status,
                    min(2147483647, (int)round((microtime(true) - self::$started) * 1000)),json_encode((object)$ctx['details'], JSON_THROW_ON_ERROR)]);
            $db->query("SELECT set_config('analyst.audit_context','',false)");
        }
        catch (Throwable $e)
        {
            if (isset($db) && Database::InTransaction($db))
            {
                $db->transRollback();
            }
            Logger::Log('error', 'Audit event could not be finalized.', ['request_id' => self::$context['request_id'] ?? null,'error_class' => get_class($e),'code' => (string)$e->getCode()]);
        }
    }
    public static function ActionLabels(): array
    {
        return ['view_page' => 'Viewed page','signin_password' => 'Signed in with password','signin_code' => 'Signed in with email code',
            'request_signin_code' => 'Requested sign-in code','request_password_reset' => 'Requested password reset','sign_out' => 'Signed out',
            'view_protected_email' => 'Viewed protected authentication email','generate_dictionary' => 'Generated ERD from dictionary',
            'scan' => 'Reviewed source code','export_client' => 'Exported browser inventory','export' => 'Exported report',
            'excel_export' => 'Exported Excel','excel_import' => 'Imported Excel','graphml_export' => 'Exported GraphML','rdp_download' => 'Downloaded RDP connection',
            'reveal_credential' => 'Revealed vault credential','metadata_export' => 'Exported dictionary metadata','upload_avatar' => 'Updated profile photo',
            'create_role' => 'Created custom role','update_role' => 'Updated role details','delete_role' => 'Deleted custom role','save_permissions' => 'Updated role permissions'];
    }
    public static function ActionLabel(string $action): string
    {
        return self::ActionLabels()[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }
    public static function ControlLabel(string $control): string
    {
        $names = ['sidebarToggle' => 'Sidebar labels','moduleSearch' => 'Module search','userMenuTrigger' => 'Account menu',
            'avatarUploadButton' => 'Profile photo','crScanBtn' => 'Review files','crChooseFolder' => 'Choose source folder',
            'erdGenerateOpen' => 'Generate ERD','erdGenerateSubmit' => 'Generate ERD','linkAdd' => 'New quick link',
            'qms' => 'QMS CodeSniffer','standard' => 'Standard diagnostics','security' => 'Security patterns',
            'inventory' => 'Function inventory','light' => 'Light appearance','dark' => 'Dark appearance'];
        if (isset($names[$control]))
        {
            return $names[$control];
        }
        $text = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $control);
        return ucfirst(trim(str_replace(['_','-'], ' ', $text)));
    }
    public static function ClientEvents(BaseConnection $db, array $payload, array $actor): int
    {
        $route = self::Route(is_string($payload['page'] ?? null) ? $payload['page'] : '');
        $events = $payload['events'] ?? null;
        if (!$route || !is_array($events) || !array_is_list($events) || count($events) > 40)
        {
            throw new InvalidArgumentException('Invalid browser activity batch.');
        }
        $validated = [];
        foreach ($events as $event)
        {
            if (!is_array($event) || !is_string($event['id'] ?? null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $event['id']) || !in_array($event['kind'] ?? '', ['click','change','submit','search','copy'], true)
                || !is_string($event['control'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,79}$/D', $event['control']))
            {
                throw new InvalidArgumentException('Invalid browser activity.');
            }
            $validated[] = $event;
        }
        $count = 0;
        $db->transBegin();
        try
        {
            $stmtSql = "INSERT INTO audit_events(request_id,source,actor_id,actor_employee_id,actor_name,module,action,outcome,path,ip_address,details,finalized_at)
                VALUES(?,'browser',?,?,?,?,?,'reported',?,?,CAST(? AS JSONB),clock_timestamp()) ON CONFLICT(request_id) DO NOTHING";
            foreach ($validated as $event)
            {
                $details = ['control' => $event['control']];
                $stmt = $db->query($stmtSql, [$event['id'],(int)$actor['user_id'],mb_substr($actor['employee_id'], 0, 50),mb_substr($actor['full_name'], 0, 200),
                    $route['module'],'ui.'.$event['kind'],$route['path'],filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: null,json_encode($details)]);
                $count += $db->affectedRows();
            }
            $db->transCommit();
            return $count;
        }
        catch (Throwable $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            throw $e;
        }
    }
}
