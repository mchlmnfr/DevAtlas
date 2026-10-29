<?php

/**
 * Filename: custom-roles.php
 * Purpose: Verify custom roles behavior using isolated regression fixtures.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\Exceptions\DatabaseException;

/** Custom role lifecycle, upgrade preservation and authorization in a disposable schema. */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
session_set_save_handler(static fn () => true, static fn () => true, static fn () => '', static fn () => true, static fn () => true, static fn () => 0);
require_once __DIR__.'/../lib/Roles.php';
set_exception_handler(static function (Throwable $e): void
{
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
});
set_error_handler(static function ($severity, $message, $file, $line)
{
    if (error_reporting() & $severity)
    {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
$db = Database::GetConnection('app');
$schema = 'roles_fixture_'.bin2hex(random_bytes(6));
$checks = 0;
function CrCheck(bool $isOk, string $message): void
{
    global $checks;
    $checks++;
    if (!$isOk)
    {
        throw new RuntimeException($message);
    }
}
function CrReject(callable $fn, string $message): void
{
    try
    {
        $fn();
    }
    catch (InvalidArgumentException|OutOfBoundsException|DomainException|DatabaseException $e)
    {
        CrCheck(true, $message);
        return;
    }
    CrCheck(false, $message);
}
function CrSnapshot(): string
{
    global $db;
    return hash('sha256', json_encode([$db->query('SELECT * FROM users ORDER BY user_id')->getResultArray(),$db->query('SELECT role_key,role_name FROM app_roles ORDER BY role_key')->getResultArray(),$db->query('SELECT * FROM role_permissions ORDER BY permission_id')->getResultArray()]));
}
try
{
    $full = file_get_contents(__DIR__.'/../database/schema.sql');
    $base = substr($full, 0, strpos($full, '-- Custom access roles.'));
    $db->query(str_replace('toolkit', $schema, $base));
    $db->query('SET search_path TO "'.$schema.'",public');
    CrCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM role_permissions WHERE role_key IN('analyst','reviewer','viewer') AND module IN('applications','requirements') AND is_allowed")) === 0, 'Applications and Requirements are unchecked for non-Administrator preset roles by default.');
    CrCheck((int)Database::Scalar($db->query("SELECT COUNT(*) FROM role_permissions WHERE role_key='admin' AND module IN('applications','requirements') AND NOT is_allowed")) === 0, 'Administrator access remains complete.');
    $db->query("UPDATE role_permissions SET is_allowed=TRUE WHERE role_key='viewer' AND module='datadict' AND permission='export'");
    $before = CrSnapshot();
    $migration = str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/migration_019_custom_roles.sql'));
    $db->query($migration);
    $db->query($migration);
    CrCheck($before === CrSnapshot(), 'Upgrade preserves every existing account, role name and configured permission.');
    CrCheck(count(Roles::Catalog($db)) === 4 && count(array_filter(Roles::Catalog($db), fn ($r) => $r['is_system'])) === 4, 'All four presets are protected.');
    $admin = (int)Database::Scalar($db->query("SELECT user_id FROM users WHERE role_key='admin' LIMIT 1"));
    $_SESSION = ['user_id' => $admin,'employee_id' => 'admin','full_name' => 'Role fixture'];
    Permissions::Reset();
    $_SERVER['REQUEST_URI'] = APP_BASE_URL.'/modules/permissions/api.php?action=create_role';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['action' => 'create_role'];
    $_POST = [];
    Audit::Begin(true);
    Audit::Attach($db);
    Audit::Input(['role_key' => 'support','description' => 'PRIVATE_DESCRIPTION_SENTINEL']);
    CrCheck(Roles::Create($db, ['role_key' => 'support','role_name' => 'Support specialist','description' => 'PRIVATE_DESCRIPTION_SENTINEL']) === 'support', 'Custom role creation succeeds.');
    Audit::Response(['ok' => true,'role_key' => 'support']);
    Audit::Finish();
    $event = $db->query("SELECT * FROM audit_events WHERE module='permissions' ORDER BY event_id DESC LIMIT 1")->getUnbufferedRow('array');
    $changes = $db->query("SELECT * FROM audit_changes WHERE table_name='app_roles' ORDER BY change_id")->getResultArray();
    CrCheck(json_decode($event['details'], true)['role_key'] === 'support' && count($changes) === 1 && $changes[0]['record_id'] === 'support', 'Custom role creation records its actor and stable key in the audit trail.');
    CrCheck(!str_contains(json_encode([$event,$changes]), 'PRIVATE_DESCRIPTION_SENTINEL'), 'Audit metadata excludes free-text descriptions.');
    $db->query("INSERT INTO users(employee_id,password_hash,first_name,last_name,role_key)VALUES('support-user','fixture hash','Support','User',?)", ['support']);
    $user = (int)Database::Scalar($db->query("SELECT user_id FROM users WHERE employee_id='support-user'"));
    $_SESSION['user_id'] = $user;
    Permissions::Reset();
    CrCheck(Permissions::Actor()['role_name'] === 'Support specialist' && Permissions::Can('dashboard'), 'Custom role has a display name and minimal dashboard access.');
    CrCheck(!Permissions::Can('applications') && !Permissions::Can('servers', 'credentials') && !Permissions::IsAdmin(), 'Minimal roles inherit no business or sensitive access.');
    CrCheck(Permissions::Can('account', 'edit') && Permissions::Can('notifications', 'view') && Permissions::Can('directory'), 'Personal tools and public viewing remain available.');
    CrReject(fn () => Roles::Delete($db, 'support'), 'An assigned custom role cannot be deleted.');
    Roles::SavePermissions($db, ['role_key' => 'support','grants' => [
        ['module' => 'applications','permission' => 'view','is_allowed' => true],['module' => 'applications','permission' => 'create','is_allowed' => true],
        ['module' => 'servers','permission' => 'credentials','is_allowed' => true],['module' => 'permissions','permission' => 'view','is_allowed' => true],
        ['module' => 'account','permission' => 'edit','is_allowed' => false],['module' => 'directory','permission' => 'view','is_allowed' => false]]]);
    Permissions::Reset();
    CrCheck(Permissions::Can('applications', 'create') && Permissions::UserCan($db, $user, 'applications', 'create'), 'Configured custom permissions apply to browser and reminder checks.');
    CrCheck(!Permissions::Can('servers', 'credentials') && !Permissions::Can('permissions'), 'Sensitive actions require module view; custom roles cannot administer roles.');
    CrCheck(Permissions::Can('account', 'edit') && Permissions::Can('directory'), 'Mandatory personal and public rights cannot be removed.');
    Roles::SavePermissions($db, ['role_key' => 'support','grants' => [['module' => 'applications','permission' => 'view','is_allowed' => false]]]);
    Permissions::Reset();
    CrCheck(!Permissions::Can('applications', 'create') && !Permissions::UserCan($db, $user, 'applications', 'create'), 'Revoking view immediately removes module actions.');
    $db->transBegin();
    CrReject(fn () => Permissions::GuardAccount($db, $user, 'admin'), 'Custom roles cannot promote themselves.');
    $db->transRollback();
    Roles::Update($db, ['role_key' => 'support','role_name' => 'Service desk','description' => 'New purpose']);
    Permissions::Reset();
    CrCheck(Permissions::Actor()['role_name'] === 'Service desk', 'Renaming keeps assignments and updates account display.');
    $entry = array_values(array_filter(Roles::Catalog($db), fn ($r) => $r['role_key'] === 'support'))[0];
    CrCheck((int)$entry['user_count'] === 1 && !$entry['is_system'], 'Catalog shows custom status and assigned user count.');
    Roles::Create($db, ['role_key' => 'copy_viewer','role_name' => 'Custom viewer','copy_from' => 'viewer']);
    $stmt = $db->query("SELECT is_allowed FROM role_permissions WHERE role_key='copy_viewer' AND module='datadict' AND permission='export'");
    CrCheck((bool)Database::Scalar($stmt), 'Copying includes the source role’s configured permissions.');
    Roles::SavePermissions($db, ['role_key' => 'viewer','grants' => [['module' => 'datadict','permission' => 'export','is_allowed' => false]]]);
    CrCheck((bool)Database::Scalar($db->query("SELECT is_allowed FROM role_permissions WHERE role_key='copy_viewer' AND module='datadict' AND permission='export'")), 'Copies are independent of later source changes.');
    foreach (['admin','analyst','reviewer','viewer'] as $key)
    {
        CrReject(fn () => Roles::Delete($db, $key), 'Preset role cannot be deleted.');
        CrReject(fn () => Roles::Update($db, ['role_key' => $key,'role_name' => 'Renamed']), 'Preset role name cannot be changed.');
    }
    CrReject(fn () => Roles::SavePermissions($db, ['role_key' => 'admin','grants' => []]), 'Administrator grants cannot be edited.');
    CrReject(fn () => Roles::Create($db, ['role_key' => 'other_admin','role_name' => 'Other admin','copy_from' => 'admin']), 'Implicit Administrator access cannot be cloned.');
    CrReject(fn () => Roles::Create($db, ['role_key' => 'support','role_name' => 'Duplicate key']), 'Duplicate stable keys are rejected.');
    CrReject(fn () => Roles::Create($db, ['role_key' => 'duplicate_name','role_name' => '  SERVICE DESK  ']), 'Names are unique regardless of case and surrounding whitespace.');
    foreach (['Bad Key','9start','way_too_long_role_key_123',[],null] as $key)
    {
        CrReject(fn () => Roles::Create($db, ['role_key' => $key,'role_name' => 'Invalid']), 'Malformed role keys are rejected.');
    }
    CrReject(fn () => Roles::Create($db, ['role_key' => 'blank','role_name' => '  ']), 'Blank role names are rejected.');
    CrReject(fn () => Roles::Create($db, ['role_key' => 'bad_source','role_name' => 'Bad source','copy_from' => 'missing']), 'Missing source role is rejected.');
    CrCheck(!(bool)Database::Scalar($db->query("SELECT 1 FROM app_roles WHERE role_key='bad_source'")), 'Failed copies leave no partial role.');
    CrReject(fn () => Roles::SavePermissions($db, ['role_key' => 'support','grants' => [['module' => 'applications','permission' => 'view','is_allowed' => true],['module' => 'unknown','permission' => 'view','is_allowed' => true]]]), 'Invalid permission batches are rejected atomically.');
    Permissions::Reset();
    CrCheck(!Permissions::Can('applications'), 'Failed permission batch rolls back earlier grants.');
    $perms = $db->query("SELECT * FROM role_permissions WHERE role_key='support' ORDER BY permission_id")->getResultArray();
    $db->query(str_replace('toolkit', $schema, file_get_contents(__DIR__.'/../database/migration_018_workflow.sql')));
    $db->query($migration);
    CrCheck($perms === $db->query("SELECT * FROM role_permissions WHERE role_key='support' ORDER BY permission_id")->getResultArray(), 'Reapplying older and current migrations does not expand custom permissions.');
    Roles::Delete($db, 'copy_viewer');
    CrCheck(!(bool)Database::Scalar($db->query("SELECT 1 FROM role_permissions WHERE role_key='copy_viewer'")), 'Deleting an unassigned role removes its grants.');
    $db->query("UPDATE users SET role_key='analyst' WHERE user_id=?", [$user]);
    Roles::Delete($db, 'support');
    CrCheck(!(bool)Database::Scalar($db->query("SELECT 1 FROM app_roles WHERE role_key='support'")), 'Reassigned roles can be deleted.');
    echo "Custom role fixtures: {$checks} assertions passed.\n";
}
finally
{
    if (Database::InTransaction($db))
    {
        $db->transRollback();
    }
    $db->query("SELECT set_config('analyst.audit_context','',false)");
    $db->query('SET search_path TO public');
    $db->query('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
    Permissions::Reset();
}
