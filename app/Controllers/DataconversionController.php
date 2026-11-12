<?php
namespace App\Controllers;

use Auth;
use DataConversion;
use DomainException;
use Permissions;
use RuntimeException;

/** Data Management / offline SQL conversion; destination databases are never contacted. */
class DataconversionController extends BaseController
{
    private function Boot(): void
    {
        require_once ROOTPATH . 'lib/Auth.php';
        require_once ROOTPATH . 'lib/helpers.php';
        require_once ROOTPATH . 'lib/DataConversion.php';
    }

    public function Index()
    {
        $this->Boot();
        Auth::RequirePage();
        Permissions::Require('dataconversion');
        $pageTitle = 'Data Conversion';
        $pageSubtitle = 'Validate CSV and Excel data and create SQL Server scripts';
        $activeNav = 'dataconversion';
        $bodyClass = 'workspace-page conversion-page';
        $pageStyles = ['assets/css/workspace.css', 'assets/css/data-conversion.css'];
        $pageScript = 'assets/js/data-conversion.js';
        $uploadLimit = round(DataConversion::UploadLimit() / 1048576, 1);
        return $this->Render('dataconversion/index', get_defined_vars());
    }

    public function Api()
    {
        $this->Boot();
        Auth::RequireApi();
        $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
        $read = in_array($action, ['state', 'template', 'schema_query'], true);
        if (!in_array($action, ['state', 'template', 'schema_query', 'upload', 'worksheet', 'clear', 'download'], true)) JsonError('Unknown action.', 404);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== ($read ? 'GET' : 'POST')) JsonError('Invalid request method.', 405);
        Permissions::Require('dataconversion', $action === 'download' ? 'export' : ($read ? 'view' : 'create'));
        if (!$read) RequireCsrf();
        try {
            if ($action === 'state') JsonResponse(['job' => DataConversion::State()]);
            if ($action === 'template') {
                $csv = "ReportCode,SMFCode,ReportName,Recipient,Department,GroupCode,Frequency,Schedule,Fields,SourceSystem,Remarks\r\nRPT-2026-001,,Example report,,Example department,,Daily,09:00,Example field,Example system,Replace this sample row\r\n";
                return $this->response->download('consol_data_template.csv', $csv)->setContentType('text/csv');
            }
            if ($action === 'schema_query') {
                $sql = "-- Run in the destination SQL Server database and export results as UTF-8 CSV with headers.\r\nSELECT s.name AS TableSchema, t.name AS TableName, c.name AS ColumnName,\r\n       CAST(c.is_identity AS int) AS IsIdentity, CAST(c.is_computed AS int) AS IsComputed\r\nFROM sys.tables t\r\nJOIN sys.schemas s ON t.schema_id = s.schema_id\r\nJOIN sys.columns c ON t.object_id = c.object_id\r\nORDER BY s.name, t.name, c.column_id;\r\n";
                return $this->response->download('schema_export.sql', $sql)->setContentType('application/sql');
            }
            if ($action === 'upload') JsonResponse(['job' => DataConversion::Upload($_POST, $_FILES)]);
            if ($action === 'worksheet') JsonResponse(['job' => DataConversion::Worksheet(ReadJsonBody())]);
            // Downloads use multipart POST with the shared CSRF header.
            $data = $action === 'download' ? $_POST : ReadJsonBody();
            $id = $data['job_id'] ?? '';
            if (!is_string($id)) throw new DomainException('Invalid conversion preview.');
            if ($action === 'clear') { DataConversion::Clear($id); JsonResponse(['job' => null]); }
            $file = DataConversion::Download($id);
            return $this->response->download($file['path'], null)->setFileName($file['filename'])->setContentType('application/sql');
        } catch (DomainException | RuntimeException $e) {
            // Validation errors can contain source values; return them without writing uploaded data to logs.
            JsonResponse(['error' => $e->getMessage()], 422);
        }
    }
}
