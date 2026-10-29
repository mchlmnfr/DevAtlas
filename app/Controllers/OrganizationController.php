<?php
/**
 * Filename: OrganizationController.php
 * Purpose: Handle Organization routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Auth;
use Database;
use Directory;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

class OrganizationController extends BaseController
{
    public function Api()
    {
        require_once APPPATH . 'Helpers/organization_helper.php';
        /**
         * modules/organization/api.php
         * JSON API for Organization â€” the single owner of the organizational
         * structure (Group â†’ Division â†’ Department â†’ Unit â†’ Position).
         * Call Directory (modules/directory/) only reads
         * Departments from here to attach shared contact info; it no longer
         * creates/edits/deletes structure itself.
         */
        require_once (ROOTPATH . 'modules/organization') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/organization') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/organization') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/organization') . '/../../lib/helpers.php';

        Auth::RequireApi();
        // every action below requires a logged-in session

        $db = Database::GetConnection('app');
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        $readActions = ['tree', 'list_groups', 'list_divisions', 'list_departments', 'list_units', 'list_positions'];
        if (in_array($action, $readActions, true))
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

            /**
             * Full 5-level tree in exactly 5 queries total (groups, divisions,
             * departments, units, positions), assembled in PHP â€” avoids the
             * classic N+1 pattern of querying one level down per parent row.
             */
            case 'tree':
                {
                    $groups = $db->table('org_groups')->orderBy('group_name', '', false)->get()->getResultArray();
                    $divisions = $db->table('org_divisions')->orderBy('division_name', '', false)->get()->getResultArray();
                    $departments = $db->table('org_departments')->orderBy('department_name', '', false)->get()->getResultArray();
                    $units = $db->table('org_units')->orderBy('unit_name', '', false)->get()->getResultArray();
                    $positions = $db->table('org_positions')->orderBy('position_title', '', false)->get()->getResultArray();

                    $positionsByUnit = [];
                    foreach ($positions as $p)
                    {
                        $positionsByUnit[$p['unit_id']][] = $p;
                    }

                    $unitsByDept = [];
                    foreach ($units as $u)
                    {
                        $u['positions'] = $positionsByUnit[$u['unit_id']] ?? [];
                        $unitsByDept[$u['department_id']][] = $u;
                    }

                    $deptsByDiv = [];
                    foreach ($departments as $d)
                    {
                        $d['units'] = $unitsByDept[$d['department_id']] ?? [];
                        $deptsByDiv[$d['division_id']][] = $d;
                    }

                    $divsByGroup = [];
                    foreach ($divisions as $div)
                    {
                        $div['departments'] = $deptsByDiv[$div['division_id']] ?? [];
                        $divsByGroup[$div['group_id']][] = $div;
                    }

                    foreach ($groups as &$g)
                    {
                        $g['divisions'] = $divsByGroup[$g['group_id']] ?? [];
                    }
                    JsonResponse($groups);
                }

                // ---------------------------------------------------------------
                // Group maintenance
                // ---------------------------------------------------------------

            case 'save_group':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['group_id'] ?? 0);
                    $name = OrgName($data['group_name'] ?? null, 'Group name', 150);
                    $desc = CleanStr($data['description'] ?? null);
                    try
                    {
                        if ($id)
                        {
                            
                            $stmt = $db->table('org_groups')->set('group_name', $name)->set('description', $desc)->where('group_id', $id)->update();
                            if ($db->affectedRows() === 0)
                            {
                                JsonError('Group not found.', 404);
                            }
                        }
                        else
                        {
                            
                            $stmt = $db->query($db->table('org_groups')->set('group_name', $name)->set('description', $desc)->getCompiledInsert() . ' RETURNING group_id');
                            $id = Database::Scalar($stmt);
                        }
                    }
                    catch (DatabaseException $e)
                    {
                        if (Database::SqlState($e) === '23505')
                        {
                            JsonError('A group with this name already exists.');
                        }
                        throw $e;
                    }
                    JsonResponse(['ok' => true,'group_id' => $id]);
                }
            case 'delete_group':
                {
                    $data = ReadJsonBody();
                    DeleteOrgNode(
                        $db,
                        'org_groups',
                        'group_id',
                        (int)($data['group_id'] ?? 0),
                        [['org_divisions', 'group_id', 'divisions'], ['users', 'group_id', 'users']]
                    );
                    JsonResponse(['ok' => true]);
                }

                // ---------------------------------------------------------------
                // Division maintenance
                // ---------------------------------------------------------------

            case 'save_division':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['division_id'] ?? 0);
                    $groupId = (int)($data['group_id'] ?? 0);
                    $name = OrgName($data['division_name'] ?? null, 'Division name', 150);
                    if (!$groupId)
                    {
                        JsonError('Choose a group.');
                    }
                    $desc = CleanStr($data['description'] ?? null);
                    try
                    {
                        if ($id)
                        {
                            
                            $stmt = $db->table('org_divisions')->set('division_name', $name)->set('description', $desc)->where('division_id', $id)->update();
                            if ($db->affectedRows() === 0)
                            {
                                JsonError('Division not found.', 404);
                            }
                        }
                        else
                        {
                            
                            $stmt = $db->query($db->table('org_divisions')->set('group_id', $groupId)->set('division_name', $name)->set('description', $desc)->getCompiledInsert() . ' RETURNING division_id');
                            $id = Database::Scalar($stmt);
                        }
                    }
                    catch (DatabaseException $e)
                    {
                        if (Database::SqlState($e) === '23505')
                        {
                            JsonError('A division with this name already exists in this group.');
                        }
                        if (Database::SqlState($e) === '23503')
                        {
                            JsonError('The selected group no longer exists.', 409);
                        }
                        throw $e;
                    }
                    JsonResponse(['ok' => true,'division_id' => $id]);
                }
            case 'delete_division':
                {
                    $data = ReadJsonBody();
                    DeleteOrgNode(
                        $db,
                        'org_divisions',
                        'division_id',
                        (int)($data['division_id'] ?? 0),
                        [['org_departments', 'division_id', 'departments'], ['users', 'division_id', 'users']]
                    );
                    JsonResponse(['ok' => true]);
                }

                // ---------------------------------------------------------------
                // Department maintenance (structure only â€” contact info lives in
                // Call Directory / modules/directory/api.php)
                // ---------------------------------------------------------------

            case 'save_department':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['department_id'] ?? 0);
                    $divisionId = (int)($data['division_id'] ?? 0);
                    $name = OrgName($data['department_name'] ?? null, 'Department name', 200);
                    if (!$divisionId)
                    {
                        JsonError('Choose a division.');
                    }
                    $desc = CleanStr($data['description'] ?? null);
                    try
                    {
                        if ($id)
                        {
                            
                            $stmt = $db->table('org_departments')->set('department_name', $name)->set('description', $desc)->where('department_id', $id)->update();
                            if ($db->affectedRows() === 0)
                            {
                                JsonError('Department not found.', 404);
                            }
                        }
                        else
                        {
                            
                            $stmt = $db->query($db->table('org_departments')->set('division_id', $divisionId)->set('department_name', $name)->set('description', $desc)->getCompiledInsert() . ' RETURNING department_id');
                            $id = Database::Scalar($stmt);
                        }
                    }
                    catch (DatabaseException $e)
                    {
                        if (Database::SqlState($e) === '23505')
                        {
                            JsonError('A department with this name already exists in this division.');
                        }
                        if (Database::SqlState($e) === '23503')
                        {
                            JsonError('The selected division no longer exists.', 409);
                        }
                        throw $e;
                    }
                    JsonResponse(['ok' => true,'department_id' => $id]);
                }
            case 'delete_department':
                {
                    $data = ReadJsonBody();
                    DeleteOrgNode(
                        $db,
                        'org_departments',
                        'department_id',
                        (int)($data['department_id'] ?? 0),
                        [['org_units', 'department_id', 'units'], ['users', 'department_id', 'users'],
                        ['department_emails', 'department_id', 'department emails'], ['department_numbers', 'department_id', 'department phone numbers']]
                    );
                    JsonResponse(['ok' => true]);
                }

                // ---------------------------------------------------------------
                // Cascading lookups (read-only) for the User form
                // ---------------------------------------------------------------

            case 'list_groups':
                {
                    JsonResponse($db->table('org_groups')->select('group_id, group_name', false)->orderBy('group_name', '', false)->get()->getResultArray());
                }
            case 'list_divisions':
                {
                    $groupId = (int)($_GET['group_id'] ?? 0);
                    
                    $stmt = $db->table('org_divisions')->select('division_id, division_name', false)->where('group_id', $groupId)->orderBy('division_name', '', false)->get();
                    JsonResponse($stmt->getResultArray());
                }
            case 'list_departments':
                {
                    $divisionId = (int)($_GET['division_id'] ?? 0);
                    
                    $stmt = $db->table('org_departments')->select('department_id, department_name', false)->where('division_id', $divisionId)->orderBy('department_name', '', false)->get();
                    JsonResponse($stmt->getResultArray());
                }
            case 'list_units':
                {
                    $departmentId = (int)($_GET['department_id'] ?? 0);
                    
                    $stmt = $db->table('org_units')->select('unit_id, unit_name, description', false)->where('department_id', $departmentId)->orderBy('unit_name', '', false)->get();
                    JsonResponse($stmt->getResultArray());
                }
            case 'list_positions':
                {
                    $unitId = (int)($_GET['unit_id'] ?? 0);
                    
                    $stmt = $db->table('org_positions')->select('position_id, position_title, description', false)->where('unit_id', $unitId)->orderBy('position_title', '', false)->get();
                    JsonResponse($stmt->getResultArray());
                }

                // ---------------------------------------------------------------
                // Unit maintenance
                // ---------------------------------------------------------------

            case 'save_unit':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['unit_id'] ?? 0);
                    $departmentId = (int)($data['department_id'] ?? 0);
                    $name = OrgName($data['unit_name'] ?? null, 'Unit name', 150);
                    if (!$departmentId)
                    {
                        JsonError('Choose a department.');
                    }
                    $desc = CleanStr($data['description'] ?? null);
                    try
                    {
                        if ($id)
                        {
                            
                            $stmt = $db->table('org_units')->set('unit_name', $name)->set('description', $desc)->where('unit_id', $id)->update();
                            if ($db->affectedRows() === 0)
                            {
                                JsonError('Unit not found.', 404);
                            }
                        }
                        else
                        {
                            
                            $stmt = $db->query($db->table('org_units')->set('department_id', $departmentId)->set('unit_name', $name)->set('description', $desc)->getCompiledInsert() . ' RETURNING unit_id');
                            $id = Database::Scalar($stmt);
                        }
                    }
                    catch (DatabaseException $e)
                    {
                        if (Database::SqlState($e) === '23505')
                        {
                            JsonError('A unit with this name already exists in this department.');
                        }
                        if (Database::SqlState($e) === '23503')
                        {
                            JsonError('The selected department no longer exists.', 409);
                        }
                        throw $e;
                    }
                    JsonResponse(['ok' => true,'unit_id' => $id]);
                }
            case 'delete_unit':
                {
                    $data = ReadJsonBody();
                    DeleteOrgNode(
                        $db,
                        'org_units',
                        'unit_id',
                        (int)($data['unit_id'] ?? 0),
                        [['org_positions', 'unit_id', 'positions'], ['users', 'unit_id', 'users']]
                    );
                    JsonResponse(['ok' => true]);
                }

                // ---------------------------------------------------------------
                // Position maintenance
                // ---------------------------------------------------------------

            case 'save_position':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['position_id'] ?? 0);
                    $unitId = (int)($data['unit_id'] ?? 0);
                    $title = OrgName($data['position_title'] ?? null, 'Position title', 150);
                    if (!$unitId)
                    {
                        JsonError('Choose a unit.');
                    }
                    $desc = CleanStr($data['description'] ?? null);
                    try
                    {
                        if ($id)
                        {
                            
                            $stmt = $db->table('org_positions')->set('position_title', $title)->set('description', $desc)->where('position_id', $id)->update();
                            if ($db->affectedRows() === 0)
                            {
                                JsonError('Position not found.', 404);
                            }
                        }
                        else
                        {
                            
                            $stmt = $db->query($db->table('org_positions')->set('unit_id', $unitId)->set('position_title', $title)->set('description', $desc)->getCompiledInsert() . ' RETURNING position_id');
                            $id = Database::Scalar($stmt);
                        }
                    }
                    catch (DatabaseException $e)
                    {
                        if (Database::SqlState($e) === '23505')
                        {
                            JsonError('A position with this title already exists in this unit.');
                        }
                        if (Database::SqlState($e) === '23503')
                        {
                            JsonError('The selected unit no longer exists.', 409);
                        }
                        throw $e;
                    }
                    JsonResponse(['ok' => true,'position_id' => $id]);
                }
            case 'delete_position':
                {
                    $data = ReadJsonBody();
                    DeleteOrgNode(
                        $db,
                        'org_positions',
                        'position_id',
                        (int)($data['position_id'] ?? 0),
                        [['users', 'position_id', 'users']]
                    );
                    JsonResponse(['ok' => true]);
                }

            default:
                JsonError('Unknown action.', 400);
        }
    }

    public function Index()
    {
        require_once APPPATH . 'Helpers/organization_helper.php';
        $pageTitle = 'Organization';
        $pageSubtitle = 'Structure, teams and positions';
        $activeNav = 'organization';
        $bodyClass = 'workspace-page organization-page';
        $pageStyles = ['assets/css/workspace.css', 'assets/css/organization.css'];
        $pageScripts = ['assets/js/workspace-ui.js'];
        $pageScript = 'assets/js/organization.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('organization/index', get_defined_vars());
    }
}
