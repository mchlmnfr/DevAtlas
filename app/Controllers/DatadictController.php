<?php
/**
 * Filename: DatadictController.php
 * Purpose: Handle Datadict routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use DataDictionary;
use DatabaseEngines;


use Throwable;

use Auth;
use Database;
use InvalidArgumentException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use SchemaIntrospector;

class DatadictController extends BaseController
{
    public function Api()
    {
        require_once APPPATH . 'Helpers/datadict_helper.php';
        require_once (ROOTPATH . 'modules/datadict').'/../../config/config.php';
        require_once (ROOTPATH . 'modules/datadict').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/datadict').'/../../config/Database.php';
        require_once (ROOTPATH . 'modules/datadict').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/datadict').'/../../lib/SchemaIntrospector.php';
        require_once (ROOTPATH . 'modules/datadict').'/../../lib/DataDictionary.php';
        Auth::RequireApi();
        $action = (string)($_GET['action'] ?? '');
        $reads = ['engines','list_tables','get_table','list_connections','get_connection','metadata_export','metadata_example'];
        $writes = ['save_table','delete_table','save_column','delete_column','save_connection','delete_connection','test_connection','introspect_schemas','introspect_tables','preview_table','import_tables','import_all_tables','import_from_introspection','preview_metadata','import_metadata'];
        if (!in_array($action, array_merge($reads, $writes), true))
        {
            JsonError('Unknown action.');
        }
        if ($_SERVER['REQUEST_METHOD'] !== (in_array($action, $reads, true) ? 'GET' : 'POST'))
        {
            JsonError(in_array($action, $reads, true) ? 'GET required.' : 'POST required.', 405);
        }
        if (in_array($action, $writes, true))
        {
            RequireCsrf();
        }
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 6 * 1024 * 1024)
        {
            JsonError('Request exceeds 6 MB. Import fewer tables at a time.', 413);
        }
        $body = $_SERVER['REQUEST_METHOD'] === 'POST' ? ReadJsonBody() : [];
        $db = Database::GetConnection('app');
        try
        {
            switch ($action)
            {
                case 'engines': JsonResponse(DatabaseEngines::Catalog());
                    // no break
                case 'list_tables':
                    $rows = $db->query("SELECT t.*,COALESCE(c.column_count,0) AS column_count,COALESCE(c.documented_columns,0) AS documented_columns,COALESCE(c.sensitive_columns,0) AS sensitive_columns,COALESCE(c.foreign_keys,0) AS foreign_keys FROM dd_tables t LEFT JOIN (SELECT table_id,COUNT(*) AS column_count,COUNT(*) FILTER (WHERE NULLIF(TRIM(description),'') IS NOT NULL) AS documented_columns,COUNT(*) FILTER (WHERE sensitive_flag) AS sensitive_columns,COUNT(*) FILTER (WHERE is_foreign_key) AS foreign_keys FROM dd_columns GROUP BY table_id) c ON c.table_id=t.table_id ORDER BY t.source_database,t.schema_name,t.table_name")->getResultArray();
                    foreach ($rows as &$row)
                    {
                        foreach (['table_id','column_count','documented_columns','sensitive_columns','foreign_keys'] as $key)
                        {
                            $row[$key] = (int)$row[$key];
                        }
                    }
                    unset($row);
                    JsonResponse($rows);
                    // no break
                case 'get_table':
                    
                    $statement = $db->table('dd_tables')->where('table_id', (int)($_GET['table_id'] ?? 0))->get();
                    $table = $statement->getUnbufferedRow('array');
                    if (!$table)
                    {
                        JsonError('Table not found.', 404);
                    }
                    
                    $statement = $db->table('dd_columns')->where('table_id', $table['table_id'])->orderBy('ordinal_position NULLS LAST,column_name', '', false)->get();
                    $table['columns'] = $statement->getResultArray();
                    $statementSql = 'SELECT c.column_id,c.column_name,c.fk_ref_table,c.fk_ref_column,t.table_id,t.schema_name,t.table_name FROM dd_columns c JOIN dd_tables t ON t.table_id=c.table_id WHERE t.source_database=? AND c.is_foreign_key AND (c.fk_ref_table=? OR (t.schema_name=? AND c.fk_ref_table=?)) ORDER BY t.schema_name,t.table_name,c.column_name';
                    $statement = $db->query($statementSql, [$table['source_database'],$table['schema_name'].'.'.$table['table_name'],$table['schema_name'],$table['table_name']]);
                    $table['referenced_by'] = $statement->getResultArray();
                    JsonResponse($table);
                    // no break
                case 'save_table':
                    $fields = DataDictionary::Table($body);
                    $fields['is_active'] = PgBool($fields['is_active']);
                    $id = (int)($body['table_id'] ?? 0);
                    if ($id > 0)
                    {
                        $db->table('dd_tables')->where('table_id', $id)->update($fields);
                        if (!$db->affectedRows())
                        {
                            JsonError('Table not found.', 404);
                        }
                    }
                    else
                    {
                        $statement = $db->query($db->table('dd_tables')->set($fields)->getCompiledInsert() . ' RETURNING table_id');
                        $id = (int)Database::Scalar($statement);
                    }
                    JsonResponse(['ok' => true,'table_id' => $id]);
                    // no break
                case 'delete_table':
                    
                    $statement = $db->table('dd_tables')->where('table_id', (int)($body['table_id'] ?? 0))->delete();
                    if (!$db->affectedRows())
                    {
                        JsonError('Table not found.', 404);
                    }
                    JsonResponse(['ok' => true]);
                    // no break
                case 'save_column':
                    $fields = DataDictionary::Column($body);
                    $tableId = (int)($body['table_id'] ?? 0);
                    $id = (int)($body['column_id'] ?? 0);
                    
                    $statement = $db->table('dd_tables')->select('table_id', false)->where('table_id', $tableId)->get();
                    if (!Database::Scalar($statement))
                    {
                        JsonError('Table not found.', 404);
                    }
                    foreach (['is_nullable','is_primary_key','is_foreign_key','is_auto_increment','sensitive_flag'] as $key)
                    {
                        $fields[$key] = PgBool($fields[$key]);
                    }
                    $fields['table_id'] = $tableId;
                    if ($id > 0)
                    {
                        $columnFields = $fields;
                        unset($columnFields['table_id']);
                        $db->table('dd_columns')->where('column_id', $id)->where('table_id', $tableId)->update($columnFields);
                        if (!$db->affectedRows())
                        {
                            JsonError('Column not found in this table.', 404);
                        }
                    }
                    else
                    {
                        $statement = $db->query($db->table('dd_columns')->set($fields)->getCompiledInsert() . ' RETURNING column_id');
                        $id = (int)Database::Scalar($statement);
                    }
                    JsonResponse(['ok' => true,'column_id' => $id]);
                    // no break
                case 'delete_column':
                    
                    $statement = $db->table('dd_columns')->where('column_id', (int)($body['column_id'] ?? 0))->where('table_id', (int)($body['table_id'] ?? 0))->delete();
                    if (!$db->affectedRows())
                    {
                        JsonError('Column not found in this table.', 404);
                    }
                    JsonResponse(['ok' => true]);
                    // no break
                case 'list_connections': case 'get_connection':
                    $sql = "SELECT connection_id,label,db_engine,host,port,username,database_name,default_schema FROM dd_connections";
                    if ($action === 'get_connection')
                    {
                        $statementSql = $sql.' WHERE connection_id=?';
                        $statement = $db->query($statementSql, [(int)($_GET['connection_id'] ?? 0)]);
                        $profile = $statement->getUnbufferedRow('array');
                        if (!$profile)
                        {
                            JsonError('Saved connection not found.', 404);
                        }
                        JsonResponse($profile);
                    }
                    JsonResponse($db->query($sql.' ORDER BY label,connection_id')->getResultArray());
                    // no break
                case 'save_connection':
                    $fields = DatabaseEngines::Validate($body);
                    if ($fields['label'] === '')
                    {
                        throw new InvalidArgumentException('Enter a connection label.');
                    }
                    $id = (int)($body['connection_id'] ?? 0);
                    if ($id > 0 && $fields['password'] === '' && empty($body['clear_password']))
                    {
                        unset($fields['password']);
                    }
                    else
                    {
                        $fields['password'] = DataDictionary::Encrypt($fields['password']);
                        $fields['password_encrypted'] = 'true';
                    }
                    if ($id > 0)
                    {
                        $db->table('dd_connections')->where('connection_id', $id)->update($fields);
                        if (!$db->affectedRows())
                        {
                            JsonError('Saved connection not found.', 404);
                        }
                    }
                    else
                    {
                        $statement = $db->query($db->table('dd_connections')->set($fields)->getCompiledInsert() . ' RETURNING connection_id');
                        $id = (int)Database::Scalar($statement);
                    }
                    JsonResponse(['ok' => true,'connection_id' => $id]);
                    // no break
                case 'delete_connection':
                    
                    $statement = $db->table('dd_connections')->where('connection_id', (int)($body['connection_id'] ?? 0))->delete();
                    if (!$db->affectedRows())
                    {
                        JsonError('Saved connection not found.', 404);
                    }
                    JsonResponse(['ok' => true]);
                    // no break
                case 'test_connection': case 'introspect_schemas': case 'introspect_tables': case 'preview_table': case 'import_tables': case 'import_all_tables': case 'import_from_introspection':
                    $conn = DdConnection($body, $db);
                    $intro = new SchemaIntrospector($conn['db_engine'], $conn['host'], (string)$conn['port'], $conn['username'], $conn['password'], $conn['database_name']);
                    if ($action === 'test_connection')
                    {
                        JsonResponse(['ok' => true,'version' => $intro->Ping()]);
                    }
                    if ($action === 'introspect_schemas')
                    {
                        JsonResponse($intro->ListSchemas());
                    }
                    $schema = DataDictionary::Text($body['schema'] ?? null, 128, 'Schema', true);
                    if ($action === 'introspect_tables')
                    {
                        JsonResponse($intro->ListObjects($schema, DataDictionary::Boolean($body['include_views'] ?? true)));
                    }
                    if ($action === 'preview_table')
                    {
                        $name = DataDictionary::Text($body['table_name'] ?? null, 128, 'Table name', true);
                        $columns = $intro->GetColumns($schema, $name);
                        if (count($columns) > 2000)
                        {
                            JsonError('This table exceeds the 2,000-column import limit.');
                        }
                        JsonResponse(array_map(static fn ($column) => DataDictionary::Column($column), $columns));
                    }
                    $available = $intro->ListObjects($schema, true);
                    $types = array_column($available, 'object_type', 'table_name');
                    $names = $action === 'import_all_tables' ? array_keys($types) : ($action === 'import_from_introspection' ? [$body['table_name'] ?? ''] : ($body['tables'] ?? []));
                    if (!is_array($names) || !array_is_list($names) || !$names || count($names) > 25)
                    {
                        JsonError('Select 1 to 25 tables per request. Use the import workflow to process larger selections.');
                    }
                    $names = array_map(static fn ($name) => DataDictionary::Text($name, 128, 'Table name', true), $names);
                    $label = DataDictionary::Text($body['source_database'] ?? ($conn['label'] ?: ($conn['db_engine'] === 'sqlite' ? basename($conn['database_name']) : $conn['database_name'])), 150, 'Dictionary database label', true);
                    $results = [];
                    foreach (array_unique($names) as $name)
                    {
                        $name = DataDictionary::Text($name, 128, 'Table name', true);
                        try
                        {
                            if (!isset($types[$name]))
                            {
                                throw new InvalidArgumentException('Table/view is no longer available in this schema.');
                            }
                            $columns = $intro->GetColumns($schema, $name);
                            if (!$columns || count($columns) > 2000)
                            {
                                throw new InvalidArgumentException('Table has no accessible columns or exceeds 2,000 columns.');
                            }
                            $db->transBegin();
                            $result = DataDictionary::Upsert($db, ['source_database' => $label,'schema_name' => $schema,'table_name' => $name,'source_engine' => $conn['db_engine'],'object_type' => $types[$name]], $columns);
                            $db->transCommit();
                            $results[] = ['table' => $name,'ok' => true] + $result;
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
                            $results[] = ['table' => $name,'ok' => false,'error' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'Metadata could not be imported. Check catalog permissions and supported field lengths.'];
                        }
                    }
                    JsonResponse(['ok' => true,'database' => $label,'schema' => $schema,'total' => count($results),'imported' => count(array_filter($results, static fn ($row) => $row['ok'])),'results' => $results]);
                    // no break
                case 'preview_metadata': case 'import_metadata':
                    $document = $body['document'] ?? null;
                    if (!is_array($document))
                    {
                        throw new InvalidArgumentException('Choose a dictionary metadata JSON file.');
                    }
                    $tables = DataDictionary::Document($document);
                    if ($action === 'preview_metadata')
                    {
                        JsonResponse(array_map(static fn ($table) => ['source_database' => $table['source_database'],'schema_name' => $table['schema_name'],'table_name' => $table['table_name'],'object_type' => $table['object_type'],'column_count' => count($table['columns'])], $tables));
                    }
                    if (count($tables) > 25)
                    {
                        throw new InvalidArgumentException('Import up to 25 metadata tables per request.');
                    }
                    $results = [];
                    foreach ($tables as $table)
                    {
                        $db->transBegin();
                        try
                        {
                            $result = DataDictionary::Upsert($db, $table, $table['columns']);
                            $db->transCommit();
                            $results[] = ['table' => $table['table_name'],'ok' => true] + $result;
                        }
                        catch (Throwable $e)
                        {
                            if ($e instanceof \App\Http\ResponseComplete)
                            {
                                throw $e;
                            }
                            $db->transRollback();
                            $results[] = ['table' => $table['table_name'],'ok' => false,'error' => 'Metadata could not be imported. Check the supplied field lengths and types.'];
                        }
                    }
                    JsonResponse(['ok' => true,'results' => $results]);
                    // no break
                case 'metadata_example':
                    DdDownload(['format' => 'devatlas-dictionary','version' => 1,'source_database' => 'Example database','source_engine' => 'other',
                        'tables' => [['schema_name' => 'public','table_name' => 'customers','object_type' => 'table','description' => 'Customer master records.',
                            'columns' => [['column_name' => 'id','data_type' => 'INTEGER','ordinal_position' => 1,'is_nullable' => false,'is_primary_key' => true,'description' => 'Unique customer identifier.'],
                            ['column_name' => 'email','data_type' => 'VARCHAR','char_length' => 255,'is_nullable' => true,'sensitive_flag' => true,'description' => 'Customer email address.']]]]], 'dictionary_metadata_example.json');
                    // no break
                case 'metadata_export':
                    $source = DataDictionary::Text($_GET['source_database'] ?? null, 150, 'Database label', true);
                    
                    $statement = $db->table('dd_tables')->where('source_database', $source)->orderBy('schema_name,table_name', '', false)->get();
                    $tables = $statement->getResultArray();
                    if (!$tables)
                    {
                        JsonError('No documented tables found.', 404);
                    }
                    if (count($tables) > 500)
                    {
                        JsonError('This database exceeds the portable export limit of 500 tables.');
                    }
                    $count = 0;
                    foreach ($tables as &$table)
                    {
                        
                        $statement = $db->table('dd_columns')->where('table_id', $table['table_id'])->orderBy('ordinal_position NULLS LAST,column_name', '', false)->get();
                        $columns = $statement->getResultArray();
                        if (count($columns) > 2000 || ($count += count($columns)) > 25000)
                        {
                            JsonError('This database exceeds the portable column export limits.');
                        }
                        foreach ($columns as &$column)
                        {
                            foreach (['column_id','table_id','created_at','updated_at'] as $key)
                            {
                                unset($column[$key]);
                            }
                        }
                        unset($column);
                        $table['columns'] = $columns;
                        foreach (['table_id','created_at','updated_at'] as $key)
                        {
                            unset($table[$key]);
                        }
                    }
                    unset($table);
                    DdDownload(['format' => 'devatlas-dictionary','version' => 1,'source_database' => $source,'tables' => $tables], 'dictionary_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $source).'.json');
            }
        }
        catch (InvalidArgumentException $e)
        {
            JsonError($e->getMessage());
        }
        catch (DatabaseException $e)
        {
            if (Database::InTransaction($db))
            {
                $db->transRollback();
            }
            if (Database::SqlState($e) === '23505')
            {
                JsonError('That table or column name already exists in this database/schema.');
            }
            if (Database::SqlState($e) === '42703')
            {
                JsonError('The dictionary database needs migration_014_dictionary_engines.sql.', 409);
            }
            JsonError('The metadata operation failed. Check connection access and database configuration.', 500);
        }
        catch (RuntimeException $e)
        {
            JsonError($e->getMessage(), 400);
        }
    }

    public function ExcelExport()
    {
        require_once APPPATH . 'Helpers/datadict_helper.php';
        /**
         * modules/datadict/excel_export.php
         * Exports every documented table (and its columns) belonging to ONE
         * source database into a single .xlsx, formatted to match the
         * "ANNEX E â€“ Data Dictionary" reference template:
         *   - Bold title banner
         *   - Dark-blue header row: Table Name | Field Name | Datatype/Size |
         *     Null | PK | FK | Auto Increment | Description
         *   - Table Name column merged vertically per table, first row of each
         *     table shaded gray
         *   - Hairline borders throughout
         *
         * GET excel_export.php?source_database=My+Database
         */
        require_once (ROOTPATH . 'modules/datadict') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/datadict') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/datadict') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/datadict') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/datadict') . '/../../lib/vendor_bootstrap.php';
        require_once (ROOTPATH . 'modules/datadict') . '/../../lib/DataDictionary.php';

        Auth::RequirePage();
        // opened via a direct link/new tab, so redirect to login rather than a JSON error
        RequireVendorAutoload();

        $sourceDatabase = CleanStr($_GET['source_database'] ?? null);
        if (!$sourceDatabase)
        {
            http_response_code(400);
            die('source_database is required.');
        }

        $db = Database::GetConnection('app');

        
        $tStmt = $db->table('dd_tables')->where('source_database', $sourceDatabase)->orderBy('schema_name, table_name', '', false)->get();
        $tables = $tStmt->getResultArray();

        if (!$tables)
        {
            http_response_code(404);
            die('No documented tables found for this database.');
        }

        $tableIds = array_column($tables, 'table_id');
        $placeholders = implode(',', array_fill(0, count($tableIds), '?'));
        $cStmtSql = "SELECT * FROM dd_columns WHERE table_id IN ($placeholders) ORDER BY table_id, ordinal_position NULLS LAST, column_name";
        $cStmt = $db->query($cStmtSql, $tableIds);
        $columnsByTable = [];
        foreach ($cStmt->getResultArray() as $c)
        {
            $columnsByTable[$c['table_id']][] = $c;
        }

        /** Formats "varchar(50)", "decimal(10,2)", or plain "bigint" from stored type parts. */


        // ---------------------------------------------------------------------
        // Build the workbook
        // ---------------------------------------------------------------------

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Dictionary');
        $spreadsheet->getProperties()
            ->setTitle('Data Dictionary - ' . $sourceDatabase)
            ->setDescription('DevAtlas Data Dictionary export');

        $HEADER_FILL = '1F4E78';
        $GROUP_FILL = 'DDDDDD';
        $HEADER_FONT_COLOR = 'FFFFFF';

        // --- Title banner (merged, matches the reference template) ---
        $sheet->mergeCells('A1:C3');
        $sheet->setCellValue('A1', 'ANNEX E â€“ DATA DICTIONARY: ' . strtoupper($sourceDatabase));
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['name' => 'Consolas', 'size' => 20, 'bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        for ($r = 1; $r <= 3; $r++)
        {
            $sheet->getRowDimension($r)->setRowHeight(13.8);
        }

        // --- Column headers (row 4) ---
        $headers = ['Table Name', 'Field Name', 'Datatype/Size', 'Null', 'PK', 'FK', 'Auto Increment', 'Description'];
        $col = 'A';
        foreach ($headers as $h)
        {
            $sheet->setCellValue("{$col}4", $h);
            $col++;
        }
        $sheet->getStyle('A4:H4')->applyFromArray([
            'font' => ['name' => 'Consolas', 'size' => 10.5, 'bold' => true, 'color' => ['rgb' => $HEADER_FONT_COLOR]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $HEADER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(28);

        // --- Data rows, grouped per table ---
        $row = 5;
        foreach ($tables as $table)
        {
            $columns = $columnsByTable[$table['table_id']] ?? [];
            if (!$columns)
            {
                // Still list the table even if no columns are documented yet, so the export is complete.
                $columns = [null];
            }
            $startRow = $row;

            foreach ($columns as $i => $col)
            {
                $isGroupFirstRow = ($i === 0);

                if ($col === null)
                {
                    $sheet->setCellValue("B{$row}", '(no columns documented)');
                }
                else
                {
                    $sheet->setCellValueExplicit("B{$row}", $col['column_name'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("C{$row}", FormatDataType($col), DataType::TYPE_STRING);
                    $sheet->setCellValue("D{$row}", $col['is_nullable'] ? 'NULL' : 'NOT NULL');
                    $sheet->setCellValue("E{$row}", $col['is_primary_key'] ? 'Yes' : 'No');
                    $sheet->setCellValue("F{$row}", $col['is_foreign_key'] ? 'Yes' : 'No');
                    $sheet->setCellValue("G{$row}", $col['is_auto_increment'] ? 'Yes' : 'No');
                    $sheet->setCellValueExplicit("H{$row}", $col['description'] ?? '', DataType::TYPE_STRING);
                }

                $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                    'font' => ['name' => 'Calibri', 'size' => 11],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                ]);
                if ($isGroupFirstRow)
                {
                    $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GROUP_FILL]],
                    ]);
                }
                $sheet->getRowDimension($row)->setRowHeight(15);
                $row++;
            }

            $endRow = $row - 1;
            $sheet->setCellValueExplicit("A{$startRow}", $table['schema_name'].'.'.$table['table_name'], DataType::TYPE_STRING);
            if ($endRow > $startRow)
            {
                $sheet->mergeCells("A{$startRow}:A{$endRow}");
            }
            $sheet->getStyle("A{$startRow}")->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'font' => ['bold' => true],
            ]);
        }

        // --- Column widths (matches the reference template) ---
        $sheet->getColumnDimension('A')->setWidth(36.58);
        $sheet->getColumnDimension('B')->setWidth(24);
        $sheet->getColumnDimension('C')->setWidth(20.6);
        $sheet->getColumnDimension('D')->setWidth(12.96);
        $sheet->getColumnDimension('E')->setWidth(6.16);
        $sheet->getColumnDimension('F')->setWidth(20);
        $sheet->getColumnDimension('G')->setWidth(13.52);
        $sheet->getColumnDimension('H')->setWidth(72.57);

        $sheet->setSelectedCell('A1');
        $sheet->freezePane('B5');
        $details = $spreadsheet->createSheet();
        $details->setTitle('Field Details');
        $headers = ['Database','Engine','Schema','Table / view','Object type','Business owner','Field','Type','Nullable','Primary key','Foreign key','Identity','Reference table','Reference field','Default','Description','Business rule','Sensitive / PII'];
        foreach ($headers as $index => $header)
        {
            $details->setCellValueExplicitByColumnAndRow($index + 1, 1, $header, DataType::TYPE_STRING);
        }
        $details->getStyle('A1:R1')->applyFromArray(['font' => ['bold' => true,'color' => ['rgb' => 'FFFFFF']],'fill' => ['fillType' => Fill::FILL_SOLID,'startColor' => ['rgb' => $HEADER_FILL]]]);
        $detailRow = 2;
        foreach ($tables as $table)
        {
            foreach ($columnsByTable[$table['table_id']] ?? [] as $column)
            {
                $values = [$sourceDatabase,$table['source_engine'] ?? '',$table['schema_name'],$table['table_name'],$table['object_type'] ?? 'table',$table['business_owner'],$column['column_name'],FormatDataType($column),$column['is_nullable'] ? 'Yes' : 'No',$column['is_primary_key'] ? 'Yes' : 'No',$column['is_foreign_key'] ? 'Yes' : 'No',$column['is_auto_increment'] ? 'Yes' : 'No',$column['fk_ref_table'],$column['fk_ref_column'],$column['default_value'],$column['description'],$column['business_rule'],$column['sensitive_flag'] ? 'Yes' : 'No'];
                foreach ($values as $index => $value)
                {
                    $details->setCellValueExplicitByColumnAndRow($index + 1, $detailRow, mb_substr((string)$value, 0, 32000), DataType::TYPE_STRING);
                }
                $detailRow++;
            }
        }
        $details->freezePane('G2');
        $details->setAutoFilter('A1:R'.max(2, $detailRow - 1));
        foreach (range('A', 'R') as $column)
        {
            $details->getColumnDimension($column)->setWidth(in_array($column, ['O','P','Q'], true) ? 55 : 22);
        }
        $details->getStyle('A2:R'.max(2, $detailRow - 1))->getAlignment()->setWrapText(true);
        $spreadsheet->setActiveSheetIndex(0);

        // ---------------------------------------------------------------------
        // Stream the file
        // ---------------------------------------------------------------------

        $filename = 'DataDictionary_' . preg_replace('/[^A-Za-z0-9_\-]+/', '_', $sourceDatabase) . '.xlsx';

        Header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        Header('Content-Disposition: attachment; filename="' . $filename . '"');
        Header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->Save('php://output');
    }

    public function Index()
    {
        require_once APPPATH . 'Helpers/datadict_helper.php';
        $pageTitle = 'Data Dictionary';
        $pageSubtitle = 'Explore structures, document fields and import metadata across database engines';
        $activeNav = 'datadict';
        $bodyClass = 'dictionary-page';
        $pageStyles = ['assets/css/datadict.css'];
        $pageScript = 'assets/js/datadict.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('datadict/index', get_defined_vars());
    }
}
