<?php
declare(strict_types=1);

namespace DevAtlas\DataConversion;

use RuntimeException;
use SplFileObject;

const CONSOL_COLUMNS = [
    'ReportCode', 'SMFCode', 'ReportName', 'TableName', 'Recipient',
    'Department', 'GroupCode', 'Frequency', 'Schedule', 'Fields',
    'SourceSystem', 'Remarks', 'InsertedAt'
];
const CONSOL_OPTIONAL_LENGTHS = [
    'SMFCode' => 100, 'Department' => 150, 'GroupCode' => 150,
    'Frequency' => 100, 'Schedule' => 250, 'SourceSystem' => 150,
];
/** Recognize exact database names and the headings in (1) Department.xlsx. */
function consolHeaderName(string $header): ?string {
    $key = strtolower(trim(preg_replace('/\s+/u', ' ', $header)));
    $key = preg_replace('/\s*\([^)]*\)/u', '', $key);
    $key = preg_replace('/[^a-z0-9]+/u', '', $key);
    $aliases = [
        'reportcode'=>'ReportCode', 'smfcode'=>'SMFCode', 'smfnumbers'=>'SMFCode',
        'reportname'=>'ReportName', 'dataextractfiles'=>'ReportName',
        'tablename'=>'TableName', 'recipient'=>'Recipient',
        'department'=>'Department', 'group'=>'GroupCode', 'groupcode'=>'GroupCode',
        'frequency'=>'Frequency', 'schedule'=>'Schedule',
        'fields'=>'Fields', 'source'=>'SourceSystem', 'sourcesystem'=>'SourceSystem',
        'remarks'=>'Remarks', 'insertedat'=>'InsertedAt',
    ];
    // The workbook contains parenthetical text in the headings and newlines.
    // Some cells use a spelling variant such as "Remarks (dcod comments)".
    return $aliases[$key] ?? null;
}
function consolLength(string $str): int {
    if (function_exists('mb_strlen')) return mb_strlen($str, 'UTF-8');
    return preg_match_all('/./us', $str) ?: 0;
}
function consolRead(string $file, string $delimiter, bool $preview = true): array {
    $fh = openCsv($file, $delimiter);
    $headers = $fh->fgetcsv();
    if (!is_array($headers) || $headers === [null]) throw new RuntimeException('Worksheet has no header row.');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $columnMap = []; $ignored = [];
    
    foreach ($headers as $ix => $value) {
        $name = trim(utf8((string)$value));
        if ($name === '') throw new RuntimeException('Blank source header at column ' . ($ix + 1));
        $real = consolHeaderName($name);
        if ($real === null) throw new RuntimeException('Column ' . $name . ' cannot be mapped to dbo.consol_data.');
        if (isset($columnMap[$real]) || in_array($real, $ignored, true)) throw new RuntimeException('Duplicate source header: ' . $real);
        if (in_array($real, ['TableName', 'InsertedAt'], true)) { $ignored[] = $real; continue; }
        $columnMap[$real] = $ix;
    }
    foreach (['ReportCode', 'ReportName'] as $required) {
        if (!array_key_exists($required, $columnMap)) throw new RuntimeException('Source must include a ' . $required . ' column.');
    }
    $count = 0; $seen = []; $sample = [];
    while (!$fh->eof()) {
        $cells = $fh->fgetcsv();
        if (!is_array($cells) || $cells === [null]) continue;
        $hasValue = false;
        foreach ($cells as $cell) if ($cell !== null && trim((string)$cell) !== '') { $hasValue = true; break; }
        if (!$hasValue) continue;
        if (count($cells) !== count($headers)) throw new RuntimeException('Record ' . ($count + 2) . ': column count does not match the header.');
        $data = consolValidateRow($cells, $columnMap, $count + 2);
        $code = $data['ReportCode'];
        if (isset($seen[$code])) throw new RuntimeException('Duplicate ReportCode in upload: ' . $code . ' (rows ' . $seen[$code] . ' and ' . ($count + 2) . ').');
        $seen[$code] = $count + 2;
        $count++;
        if ($count > 100000) throw new RuntimeException('Use no more than 100,000 data rows per conversion.');
        if ($preview && count($sample) < 5) $sample[] = $data;
    }
    if ($count === 0) throw new RuntimeException('No data rows in worksheet.');
    $ordered = array_values(array_filter(CONSOL_COLUMNS, static fn($c) => $c !== 'InsertedAt' && ($c === 'TableName' || isset($columnMap[$c]))));
    return ['rows' => $count, 'columns' => $ordered, 'preview' => $sample, 'ignored' => $ignored];
}
function consolValidateRow(array $cells, array $columnMap, int $rowNum): array {
    $data = [];
    foreach ($columnMap as $col => $index) {
        $value = utf8((string)($cells[$index] ?? ''));
        if (str_contains($value, "\0")) throw new RuntimeException("Row $rowNum: NUL byte in $col.");
        $data[$col] = trim($value) === '' ? null : $value;
    }
    $code = trim((string)($data['ReportCode'] ?? ''));
    if (!preg_match('/^RPT-[0-9]{4}-[0-9]{3}$/D', $code)) throw new RuntimeException("Row $rowNum: ReportCode must match RPT-0000-000, got '" . $code . "'.");
    $data['ReportCode'] = $code;
    if (trim((string)($data['ReportName'] ?? '')) === '') throw new RuntimeException("Row $rowNum: ReportName is required.");
    if (consolLength((string)$data['ReportName']) > 500) throw new RuntimeException("Row $rowNum: ReportName exceeds 500 characters.");
    foreach (CONSOL_OPTIONAL_LENGTHS as $col => $max) {
        if (isset($data[$col]) && consolLength($data[$col]) > $max) throw new RuntimeException("Row $rowNum: $col exceeds $max characters.");
    }
    $data['TableName'] = strtolower(str_replace('-', '_', $code));
    return $data;
}
/**
 * Generate one INSERT per report, matching the existing trigger's single-row requirement.
 * ReportCode is explicitly supplied and never created or changed by this PHP tool.
 * SQL Server performs live duplicate checks at execution time.
 */
function consolSql(string $file, string $delimiter, int $unusedBatchSize = 500): void {
    $structure = consolRead($file, $delimiter, false);
    $columns = $structure['columns'];
    $quoted = implode(', ', array_map(static fn ($column) => quoteIdentifier($column), $columns));
    echo "-- Central DB dbo.consol_data | SQL Server 2012 | INSERT ONLY\r\n";
    echo "-- Each INSERT is one row: compatible with triggers requiring a single-row INSERT.\r\n";
    echo "-- ReportCode comes from the source, TableName is derived, InsertedAt uses its default.\r\n";
    echo "-- Existing ReportCode OR TableName is skipped at execution time (no overwrite).\r\n";
    echo "-- NOTE: The existing trigger must honor explicitly supplied ReportCode values.\r\n";
    echo "-- If trg_consol_data_AutoCode always replaces supplied values, its logic must be adjusted.\r\n";
    echo "USE [central_db];\r\nGO\r\n";
    echo "SET NOCOUNT ON;\r\nSET XACT_ABORT ON;\r\n";
    echo "DECLARE @InsertedRows int = 0, @SkippedRows int = 0;\r\n";
    echo "BEGIN TRY\r\n    BEGIN TRANSACTION;\r\n\r\n";

    $fh = openCsv($file, $delimiter);
    $headers = $fh->fgetcsv();
    $map = [];
    foreach ($headers as $ix => $head) {
        $key = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$head));
        $col = consolHeaderName($key);
        if ($col !== null && !in_array($col, ['TableName', 'InsertedAt'], true)) $map[$col] = $ix;
    }
    $row = 0;
    while (!$fh->eof()) {
        $cells = $fh->fgetcsv();
        if (!is_array($cells) || $cells === [null]) continue;
        $nonempty = false;
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string)$cell) !== '') { $nonempty = true; break; }
        }
        if (!$nonempty) continue;
        $data = consolValidateRow($cells, $map, $row + 2);
        $values = [];
        foreach ($columns as $column) {
            $value = $data[$column] ?? null;
            $values[] = $value === null ? 'NULL' : sqlLiteral($value, false);
        }
        $code = sqlLiteral($data['ReportCode'], false);
        $table = sqlLiteral($data['TableName'], false);
        echo "    IF NOT EXISTS (\r\n";
        echo "        SELECT 1 FROM [dbo].[consol_data] WITH (UPDLOCK, HOLDLOCK)\r\n";
        echo "        WHERE [ReportCode] = $code OR [TableName] = $table\r\n";
        echo "    )\r\n    BEGIN\r\n";
        echo "        INSERT INTO [dbo].[consol_data] ($quoted)\r\n";
        echo "        VALUES (" . implode(', ', $values) . ");\r\n";
        echo "        SET @InsertedRows = @InsertedRows + 1;\r\n";
        echo "    END\r\n    ELSE SET @SkippedRows = @SkippedRows + 1;\r\n\r\n";
        $row++;
    }
    echo "    COMMIT TRANSACTION;\r\n";
    echo "    SELECT @InsertedRows AS InsertedRows, @SkippedRows AS SkippedExistingRows;\r\n";
    echo "END TRY\r\nBEGIN CATCH\r\n";
    echo "    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;\r\n";
    echo "    THROW;\r\nEND CATCH;\r\nGO\r\n";
    echo "-- Source rows: $row. Re-running this SQL skips codes already stored in dbo.consol_data.\r\n";
}
