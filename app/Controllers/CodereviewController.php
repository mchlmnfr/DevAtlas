<?php
/**
 * Filename: CodereviewController.php
 * Purpose: Handle Codereview routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use CodeReview;
use QmsCodeSniffer;


use Applications;
use Auth;
use Database;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Notifications;
use OutOfBoundsException;
use OwaspReview;
use CodeIgniter\Database\BaseConnection;
use Permissions;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Ods;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReviewStore;

class CodereviewController extends BaseController
{
    public function Api()
    {
        require_once APPPATH . 'Helpers/codereview_helper.php';
        require_once (ROOTPATH . 'modules/codereview') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/codereview') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/codereview') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/codereview') . '/../../lib/CodeReview.php';
        require_once (ROOTPATH . 'modules/codereview') . '/../../lib/ReviewStore.php';

        Auth::RequireApi();
        $action = $_GET['action'] ?? $_POST['action'] ?? '';

        if ($action === 'server_projects' && $_SERVER['REQUEST_METHOD'] === 'GET')
        {
            JsonResponse(CodeReview::ServerProjects());
        }
        if ($action !== 'scan' || $_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            JsonError('Unknown action.', 400);
        }
        RequireCsrf();
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > CodeReview::MAX_REQUEST_BYTES)
        {
            JsonError('Scan request exceeds 96 MB.', 413);
        }
        set_time_limit(300);

        $data = ReadJsonBody();
        try
        {
            $options = CodeReview::Options($data);
            $mode = (string)($data['mode'] ?? '');
            if ($mode === 'server')
            {
                $report = CodeReview::ScanServer((string)($data['project'] ?? ''), $options);
            }
            elseif ($mode === 'client')
            {
                $files = $data['files'] ?? null;
                if (!is_array($files))
                {
                    throw new InvalidArgumentException('Choose a folder.');
                }
                $report = CodeReview::ScanClient($files, $options, (string)($data['project'] ?? 'My computer'));
            }
            else
            {
                throw new InvalidArgumentException('Choose a scan mode.');
            }
            $report['review_id'] = ReviewStore::Save(Database::GetConnection('app'), $report, $data, Auth::User());
            JsonResponse($report);
        }
        catch (InvalidArgumentException $e)
        {
            JsonError($e->getMessage());
        }
    }

    public function Export()
    {
        require_once APPPATH . 'Helpers/codereview_helper.php';
        require_once (ROOTPATH . 'modules/codereview') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/codereview') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/codereview') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/codereview') . '/../../lib/CodeReview.php';
        require_once (ROOTPATH . 'modules/codereview') . '/../../lib/vendor_bootstrap.php';

        Auth::RequireApi();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            JsonError('POST required.', 405);
        }
        RequireCsrf();
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > CodeReview::MAX_REPORT_BYTES)
        {
            JsonError('Report exceeds 24 MB.', 413);
        }
        set_time_limit(300);
        $report = ReadJsonBody();
        $files = $report['files'] ?? null;
        if (!is_array($files) || count($files) > CodeReview::MAX_FILES)
        {
            JsonError('Invalid report.');
        }
        $findingCount = 0;
        foreach ($files as $file)
        {
            if (!is_array($file) || !is_array($file['findings'] ?? null) || !is_array($file['metrics'] ?? null))
            {
                JsonError('Invalid report.');
            }
            $findingCount += count($file['findings']);
            if ($findingCount > CodeReview::MAX_FINDINGS)
            {
                JsonError('Too many findings to export.');
            }
        }
        $isQms = ($report['engine'] ?? '') === 'qms';
        $isOwasp = ($report['engine'] ?? '') === 'owasp';
        if ($isQms)
        {
            try
            {
                $qmsOptions = CodeReview::Options(['engine' => 'qms',
                    'qms_scope' => $report['qms']['profile']['scope'] ?? 'existing',
                    'qms_indent' => $report['qms']['profile']['indent'] ?? '4']);
            }
            catch (InvalidArgumentException $e)
            {
                JsonError($e->getMessage());
            }
            foreach ($files as $file)
            {
                if (!is_array($file['qms_checks'] ?? null) || count($file['qms_checks']) !== 24)
                {
                    JsonError('Invalid QMS checklist.');
                }
                $ids = [];
                foreach ($file['qms_checks'] as $check)
                {
                    if (!is_array($check) || !is_int($check['id'] ?? null) || $check['id'] < 1 || $check['id'] > 24 ||
                        isset($ids[$check['id']]) || !in_array($check['status'] ?? '', QmsCodeSniffer::STATUSES, true) ||
                        !is_int($check['finding_count'] ?? null) || $check['finding_count'] < 0 || $check['finding_count'] > 1000000)
                    {
                        JsonError('Invalid QMS checklist.');
                    }
                    $ids[$check['id']] = true;
                }
            }
        }
        $inventory = $report['inventory'] ?? [];
        if (!is_array($inventory) || count($inventory) > CodeReview::MAX_FUNCTIONS)
        {
            JsonError('Invalid function inventory.');
        }
        foreach ($inventory as $row)
        {
            if (!is_array($row))
            {
                JsonError('Invalid function inventory.');
            }
            foreach (['variables', 'arguments', 'out_parameters', 'returns'] as $key)
            {
                if (!is_array($row[$key] ?? null) || count($row[$key]) > 100)
                {
                    JsonError('Invalid function inventory.');
                }
            }
        }
        // A full 3,000-file QMS workbook includes 72,000 criterion rows.
        if ($isQms && count($files) > 1000)
        {
            $limit = trim((string)ini_get('memory_limit'));
            $bytes = (int)$limit * match (strtolower(substr($limit, -1)))
            {
                'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1,
            };
            if ($bytes > 0 && $bytes < 1073741824)
            {
                ini_set('memory_limit', '1024M');
            }
        }
        RequireVendorAutoload();

        $book = new Spreadsheet();
        $summary = $book->getActiveSheet();
        $summary->setTitle('File Summary');
        $findings = $book->createSheet();
        $findings->setTitle('Findings');
        $inventorySheet = null;
        if (($report['engine'] ?? '') === 'inventory')
        {
            $inventorySheet = $book->createSheet();
            $inventorySheet->setTitle('Source Inventory');
        }



        CrHeading($summary, ['File', 'Type', 'Size (KB)', 'Lines', 'Functions', 'TODOs', 'eval() calls', 'Long lines', 'Findings']);
        CrHeading($findings, ['File', 'Line', 'Severity', 'Rule', 'Finding']);
        if ($isQms)
        {
            CrHeading($findings, ['File', 'Line', 'Severity', 'Rule', 'Finding', 'Criterion', 'Reviewer confirmation required']);
        }
        if ($isOwasp)
        {
            CrHeading($findings, ['File', 'Line', 'Severity', 'Rule', 'Finding', 'OWASP category', 'Suggested action']);
        }
        if ($inventorySheet !== null)
        {
            CrHeading($inventorySheet, ['Path', 'Source Code ID', 'Function ID', 'Function Name', 'Line', 'Description', 'Variables', 'Variable Data Types', 'Variable Passing', 'Arguments', 'Argument Data Types', 'Argument Passing', 'Out Parameters', 'Out Data Types', 'Out Passing', 'Returns', 'Return Data Type', 'Return Passing', 'Remarks']);
        }

        $sr = 2;
        $fr = 2;
        foreach ($files as $file)
        {
            $metrics = $file['metrics'];
            $values = [(string)($file['path'] ?? ''), (string)($file['type'] ?? ''),
                        number_format(((int)($file['size'] ?? 0)) / 1024, 2, '.', ''),
                        (int)($metrics['lines'] ?? 0), (int)($metrics['functions'] ?? 0),
                        (int)($metrics['todos'] ?? 0), (int)($metrics['evals'] ?? 0),
                        (int)($metrics['long_lines'] ?? 0), count($file['findings'])];
            foreach ($values as $i => $value)
            {
                CrSetText($summary, $i + 1, $sr, $value);
            }
            $sr++;
            foreach ($file['findings'] as $finding)
            {
                if (!is_array($finding))
                {
                    continue;
                }
                $values = [(string)($file['path'] ?? ''), (int)($finding['line'] ?? 0),
                            (string)($finding['severity'] ?? ''), (string)($finding['rule'] ?? ''),
                            (string)($finding['message'] ?? '')];
                if ($isQms)
                {
                    $values[] = (int)($finding['criterion'] ?? 0) ?: '';
                    $values[] = !empty($finding['review_required']) ? 'Yes' : 'No';
                }
                if ($isOwasp)
                {
                    $values[] = $finding['category'] ?? '';
                    $values[] = $finding['remediation'] ?? '';
                }
                foreach ($values as $i => $value)
                {
                    CrSetText($findings, $i + 1, $fr, $value);
                }
                $fr++;
            }
        }
        foreach (['A' => 65,'B' => 12,'C' => 14,'D' => 12,'E' => 13,'F' => 12,'G' => 15,'H' => 15,'I' => 13] as $column => $width)
        {
            $summary->getColumnDimension($column)->setWidth($width);
        }
        foreach (['A' => 65,'B' => 12,'C' => 14,'D' => 22,'E' => 95] as $column => $width)
        {
            $findings->getColumnDimension($column)->setWidth($width);
        }
        $summary->setAutoFilter('A1:I' . max(2, $sr - 1));
        $findings->setAutoFilter('A1:E' . max(2, $fr - 1));
        $findings->getStyle('E2:E' . max(2, $fr - 1))->getAlignment()->setWrapText(true);
        if ($isOwasp)
        {
            $findings->getColumnDimension('F')->setWidth(20);
            $findings->getColumnDimension('G')->setWidth(90);
            $findings->setAutoFilter('A1:G'.max(2, $fr - 1));
            $coverage = $book->createSheet();
            $coverage->setTitle('OWASP Coverage');
            CrHeading($coverage, ['Category','Title','Static coverage','Manual checkpoint','Reference']);
            $row = 2;
            foreach (OwaspReview::Categories() as $category)
            {
                $values = [$category['id'],$category['title'],'Partial patterns; manual review required',$category['manual'],$category['reference']];
                foreach ($values as $i => $value)
                {
                    CrSetText($coverage, $i + 1, $row, $value);
                }
                $row++;
            }
            foreach (['A' => 18,'B' => 48,'C' => 45,'D' => 110,'E' => 45] as $column => $width)
            {
                $coverage->getColumnDimension($column)->setWidth($width);
            }
            $coverage->getStyle('A2:E11')->getAlignment()->setWrapText(true);
        }
        if ($isQms)
        {
            $findings->getColumnDimension('F')->setWidth(12);
            $findings->getColumnDimension('G')->setWidth(32);
            $findings->setAutoFilter('A1:G' . max(2, $fr - 1));
            $checklist = $book->createSheet();
            $checklist->setTitle('QMS Checklist');
            CrHeading($checklist, ['Criterion','Title','Status','Coverage','Detected matches','Files needing attention','Files for manual review','Files with no issues detected','Not applicable files','Coverage notes']);
            $row = 2;
            foreach (QmsCodeSniffer::Summary($files) as $criterion)
            {
                $values = [$criterion['id'],$criterion['title'],$criterion['status'],$criterion['coverage'],$criterion['finding_count'],
                    $criterion['files']['Needs attention'],$criterion['files']['Manual review'],$criterion['files']['No issues detected'],$criterion['files']['Not applicable'],$criterion['note']];
                foreach ($values as $index => $value)
                {
                    CrSetText($checklist, $index + 1, $row, $value);
                }
                $row++;
            }
            $checklist->setAutoFilter('A1:J25');
            foreach (['A' => 12,'B' => 65,'C' => 24,'D' => 16,'E' => 18,'F' => 24,'G' => 24,'H' => 30,'I' => 22,'J' => 110] as $column => $width)
            {
                $checklist->getColumnDimension($column)->setWidth($width);
            }
            $checklist->getStyle('J2:J25')->getAlignment()->setWrapText(true);
            $fileChecks = $book->createSheet();
            $fileChecks->setTitle('QMS File Criteria');
            CrHeading($fileChecks, ['File','Criterion','Title','Status','Detected matches','Displayed findings limited']);
            $catalog = QmsCodeSniffer::Catalog();
            $row = 2;
            foreach ($files as $file)
            {
                foreach ($file['qms_checks'] as $check)
                {
                    $values = [$file['path'] ?? '',$check['id'],$catalog[$check['id'] - 1]['title'],$check['status'],$check['finding_count'],!empty($file['findings_limited']) ? 'Yes' : 'No'];
                    foreach ($values as $index => $value)
                    {
                        CrSetText($fileChecks, $index + 1, $row, $value);
                    }
                    $row++;
                }
            }
            $fileChecks->setAutoFilter('A1:F'.max(2, $row - 1));
            foreach (['A' => 65,'B' => 12,'C' => 65,'D' => 24,'E' => 20,'F' => 28] as $column => $width)
            {
                $fileChecks->getColumnDimension($column)->setWidth($width);
            }
            $profileSheet = $book->createSheet();
            $profileSheet->setTitle('QMS Review Profile');
            CrHeading($profileSheet, ['Setting','Value']);
            $profile = QmsCodeSniffer::Profile($qmsOptions);
            $settings = ['Engine' => 'QMS CodeSniffer','Change scope' => $profile['scope'],'Indentation' => $profile['indent'] === 'tabs' ? 'Tabs' : $profile['indent'].' spaces',
                'Required header fields' => implode(', ', $profile['header_fields']),
                'Detected matches and display limits' => $profile['limits'],
                'Findings limited' => !empty($report['findings_limited']) ? 'Yes' : 'No',
                'Skipped files' => (int)($report['skipped'] ?? 0),
                'Interpretation' => 'No issues detected refers to the static checks performed. Manual checks and coverage notes require reviewer confirmation before recording compliance.',
                'Modification trail' => 'Modified scope requires a multiline summary, developer and date. Use a diff to verify placement before each modified section.',
                'Manual standards' => 'Exact QMU database naming and SQL template are pending. Verify SAD approval, database ownership, encrypted secret storage and successful log creation using external evidence.'];
            $row = 2;
            foreach ($settings as $label => $value)
            {
                CrSetText($profileSheet, 1, $row, $label);
                CrSetText($profileSheet, 2, $row, $value);
                $row++;
            }
            $profileSheet->getColumnDimension('A')->setWidth(38);
            $profileSheet->getColumnDimension('B')->setWidth(120);
            $profileSheet->getStyle('B2:B'.($row - 1))->getAlignment()->setWrapText(true);
        }
        if ($inventorySheet !== null)
        {
            $join = static function (array $items, string $key): string
            {
                return implode("\n", array_map(static fn ($item): string => is_array($item) ? (string)($item[$key] ?? '') : '', $items));
            };
            $rowNumber = 2;
            foreach ($inventory as $function)
            {
                $values = [
                    $function['path'] ?? '', $function['source_code_id'] ?? '', $function['function_id'] ?? '',
                    $function['function_name'] ?? '', $function['line'] ?? '', $function['description'] ?? '',
                    $join($function['variables'], 'name'), $join($function['variables'], 'data_type'), $join($function['variables'], 'type'),
                    $join($function['arguments'], 'name'), $join($function['arguments'], 'data_type'), $join($function['arguments'], 'type'),
                    $join($function['out_parameters'], 'name'), $join($function['out_parameters'], 'data_type'), $join($function['out_parameters'], 'type'),
                    $join($function['returns'], 'value') ?: 'void', $function['return_data_type'] ?? '', $function['return_type'] ?? '', $function['remarks'] ?? '',
                ];
                foreach ($values as $index => $value)
                {
                    CrSetText($inventorySheet, $index + 1, $rowNumber, $value);
                }
                $rowNumber++;
            }
            foreach (['A' => 55,'B' => 24,'C' => 16,'D' => 35,'E' => 10,'F' => 65,'G' => 30,'H' => 25,'I' => 22,'J' => 30,'K' => 25,'L' => 22,'M' => 25,'N' => 22,'O' => 18,'P' => 25,'Q' => 20,'R' => 20,'S' => 40] as $column => $width)
            {
                $inventorySheet->getColumnDimension($column)->setWidth($width);
            }
            $inventorySheet->setAutoFilter('A1:S' . max(2, $rowNumber - 1));
            $inventorySheet->getStyle('F2:S' . max(2, $rowNumber - 1))->getAlignment()->setWrapText(true);
        }
        $book->setActiveSheetIndex(0);

        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($report['project'] ?? 'project'));
        $name = substr(trim($name, '_'), 0, 50) ?: 'project';
        $format = (string)($_GET['format'] ?? 'xlsx');
        if (!in_array($format, ['xlsx', 'ods'], true))
        {
            JsonError('Invalid export format.');
        }
        Header('Content-Type: ' . ($format === 'ods' ? 'application/vnd.oasis.opendocument.spreadsheet' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        Header('Content-Disposition: attachment; filename="code_review_' . $name . '_' . Date('Y-m-d') . '.' . $format . '"');
        Header('Cache-Control: no-store');
        ($format === 'ods' ? new Ods($book) : new Xlsx($book))->Save('php://output');
        $book->disconnectWorksheets();
    }

    public function History()
    {
        require_once APPPATH . 'Helpers/codereview_helper.php';
        $pageTitle = 'Review History';
        $pageSubtitle = 'Saved findings, reviewer decisions and comparisons';
        $activeNav = 'codereview';
        $bodyClass = 'workspace-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/workflows.css'];
        $pageScripts = ['assets/js/workspace-ui.js'];
        $pageScript = 'assets/js/review-history.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('codereview/history', get_defined_vars());
    }

    public function HistoryApi()
    {
        require_once APPPATH . 'Helpers/codereview_helper.php';
        require_once (ROOTPATH . 'modules/codereview').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/codereview').'/../../lib/ReviewStore.php';
        require_once (ROOTPATH . 'modules/codereview').'/../../lib/Notifications.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        $db = Database::GetConnection('app');
        $action = $_GET['action'] ?? '';
        $reads = ['runs','detail','findings','catalog','compare'];
        if ($_SERVER['REQUEST_METHOD'] !== (in_array($action, $reads, true) ? 'GET' : 'POST'))
        {
            JsonError('Invalid request method.', 405);
        }
        if (!in_array($action, $reads, true))
        {
            RequireCsrf();
        }
        try
        {
            $data = in_array($action, $reads, true) ? $_GET : ReadJsonBody();
            $id = (int)($data['review_id'] ?? 0);
            $actor = Auth::User();
            if ($action === 'catalog')
            {
                $people = [];
                foreach ($db->table('users')->select('user_id,first_name||\' \'||last_name name', false)->where('is_active', true)->where(new \CodeIgniter\Database\RawSql('NOT is_locked'))->orderBy('last_name,first_name', '', false)->get()->getResultArray() as $user)
                {
                    if (Permissions::UserCan($db, (int)$user['user_id'], 'codereview', 'review'))
                    {
                        $people[] = $user;
                    }
                }
                JsonResponse(['applications' => Permissions::Can('applications') ? $db->table('applications')->select('application_id,application_code,application_name', false)->orderBy('application_name', '', false)->get()->getResultArray() : [],'reviewers' => $people]);
            }
            if ($action === 'runs')
            {
                $q = Applications::Text($data['q'] ?? '', 200);
                $engine = $data['engine'] ?? '';
                $app = (int)($data['application_id'] ?? 0);
                $isMine = ($data['mine'] ?? '') === '1';
                if ($engine !== '' && !in_array($engine, ['standard','security','qms','inventory','owasp'], true))
                {
                    throw new InvalidArgumentException('Choose a valid engine.');
                }
                $where = ' WHERE 1=1';
                $params = [];
                if ($q !== '')
                {
                    $where .= " AND CONCAT_WS(' ',r.title,r.project,r.creator_name) ILIKE ?";
                    $params[] = '%'.$q.'%';
                }
                if ($engine !== '')
                {
                    $where .= ' AND r.engine=?';
                    $params[] = $engine;
                }
                if ($app)
                {
                    $where .= ' AND r.application_id=?';
                    $params[] = $app;
                }
                if ($isMine)
                {
                    $where .= ' AND r.assigned_to=?';
                    $params[] = $actor['user_id'];
                }
                $stmtSql = 'SELECT COUNT(*) FROM review_runs r'.$where;
                $stmt = $db->query($stmtSql, $params);
                $total = (int)Database::Scalar($stmt);
                $pages = max(1, (int)ceil($total / 25));
                $page = min(max(1, (int)($data['page'] ?? 1)), $pages);
                $stmtSql = "SELECT r.review_id,r.title,r.project,r.engine,r.origin,r.application_id,r.creator_name,r.created_at,r.finding_count,r.file_count,r.completed_at,r.due_at,a.application_name,u.first_name||' '||u.last_name assignee_name,(SELECT COUNT(*) FROM review_findings f WHERE f.review_id=r.review_id AND status IN('Open','In Progress')) open_count FROM review_runs r LEFT JOIN applications a ON a.application_id=r.application_id LEFT JOIN users u ON u.user_id=r.assigned_to".$where.' ORDER BY r.review_id DESC LIMIT 25 OFFSET '.(($page - 1) * 25);
                $stmt = $db->query($stmtSql, $params);
                JsonResponse(['runs' => $stmt->getResultArray(),'total' => $total,'page' => $page,'pages' => $pages]);
            }
            if ($action === 'detail')
            {
                $run = ReviewStore::Get($db, $id);
                
                $stmt = $db->table('review_notes')->where('review_id', $id)->orderBy('note_id DESC', '', false)->limit((int) (100), (int) (0))->get();
                $run['notes'] = $stmt->getResultArray();
                
                $stmt = $db->table('review_findings')->select('status,COUNT(*) count', false)->where('review_id', $id)->groupBy('status', false)->get();
                $run['statuses'] = Database::Pairs($stmt);
                JsonResponse($run);
            }
            if ($action === 'findings')
            {
                ReviewStore::Get($db, $id);
                $status = $data['status'] ?? '';
                if ($status !== '' && !in_array($status, ['Open','In Progress','Fixed','Accepted'], true))
                {
                    throw new InvalidArgumentException('Choose a valid finding status.');
                }
                $where = ' WHERE review_id=?';
                $params = [$id];
                if ($status !== '')
                {
                    $where .= ' AND status=?';
                    $params[] = $status;
                }
                $stmtSql = 'SELECT COUNT(*) FROM review_findings'.$where;
                $stmt = $db->query($stmtSql, $params);
                $total = (int)Database::Scalar($stmt);
                $pages = max(1, (int)ceil($total / 50));
                $page = min(max(1, (int)($data['page'] ?? 1)), $pages);
                $stmtSql = 'SELECT * FROM review_findings'.$where.' ORDER BY finding_id LIMIT 50 OFFSET '.(($page - 1) * 50);
                $stmt = $db->query($stmtSql, $params);
                JsonResponse(['findings' => $stmt->getResultArray(),'total' => $total,'page' => $page,'pages' => $pages]);
            }
            if ($action === 'compare')
            {
                JsonResponse(ReviewStore::Comparison($db, $id, (int)($data['baseline_id'] ?? 0)));
            }
            if ($action === 'update_run')
            {
                ReviewStore::Get($db, $id);
                $title = Applications::Text($data['title'] ?? '', 200, true);
                $app = empty($data['application_id']) ? null : (int)$data['application_id'];
                if ($app)
                {
                    Permissions::require('applications');
                    Applications::Get($db, $app);
                }
                $db->table('review_runs')->set('title', $title)->set('application_id', $app)->where('review_id', $id)->update();
                JsonResponse(['ok' => true]);
            }
            if ($action === 'assign_review')
            {
                $user = empty($data['assigned_to']) ? null : (int)$data['assigned_to'];
                $due = Applications::Text($data['due_at'] ?? '', 10);
                if ($due !== '')
                {
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $due);
                    if (!$date || $date->format('Y-m-d') !== $due)
                    {
                        throw new InvalidArgumentException('Choose a valid due date.');
                    }
                }
                if ($user && !Permissions::UserCan($db, $user, 'codereview', 'review'))
                {
                    throw new InvalidArgumentException('Choose an active user with Code Review review permission.');
                }
                $db->transBegin();
                
                $stmt = $db->query($db->table('review_runs')->select('1', false)->where('review_id', $id)->getCompiledSelect() . ' FOR UPDATE');
                if (!Database::Scalar($stmt))
                {
                    throw new OutOfBoundsException('Review not found.');
                }
                $db->table('review_runs')->set('assigned_to', $user)->set('due_at', $due ?: null)->where('review_id', $id)->update();
                if ($user)
                {
                    Notifications::Add($db, $user, 'review_assignment', 'A review was assigned to you', 'Open the saved review to inspect findings, record decisions and check its due date.', APP_BASE_URL.'/modules/codereview/history.php?review_id='.$id, 'review-assignment:'.$id.':'.$user);
                }
                $db->transCommit();
                JsonResponse(['ok' => true]);
            }
            if ($action === 'findings_update')
            {
                $finding = (int)($data['finding_id'] ?? 0);
                $status = $data['status'] ?? '';
                $note = Applications::Text($data['note'] ?? '', 2000);
                if (!in_array($status, ['Open','In Progress','Fixed','Accepted'], true))
                {
                    throw new InvalidArgumentException('Choose a valid finding status.');
                }
                if (in_array($status, ['Fixed','Accepted'], true) && $note === '')
                {
                    throw new InvalidArgumentException('Explain the fix or accepted exception. Do not include secrets or source code.');
                }
                $expected = Applications::Text($data['expected_updated_at'] ?? '', 100, true);
                $db->transBegin();
                
                $stmt = $db->query($db->table('review_findings')->set('status', $status)->set('disposition_note', $note)->set('updated_by', $actor['user_id'])->set('updated_at', new \CodeIgniter\Database\RawSql('clock_timestamp()'))->where('review_id', $id)->where('finding_id', $finding)->where('updated_at', new \CodeIgniter\Database\RawSql('CAST(' . $db->escape($expected) . ' AS TIMESTAMPTZ)'))->getCompiledUpdate() . ' RETURNING finding_id');
                if (!Database::Scalar($stmt))
                {
                    throw new DomainException('This finding changed since you opened it. Refresh before saving.');
                }
                $db->table('review_runs')->set('completed_at', null)->where('review_id', $id)->update();
                if ($note !== '')
                {
                    $db->table('review_notes')->set('review_id', $id)->set('finding_id', $finding)->set('author_id', $actor['user_id'])->set('author_name', $actor['full_name'])->set('note', $status.': '.$note)->insert();
                }
                $db->transCommit();
                JsonResponse(['ok' => true]);
            }
            if ($action === 'add_note' || $action === 'complete_review')
            {
                ReviewStore::Get($db, $id);
                $note = Applications::Text($data['note'] ?? '', 2000, true);
                $db->transBegin();
                $db->query($db->table('review_runs')->select('review_id', false)->where('review_id', $id)->getCompiledSelect() . ' FOR UPDATE');
                if ($action === 'complete_review')
                {
                    
                    $stmt = $db->table('review_findings')->select('COUNT(*)', false)->where('review_id', $id)->whereIn('status', ['Open', 'In Progress'])->get();
                    if (Database::Scalar($stmt) > 0)
                    {
                        throw new InvalidArgumentException('Resolve or explicitly accept every finding before completing the review.');
                    }
                    $db->table('review_runs')->set('completed_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('review_id', $id)->update();
                    $note = 'Review completed: '.$note;
                }
                $db->table('review_notes')->set('review_id', $id)->set('author_id', $actor['user_id'])->set('author_name', $actor['full_name'])->set('note', $note)->insert();
                $db->transCommit();
                JsonResponse(['ok' => true]);
            }
            JsonError('Unknown action.');
        }
        catch (InvalidArgumentException $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            JsonError($e->getMessage(), 422);
        }
        catch (OutOfBoundsException $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            JsonError($e->getMessage(), 404);
        }
        catch (DomainException $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            JsonError($e->getMessage(), 409);
        }
    }

    public function HistoryExport()
    {
        require_once APPPATH . 'Helpers/codereview_helper.php';
        require_once (ROOTPATH . 'modules/codereview').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/codereview').'/../../lib/ReviewStore.php';
        Auth::RequirePage();
        if ($_SERVER['REQUEST_METHOD'] !== 'GET')
        {
            http_response_code(405);
            \FinishRequest();
        }
        $db = Database::GetConnection('app');
        $id = (int)($_GET['review_id'] ?? 0);
        try
        {
            $run = ReviewStore::Get($db, $id);
        }
        catch (OutOfBoundsException $e)
        {
            http_response_code(404);
            exit('Review not found.');
        }
        Header('Content-Type: text/csv; charset=utf-8');
        Header('Cache-Control: no-store');
        Header('Content-Disposition: attachment; filename="review-'.$id.'.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Review','Engine','File','Line','Severity','Rule','Category','Finding','Remediation','Status','Disposition']);
        
        $stmt = $db->table('review_findings')->where('review_id', $id)->orderBy('finding_id', '', false)->get();
        foreach ($stmt->getResultArray() as $finding)
        {
            $values = [$run['title'],$run['engine'],$finding['file_path'],$finding['line_number'],$finding['severity'],$finding['rule'],$finding['category'],$finding['message'],$finding['remediation'],$finding['status'],$finding['disposition_note']];
            foreach ($values as &$value)
            {
                $value = (string)($value ?? '');
                if (preg_match('/^\s*[=+@\-\t\r\n]/', $value))
                {
                    $value = "'".$value;
                }
            }
            unset($value);
            fputcsv($out, $values);
        }
        fclose($out);
    }

    public function Index()
    {
        require_once APPPATH . 'Helpers/codereview_helper.php';
        $pageTitle = 'Code Review';
        $pageSubtitle = 'Source diagnostics, QMS standards, security checks and function inventory';
        $activeNav = 'codereview';
        $bodyClass = 'workspace-page code-review-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/code-review.css','assets/css/workflows.css'];
        $pageScripts = ['assets/js/workspace-ui.js','assets/js/qms-review.js'];
        $pageScript = 'assets/js/code-review.js';
        Auth::RequirePage();
        $currentUser = Auth::User();

        require_once (ROOTPATH . 'modules/codereview').'/../../lib/CodeReview.php';

        return $this->Render('codereview/index', get_defined_vars());
    }
}
