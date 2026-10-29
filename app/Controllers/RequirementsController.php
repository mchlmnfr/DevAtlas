<?php
/**
 * Filename: RequirementsController.php
 * Purpose: Handle Requirements routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Auth;
use Database;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Permissions;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RequirementsController extends BaseController
{
    public function Api()
    {
        require_once (ROOTPATH . 'modules/requirements') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/requirements') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/requirements') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/requirements') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/requirements') . '/../../lib/Traceability.php';

        Auth::RequireApi();
        if ($_SERVER['REQUEST_METHOD'] !== 'GET')
        {
            RequireCsrf();
        }
        $db = Database::GetConnection('app');
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        $sources = TraceabilityAssetSources();

        switch ($action)
        {
            case 'list_requirements':
                {
                    $q = CleanStr($_GET['q'] ?? null);
                    $status = CleanStr($_GET['status'] ?? null);
                    if ($status !== null && !in_array($status, ['Draft', 'Approved', 'Implemented', 'Retired'], true))
                    {
                        JsonError('Invalid status.');
                    }
                    $sql = "SELECT r.*, COALESCE(u.first_name || ' ' || u.last_name, '') AS owner_name,
                               (SELECT COUNT(*) FROM requirement_links rl WHERE rl.requirement_id=r.requirement_id) AS link_count
                        FROM requirements r LEFT JOIN users u ON u.user_id=r.owner_user_id WHERE 1=1";
                    $params = [];
                    if ($q !== null)
                    {
                        $sql .= " AND (r.requirement_code ILIKE :q1: OR r.title ILIKE :q2: OR r.description ILIKE :q3:)";
                        $params = ['q1' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%"];
                    }
                    if ($status !== null)
                    {
                        $sql .= ' AND r.status=:status:';
                        $params[':status'] = $status;
                    }
                    $sql .= ' ORDER BY r.updated_at DESC, r.requirement_id DESC';
                    $stmtSql = $sql;
                    $stmt = $db->query($stmtSql, $params);
                    JsonResponse($stmt->getResultArray());
                }

            case 'get_requirement':
                {
                    $id = (int)($_GET['id'] ?? 0);
                    if ($id <= 0)
                    {
                        JsonError('Requirement ID is required.');
                    }
                    
                    $stmt = $db->table('requirements')->where('requirement_id', $id)->get();
                    $requirement = $stmt->getUnbufferedRow('array');
                    if (!$requirement)
                    {
                        JsonError('Requirement not found.', 404);
                    }
                    $stmtSql = TraceabilityLinksSql() . ' WHERE rl.requirement_id=:id: ORDER BY asset_type, asset_label';
                    $stmt = $db->query($stmtSql, ['id' => $id]);
                    JsonResponse(['requirement' => $requirement, 'links' => array_values(array_filter($stmt->getResultArray(), static fn ($link) => TraceabilityCanViewType($link['asset_type'])))]);
                }

            case 'list_owners':
                {
                    JsonResponse($db->table('users')->select('user_id, employee_id, first_name || \' \' || last_name AS name, is_active', false)->orderBy('last_name, first_name, employee_id', '', false)->get()->getResultArray());
                }

            case 'list_assets':
                {
                    $type = (string)($_GET['type'] ?? '');
                    if (!isset($sources[$type]))
                    {
                        JsonError('Invalid asset type.');
                    }
                    if (!TraceabilityCanViewType($type))
                    {
                        Permissions::Deny(true);
                    }
                    JsonResponse($db->query($sources[$type]['sql'])->getResultArray());
                }

            case 'save_requirement':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['requirement_id'] ?? 0);
                    $code = strtoupper((string)(CleanStr($data['requirement_code'] ?? null) ?? ''));
                    $title = CleanStr($data['title'] ?? null);
                    $description = CleanStr($data['description'] ?? null);
                    $status = (string)($data['status'] ?? 'Draft');
                    $priority = (string)($data['priority'] ?? 'Medium');
                    $owner = ($data['owner_user_id'] ?? '') === '' || ($data['owner_user_id'] ?? null) === null ? null : (int)$data['owner_user_id'];
                    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $code))
                    {
                        JsonError('ID must be 1â€“40 letters, numbers, dots, dashes, or underscores.');
                    }
                    if (!$title || mb_strlen($title) > 200)
                    {
                        JsonError('Title is required and must be at most 200 characters.');
                    }
                    if (!in_array($status, ['Draft', 'Approved', 'Implemented', 'Retired'], true))
                    {
                        JsonError('Invalid status.');
                    }
                    if (!in_array($priority, ['Low', 'Medium', 'High', 'Critical'], true))
                    {
                        JsonError('Invalid priority.');
                    }
                    if ($owner !== null)
                    {
                        if ($owner <= 0)
                        {
                            JsonError('Invalid owner.');
                        }
                        
                        $check = $db->table('users')->select('1', false)->where('user_id', $owner)->get();
                        if (!Database::Scalar($check))
                        {
                            JsonError('Owner not found.');
                        }
                    }
                    $fields = ['code' => $code, 'title' => $title, 'description' => $description,
                                'status' => $status, 'priority' => $priority, 'owner' => $owner];
                    try
                    {
                        if ($id > 0)
                        {
                            
                            $fields[':id'] = $id;
                            $stmt = $db->table('requirements')->set('requirement_code', ($fields)['code'])->set('title', ($fields)['title'])->set('description', ($fields)['description'])->set('status', ($fields)['status'])->set('priority', ($fields)['priority'])->set('owner_user_id', ($fields)['owner'])->where('requirement_id', ($fields)['id'])->update();
                            if ($db->affectedRows() === 0)
                            {
                                JsonError('Requirement not found.', 404);
                            }
                        }
                        else
                        {
                            
                            $stmt = $db->query($db->table('requirements')->set('requirement_code', ($fields)['code'])->set('title', ($fields)['title'])->set('description', ($fields)['description'])->set('status', ($fields)['status'])->set('priority', ($fields)['priority'])->set('owner_user_id', ($fields)['owner'])->getCompiledInsert() . ' RETURNING requirement_id');
                            $id = (int)Database::Scalar($stmt);
                        }
                    }
                    catch (DatabaseException $e)
                    {
                        if (Database::SqlState($e) === '23505')
                        {
                            JsonError('That requirement ID is already used.', 409);
                        }
                        throw $e;
                    }
                    JsonResponse(['ok' => true, 'requirement_id' => $id]);
                }

            case 'delete_requirement':
                {
                    $id = (int)(ReadJsonBody()['requirement_id'] ?? 0);
                    if ($id <= 0)
                    {
                        JsonError('Requirement ID is required.');
                    }
                    
                    $stmt = $db->table('requirements')->where('requirement_id', $id)->delete();
                    if ($db->affectedRows() === 0)
                    {
                        JsonError('Requirement not found.', 404);
                    }
                    JsonResponse(['ok' => true]);
                }

            case 'add_link':
                {
                    $data = ReadJsonBody();
                    $id = (int)($data['requirement_id'] ?? 0);
                    $type = (string)($data['asset_type'] ?? '');
                    $assetId = (int)($data['asset_id'] ?? 0);
                    if ($id <= 0 || $assetId <= 0 || !isset($sources[$type]))
                    {
                        JsonError('Choose a requirement and an asset.');
                    }
                    if (!TraceabilityCanViewType($type))
                    {
                        Permissions::Deny(true);
                    }
                    
                    $check = $db->table('requirements')->select('1', false)->where('requirement_id', $id)->get();
                    if (!Database::Scalar($check))
                    {
                        JsonError('Requirement not found.', 404);
                    }
                    $table = $sources[$type]['table'];
                    $column = $sources[$type]['column'];
                    $pk = $type === 'table' ? 'table_id' : ($type === 'column' ? 'column_id' : $column);
                    $checkSql = "SELECT 1 FROM $table WHERE $pk=:id:";
                    $check = $db->query($checkSql, ['id' => $assetId]);
                    if (!Database::Scalar($check))
                    {
                        JsonError('Asset not found.', 404);
                    }
                    try
                    {
                        $stmtSql = "INSERT INTO requirement_links (requirement_id, $column) VALUES (:requirement_id:, :asset_id:) RETURNING link_id";
                        $stmt = $db->query($stmtSql, ['requirement_id' => $id, 'asset_id' => $assetId]);
                    }
                    catch (DatabaseException $e)
                    {
                        if (Database::SqlState($e) === '23505')
                        {
                            JsonError('This asset is already linked to the requirement.', 409);
                        }
                        throw $e;
                    }
                    JsonResponse(['ok' => true, 'link_id' => (int)Database::Scalar($stmt)]);
                }

            case 'remove_link':
                {
                    $data = ReadJsonBody();
                    
                    $stmt = $db->table('requirement_links')->where('link_id', (int)($data['link_id'] ?? 0))->where('requirement_id', (int)($data['requirement_id'] ?? 0))->delete();
                    if ($db->affectedRows() === 0)
                    {
                        JsonError('Link not found.', 404);
                    }
                    JsonResponse(['ok' => true]);
                }

            case 'impact':
                {
                    $type = (string)($_GET['type'] ?? '');
                    $assetId = (int)($_GET['id'] ?? 0);
                    if ($assetId <= 0 || !isset($sources[$type]))
                    {
                        JsonError('Choose an asset.');
                    }
                    if (!TraceabilityCanViewType($type))
                    {
                        Permissions::Deny(true);
                    }
                    $column = $sources[$type]['column'];
                    $params = ['asset_id' => $assetId];
                    $where = "rl.$column=:asset_id:";
                    if ($type === 'table')
                    {
                        $where .= ' OR rl.dd_column_id IN (SELECT column_id FROM dd_columns WHERE table_id=:table_id:)';
                        $params[':table_id'] = $assetId;
                    }
                    $stmtSql = "SELECT DISTINCT r.requirement_id, r.requirement_code, r.title, r.status, r.priority,
                                              COALESCE(u.first_name || ' ' || u.last_name, '') AS owner_name
                                       FROM requirements r
                                       JOIN requirement_links rl ON rl.requirement_id=r.requirement_id
                                       LEFT JOIN users u ON u.user_id=r.owner_user_id
                                       WHERE ($where) ORDER BY r.requirement_code";
                    $stmt = $db->query($stmtSql, $params);
                    JsonResponse($stmt->getResultArray());
                }

            default: JsonError('Unknown action.', 400);
        }
    }

    public function ExcelExport()
    {
        /** One row per requirement/asset link; unlinked requirements still get a row. */
        require_once (ROOTPATH . 'modules/requirements') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/requirements') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/requirements') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/requirements') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/requirements') . '/../../lib/Traceability.php';
        require_once (ROOTPATH . 'modules/requirements') . '/../../lib/vendor_bootstrap.php';

        Auth::RequirePage();
        RequireVendorAutoload();

        $db = Database::GetConnection('app');
        $requirements = $db->query("SELECT r.requirement_id, r.requirement_code, r.title, r.description,
                                            r.status, r.priority, r.updated_at,
                                            COALESCE(u.first_name || ' ' || u.last_name, '') AS owner_name
                                     FROM requirements r LEFT JOIN users u ON u.user_id=r.owner_user_id
                                     ORDER BY r.requirement_code")->getResultArray();
        $links = $db->query(TraceabilityLinksSql() . ' ORDER BY rl.requirement_id, asset_type, asset_label')->getResultArray();
        $linksByRequirement = [];
        foreach ($links as $link)
        {
            if (TraceabilityCanViewType($link['asset_type']))
            {
                $linksByRequirement[$link['requirement_id']][] = $link;
            }
        }

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Traceability Matrix');
        $book->getProperties()->setTitle('Requirements Traceability Matrix');
        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', 'REQUIREMENTS TRACEABILITY MATRIX');
        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => ['name' => 'Segoe UI', 'size' => 16, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A8A']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);
        $headers = ['Requirement ID', 'Title', 'Description', 'Status', 'Priority', 'Owner', 'Asset Type', 'Linked Asset', 'Updated'];
        foreach ($headers as $i => $header)
        {
            $sheet->setCellValueByColumnAndRow($i + 1, 2, $header);
        }
        $sheet->getStyle('A2:I2')->applyFromArray([
            'font' => ['name' => 'Segoe UI', 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
        ]);

        $row = 3;
        $typeLabels = ['diagram' => 'Diagram', 'table' => 'Database table', 'column' => 'Database column',
                       'hosted_system' => 'Hosted system', 'group_system' => 'Group system'];
        foreach ($requirements as $requirement)
        {
            $items = $linksByRequirement[$requirement['requirement_id']] ?? [null];
            foreach ($items as $link)
            {
                $values = [
                    $requirement['requirement_code'], $requirement['title'], $requirement['description'] ?? '',
                    $requirement['status'], $requirement['priority'], $requirement['owner_name'],
                    $link ? ($typeLabels[$link['asset_type']] ?? $link['asset_type']) : '',
                    $link['asset_label'] ?? '', $requirement['updated_at'],
                ];
                foreach ($values as $i => $value)
                {
                    // Treat all user-controlled values as text, including strings beginning with '='.
                    $sheet->setCellValueExplicitByColumnAndRow($i + 1, $row, (string)$value, DataType::TYPE_STRING);
                }
                if ($row % 2 === 0)
                {
                    $sheet->getStyle("A{$row}:I{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
                }
                $row++;
            }
        }

        foreach (['A' => 19,'B' => 34,'C' => 52,'D' => 17,'E' => 14,'F' => 26,'G' => 20,'H' => 52,'I' => 22] as $column => $width)
        {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $lastRow = max(2, $row - 1);
        if ($row > 3)
        {
            $sheet->getStyle("A3:I{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        }
        $sheet->freezePane('A3');
        $sheet->setAutoFilter("A2:I{$lastRow}");

        Header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        Header('Content-Disposition: attachment; filename="requirements_traceability_' . Date('Y-m-d') . '.xlsx"');
        Header('Cache-Control: no-store');
        (new Xlsx($book))->Save('php://output');
        $book->disconnectWorksheets();
    }

    public function Index()
    {
        $pageTitle = 'Requirements Traceability';
        $pageSubtitle = 'Connect requirements to diagrams, data, and hosted systems';
        $activeNav = 'requirements';
        $pageScript = 'assets/js/requirements.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('requirements/index', get_defined_vars());
    }
}
