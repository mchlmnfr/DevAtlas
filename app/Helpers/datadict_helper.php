<?php
/**
 * Filename: datadict_helper.php
 * Purpose: Provide reusable Datadict request validation and export helpers.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

use CodeIgniter\Database\BaseConnection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function DdConnection(array $input, BaseConnection $localDb): array
{
    $id = (int)($input['connection_id'] ?? 0);
    if ($id > 0)
    {
        
        $statement = $localDb->table('dd_connections')->where('connection_id', $id)->get();
        $saved = $statement->getUnbufferedRow('array');
        if (!$saved)
        {
            JsonError('Saved connection not found.', 404);
        }
        if ($saved['password_encrypted'])
        {
            $saved['password'] = DataDictionary::Decrypt((string)$saved['password']);
        }
        else
        {
            $plain = (string)($saved['password'] ?? '');
            
            $statement = $localDb->table('dd_connections')->set('password', DataDictionary::Encrypt($plain))->set('password_encrypted', true)->where('connection_id', $id)->update();
            $saved['password'] = $plain;
        }
        return DatabaseEngines::Validate($saved);
    }
    return DatabaseEngines::Validate($input);
}

function DdDownload(array $document, string $filename): void
{
    $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($json) > 6 * 1024 * 1024)
    {
        JsonError('Portable export exceeds 6 MB. Use the Excel export for this database.');
    }
    Header('Content-Type: application/json; charset=utf-8');
    Header('Content-Disposition: attachment; filename="'.$filename.'"');
    Header('Cache-Control: no-store');
    echo $json;
    \FinishRequest();
}

function FormatDataType(array $localCol): string
{
    return DataDictionary::FormatType($localCol);
}
