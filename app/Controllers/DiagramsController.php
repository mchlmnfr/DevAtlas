<?php
/**
 * Filename: DiagramsController.php
 * Purpose: Handle Diagrams routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Throwable;

use Auth;
use Database;
use ErdGenerator;
use ErdWorkbook;
use Generator;
use GraphMLBuilder;
use InvalidArgumentException;
use OutOfBoundsException;
use ZipArchive;

class DiagramsController extends BaseController
{
    public function Api()
    {
        /** ERD Generator API. Dictionary generation creates an editable snapshot. */
        require_once (ROOTPATH . 'modules/diagrams').'/../../config/config.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../config/Database.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../lib/ErdGenerator.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
        $read = in_array($action, ['list_diagrams','get_diagram','dictionary_tables'], true);
        if ($_SERVER['REQUEST_METHOD'] !== ($read ? 'GET' : 'POST'))
        {
            JsonError($read ? 'GET required.' : 'POST required.', 405);
        }
        if (!$read)
        {
            RequireCsrf();
        }
        if (!$read && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 6291456)
        {
            JsonError('The ERD request exceeds 6 MB.', 413);
        }
        $db = Database::GetConnection('app');
        try
        {
            $data = $read ? $_GET : ReadJsonBody();
            $id = (int)($data['diagram_id'] ?? 0);
            switch ($action)
            {
                case 'list_diagrams':
                    JsonResponse($db->query("SELECT d.diagram_id,d.diagram_name,d.description,d.updated_at,d.generation_meta,
                        (SELECT COUNT(*) FROM diagram_nodes n WHERE n.diagram_id=d.diagram_id AND n.node_subtype='entity') AS table_count,
                        (SELECT COUNT(*) FROM diagram_edges e WHERE e.diagram_id=d.diagram_id) AS edge_count
                        FROM diagrams d WHERE diagram_type='ERD' ORDER BY updated_at DESC,diagram_id DESC")->getResultArray());
                    // no break
                case 'dictionary_tables': JsonResponse(ErdGenerator::Catalog($db));
                    // no break
                case 'get_diagram': JsonResponse(ErdGenerator::Get($db, $id));
                    // no break
                case 'generate_dictionary': JsonResponse(['ok' => true,'diagram_id' => ErdGenerator::Generate($db, $data)]);
                    // no break
                case 'save_diagram':
                    if (isset($data['diagram_type']) && $data['diagram_type'] !== 'ERD')
                    {
                        throw new InvalidArgumentException('Only ERDs are supported.');
                    }
                    $name = ErdGenerator::Text($data['diagram_name'] ?? '', 200, 'ERD name', true);
                    $description = ErdGenerator::Text($data['description'] ?? '', 5000, 'Description');
                    if ($id)
                    {
                        ErdGenerator::Get($db, $id);
                        $db->table('diagrams')->set('diagram_name', $name)->set('description', $description)->where('diagram_id', $id)->where('diagram_type', 'ERD')->update();
                    }
                    else
                    {
                        
                        $stmt = $db->query($db->table('diagrams')->set('diagram_type', 'ERD')->set('diagram_name', $name)->set('description', $description)->getCompiledInsert() . ' RETURNING diagram_id');
                        $id = (int)Database::Scalar($stmt);
                    }
                    JsonResponse(['ok' => true,'diagram_id' => $id]);
                    // no break
                case 'delete_diagram':
                    
                    $stmt = $db->table('diagrams')->where('diagram_id', $id)->where('diagram_type', 'ERD')->delete();
                    if (!$db->affectedRows())
                    {
                        throw new OutOfBoundsException('ERD not found.');
                    }
                    JsonResponse(['ok' => true]);
                    // no break
                case 'save_layout': ErdGenerator::SaveLayout($db, $id, $data['positions'] ?? null);
                    JsonResponse(['ok' => true]);
                    // no break
                case 'save_entity': JsonResponse(['ok' => true,'node_key' => ErdGenerator::SaveEntity($db, $id, $data)]);
                    // no break
                case 'delete_entity': ErdGenerator::DeleteEntity($db, $id, ErdGenerator::Text($data['node_key'] ?? '', 50, 'Table reference', true));
                    JsonResponse(['ok' => true]);
                    // no break
                case 'save_relationship': JsonResponse(['ok' => true,'edge_id' => ErdGenerator::SaveRelationship($db, $id, $data)]);
                    // no break
                case 'delete_relationship':
                    $db->transBegin();
                    ErdGenerator::Get($db, $id, true);
                    
                    $stmt = $db->table('diagram_edges')->where('diagram_id', $id)->where('edge_id', (int)($data['edge_id'] ?? 0))->delete();
                    if (!$db->affectedRows())
                    {
                        throw new OutOfBoundsException('Relationship not found.');
                    }
                    $db->table('diagrams')->set('updated_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('diagram_id', $id)->update();
                    $db->transCommit();
                    JsonResponse(['ok' => true]);
                    // no break
                default:JsonError('Unknown action.', 400);
            }
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
    }

    public function ExcelExport()
    {
        /** Literal-text ERD snapshot export, compatible with Excel import. */
        require_once (ROOTPATH . 'modules/diagrams').'/../../config/config.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../config/Database.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../lib/ErdWorkbook.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../lib/vendor_bootstrap.php';
        Auth::RequirePage();
        RequireVendorAutoload();
        try
        {
            $diagram = ErdGenerator::Get(Database::GetConnection('app'), (int)($_GET['diagram_id'] ?? 0));
        }
        catch (OutOfBoundsException $e)
        {
            http_response_code(404);
            exit('ERD not found.');
        }
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getProperties()->setTitle($diagram['diagram_name'])->setDescription('DevAtlas ERD snapshot');
        foreach (['Nodes' => ['nodes',ErdWorkbook::NODES],'Edges' => ['edges',ErdWorkbook::EDGES]] as $title => [$key,$headers])
        {
            $sheet = $title === 'Nodes' ? $book->getActiveSheet() : $book->createSheet();
            $sheet->setTitle($title);
            foreach ($headers as $i => $name)
            {
                $sheet->setCellValueExplicit([$i + 1,1], $name, 's');
                $sheet->getColumnDimensionByColumn($i + 1)->setWidth(in_array($name, ['detail','erd_meta'], true) ? 60 : 24);
            }
            foreach ($diagram[$key] as $index => $row)
            {
                foreach ($headers as $i => $name)
                {
                    $sheet->setCellValueExplicit([$i + 1,$index + 2], $name === 'erd_meta' ? json_encode($row[$name], JSON_UNESCAPED_UNICODE) : (string)($row[$name] ?? ''), 's');
                }
            }
            $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
            $sheet->getStyle('A1:'.$last.'1')->applyFromArray(['font' => ['bold' => true,'color' => ['rgb' => 'FFFFFF']],'fill' => ['fillType' => 'solid','startColor' => ['rgb' => '243655']]]);
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:'.$last.max(1, count($diagram[$key]) + 1));
        }
        $sheet = $book->createSheet()->setTitle('Read Me');
        $lines = ['DevAtlas ERD snapshot','ERD: '.$diagram['diagram_name'],'Import replaces the current ERD tables and relationships. It does not change Data Dictionary.',
            'Nodes: entity = table; attribute = column. Each attribute requires its table node_key as parent_node_key.',
            'Keep node_key stable. New keys must contain letters, digits, underscores, hyphens or dots.',
            'Column detail: type followed by optional [PK], [FK] and NOT NULL. Example: integer [PK] NOT NULL',
            'Edges connect entity keys. erd_meta preserves dictionary lineage and source_column_key / target_column_key.',
            'Cardinality: 0..N:1, 0..N:0..1, 0..1:1, 0..1:0..1, 1:1, 1:N, N:1, N:M.',
            'Only literal values are accepted. Paste values to remove formulas. Limits: 50 tables, 5,000 columns and 5,000 relationships.',
            'Generation uses a snapshot. Generate a new ERD to include subsequent Data Dictionary changes.'];
        foreach ($lines as $i => $text)
        {
            $sheet->setCellValueExplicit('A'.($i + 1), $text, 's');
        }
        $sheet->getColumnDimension('A')->setWidth(120);
        $book->setActiveSheetIndex(0);
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $diagram['diagram_name']) ?: 'erd';
        Header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        Header('Content-Disposition: attachment; filename="'.$name.'.xlsx"');
        Header('Cache-Control: no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->Save('php://output');
    }

    public function ExcelImport()
    {
        /** Atomic ERD-only workbook replacement. */
        require_once (ROOTPATH . 'modules/diagrams').'/../../config/config.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../config/Database.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../lib/ErdWorkbook.php';
        require_once (ROOTPATH . 'modules/diagrams').'/../../lib/vendor_bootstrap.php';
        Auth::RequireApi();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            JsonError('POST required.', 405);
        }
        RequireCsrf();
        RequireVendorAutoload();
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5242880 || !preg_match('/\.xlsx$/i', $file['name']))
        {
            JsonError('Upload a valid .xlsx file up to 5 MB.', 422);
        }
        $db = Database::GetConnection('app');
        $id = (int)($_POST['diagram_id'] ?? 0);
        try
        {
            ErdGenerator::Get($db, $id);
            // Bound decompressed input before the spreadsheet reader allocates memory.
            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) !== true)
            {
                throw new InvalidArgumentException('The file is not an XLSX workbook.');
            }
            $bytes = 0;
            for ($i = 0;$i < $zip->numFiles;$i++)
            {
                $entry = $zip->statIndex($i);
                $bytes += $entry['size'];
            }
            $isTooLarge = $bytes > 52428800 || $zip->numFiles > 200;
            $zip->close();
            if ($isTooLarge)
            {
                throw new InvalidArgumentException('The expanded workbook is too large.');
            }
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $reader->setReadDataOnly(false);
            $reader->setLoadSheetsOnly(['Nodes','Edges']);
            $book = $reader->load($file['tmp_name']);
            $graph = ErdWorkbook::Graph(
                ErdWorkbook::Rows($book->getSheetByName('Nodes'), ['node_key','label','node_subtype'], 5050),
                ErdWorkbook::Rows($book->getSheetByName('Edges'), ['source_node_key','target_node_key'], 5000)
            );
            $db->transBegin();
            ErdGenerator::Get($db, $id, true);
            $db->table('diagram_edges')->where('diagram_id', $id)->delete();
            $db->table('diagram_nodes')->where('diagram_id', $id)->delete();
            ErdGenerator::InsertGraph($db, $id, $graph);
            $db->query("UPDATE diagrams SET updated_at=NOW(),generation_meta=generation_meta||'{\"edited\":true:,\"imported\":true:}'::JSONB WHERE diagram_id=?", [$id]);
            $db->transCommit();
            JsonResponse(['ok' => true,'node_count' => count($graph['nodes']),'edge_count' => count($graph['edges'])]);
        }
        catch (OutOfBoundsException $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            JsonError($e->getMessage(), 404);
        }
        catch (Throwable $e)
        {
            if ($e instanceof \App\Http\ResponseComplete)
            {
                throw $e;
            }
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            JsonError($e instanceof InvalidArgumentException ? $e->getMessage() : 'The workbook could not be imported. Check its format and values.', 422);
        }
    }

    public function GraphmlExport()
    {
        /**
         * modules/diagrams/graphml_export.php
         * Streams a valid yEd-compatible .graphml file for the requested diagram.
         * GET graphml_export.php?diagram_id=123
         */
        require_once (ROOTPATH . 'modules/diagrams') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/diagrams') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/diagrams') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/diagrams') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/diagrams') . '/../../lib/GraphMLBuilder.php';

        Auth::RequirePage();
        // opened via a direct link/new tab, so redirect to login rather than a JSON error

        $diagramId = (int)($_GET['diagram_id'] ?? 0);
        if (!$diagramId)
        {
            http_response_code(400);
            die('diagram_id is required.');
        }

        $db = Database::GetConnection('app');

        
        $stmt = $db->table('diagrams')->where('diagram_id', $diagramId)->where('diagram_type', 'ERD')->get();
        $diagram = $stmt->getUnbufferedRow('array');
        if (!$diagram)
        {
            http_response_code(404);
            die('Diagram not found.');
        }

        
        $n = $db->table('diagram_nodes')->where('diagram_id', $diagramId)->orderBy('node_id', '', false)->get();
        $nodes = $n->getResultArray();

        
        $e = $db->table('diagram_edges')->where('diagram_id', $diagramId)->orderBy('edge_id', '', false)->get();
        $edges = $e->getResultArray();

        if (empty($nodes))
        {
            http_response_code(422);
            die('This diagram has no nodes yet â€” add elements before exporting.');
        }

        $builder = new GraphMLBuilder($diagram['diagram_type'], $diagram['diagram_name'], $nodes, $edges);
        $xml = $builder->Build();

        $filename = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $diagram['diagram_name']) . '.graphml';

        Header('Content-Type: application/xml; charset=utf-8');
        Header('Content-Disposition: attachment; filename="' . $filename . '"');
        Header('Content-Length: ' . strlen($xml));
        echo $xml;
    }

    public function Index()
    {
        $pageTitle = 'ERD Generator';
        $pageSubtitle = 'Build entity relationship diagrams from your Data Dictionary';
        $activeNav = 'diagrams';
        $bodyClass = 'workspace-page erd-page';
        $pageStyles = ['assets/css/workspace.css','assets/css/erd.css'];
        $pageScripts = ['assets/js/workspace-ui.js'];
        $pageScript = 'assets/js/erd.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('diagrams/index', get_defined_vars());
    }
}
