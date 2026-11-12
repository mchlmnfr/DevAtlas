<?php
declare(strict_types=1);

namespace DevAtlas\DataConversion;

use RuntimeException;
use SplFileObject;

function validateTable(string $input, string $defaultSchema): array {
    $s = trim($input);
    $parts = explode('.', $s);
    if (count($parts) === 1) array_unshift($parts, $defaultSchema);
    if (count($parts) !== 2) throw new RuntimeException('Table must be a simple name or schema.table, e.g. dbo.ReportSales.');
    foreach ($parts as $p) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $p) || strlen($p) > 128)
            throw new RuntimeException('Table/schema names may contain only letters, numbers and underscores, starting with a letter or underscore.');
    }
    return $parts;
}
function quoteIdentifier(string $name): string { return '[' . str_replace(']', ']]', $name) . ']'; }
function utf8(string $v): string {
    if (function_exists('mb_check_encoding') && !mb_check_encoding($v, 'UTF-8')) throw new RuntimeException('CSV contains invalid UTF-8. Save it as UTF-8 CSV first.');
    if (!function_exists('mb_check_encoding') && preg_match('//u', $v) !== 1) throw new RuntimeException('CSV contains invalid UTF-8. Save it as UTF-8 CSV first.');
    return $v;
}
function cleanCsvCell(string $v): string {
    if (str_contains($v, "\0")) throw new RuntimeException('NUL byte found in a CSV value.');
    return utf8($v);
}
function openCsv(string $path, string $delimiter): SplFileObject {
    $f = new SplFileObject($path, 'rb');
    // DROP_NEW_LINE also strips newlines inside quoted cells on PHP 8.1.
    $f->setFlags(SplFileObject::READ_CSV);
    $f->setCsvControl($delimiter, '"', '');
    return $f;
}
function parseCsv(string $path, string $delimiter, array $cfg, ?array $schema, array $table, bool $collectPreview): array {
    $f = openCsv($path, $delimiter);
    $rawHeaders = $f->fgetcsv();
    if (!is_array($rawHeaders) || $rawHeaders === [null]) throw new RuntimeException('Empty CSV file.');
    $rawHeaders[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$rawHeaders[0]);
    $seen = []; $fields = []; $ignored = []; $all = [];
    foreach ($rawHeaders as $index => $value) {
        $column = trim(cleanCsvCell((string)$value));
        if ($column === '') throw new RuntimeException('CSV header ' . ($index + 1) . ' is blank.');
        if (strlen($column) > 128) throw new RuntimeException('CSV header is longer than SQL Server\'s 128-character identifier limit: ' . $column);
        $key = strtolower($column);
        if (isset($seen[$key])) throw new RuntimeException('Duplicate CSV column: ' . $column);
        $seen[$key] = true;
        $all[] = $column;
        if (in_array($key, array_map('strtolower', $cfg['system_columns']), true)) {
            $ignored[] = $column;
            continue;
        }
        $fields[] = ['index' => $index, 'name' => $column, 'key' => $key];
    }
    if (count($rawHeaders) > 1024) throw new RuntimeException('Use no more than 1,024 columns.');
    if (count($fields) === 0) throw new RuntimeException('No importable CSV columns (only system-managed fields were found).');
    if ($schema !== null) {
        $tk = strtolower($table[0] . '.' . $table[1]);
        if (!isset($schema[$tk])) throw new RuntimeException('Destination table is not present in the provided schema snapshot.');
        foreach ($fields as $field) {
            $spec = $schema[$tk][$field['key']] ?? null;
            if (!$spec) throw new RuntimeException('CSV column not found in destination table: ' . $field['name']);
            if ($spec['identity'] || $spec['computed']) throw new RuntimeException('Cannot insert into identity/computed column: ' . $field['name']);
        }
    }
    $rows = 0; $preview = [];
    while (!$f->eof()) {
        $data = $f->fgetcsv();
        if (!is_array($data) || $data === [null]) continue;
        if (count($data) === 1 && $data[0] === null) continue;
        $empty = true;
        foreach ($data as $cell) if ($cell !== null && trim((string)$cell) !== '') { $empty = false; break; }
        if ($empty) continue;
        if (count($data) !== count($rawHeaders)) throw new RuntimeException('CSV record ' . ($rows + 2) . ' has ' . count($data) . ' fields; expected ' . count($rawHeaders) . '.');
        foreach ($data as $cell) cleanCsvCell((string)($cell ?? ''));
        ++$rows;
        if ($rows > 100000) throw new RuntimeException('Use no more than 100,000 data rows per conversion.');
        if ($collectPreview && count($preview) < 5) {
            $record = [];
            foreach ($fields as $field) $record[$field['name']] = (string)($data[$field['index']] ?? '');
            $preview[] = $record;
        }
    }
    if ($rows === 0) throw new RuntimeException('No data rows found.');
    return ['fields' => $fields, 'headers' => $all, 'ignored' => $ignored, 'rows' => $rows, 'preview' => $preview];
}
function loadSchema(string $path): array {
    $f = openCsv($path, ',');
    $headers = $f->fgetcsv();
    if (!is_array($headers) || $headers === [null]) throw new RuntimeException('Schema snapshot is empty.');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $keys = array_map(static fn($x) => strtolower(trim((string)$x)), $headers);
    foreach (['tableschema','tablename','columnname'] as $required)
        if (!in_array($required, $keys, true)) throw new RuntimeException('Schema CSV must contain TableSchema, TableName and ColumnName headers.');
    $map = [];
    while (!$f->eof()) {
        $row = $f->fgetcsv(); if (!is_array($row) || $row === [null]) continue;
        if (count($row) !== count($keys)) throw new RuntimeException('Malformed schema snapshot row.');
        $r = array_combine($keys, $row);
        $ts = strtolower(trim((string)$r['tableschema']));
        $tn = strtolower(trim((string)$r['tablename']));
        $cn = strtolower(trim((string)$r['columnname']));
        if ($ts === '' || $tn === '' || $cn === '') continue;
        $map[$ts . '.' . $tn][$cn] = [
            'identity' => in_array(strtolower(trim((string)($r['isidentity'] ?? '0'))), ['1', 'true', 'yes'], true),
            'computed' => in_array(strtolower(trim((string)($r['iscomputed'] ?? '0'))), ['1', 'true', 'yes'], true),
        ];
    }
    if (!$map) throw new RuntimeException('No schema columns were found in the snapshot.');
    return $map;
}
function sqlLiteral(string $input, bool $blankAsNull): string {
    if ($blankAsNull && trim($input) === '') return 'NULL';
    if (str_contains($input, "\0")) throw new RuntimeException('NUL byte found in a CSV value.');
    // SQL Server 2012 supports N'...' Unicode string literals; conversion to target type is implicit.
    return "N'" . str_replace("'", "''", $input) . "'";
}
function emitSql(string $file, string $delimiter, array $cfg, array $table, array $fields): void {
    $qtable = quoteIdentifier($table[0]) . '.' . quoteIdentifier($table[1]);
    $cols = implode(', ', array_map(static fn($f) => quoteIdentifier($f['name']), $fields));
    echo "-- Central DB CSV to SQL converter | INSERT ONLY | SQL Server 2012\r\n";
    echo "-- Destination: $qtable\r\n";
    echo "-- SQL Server defaults/triggers are responsible for ReportCode/TableName if required.\r\n";
    echo "SET NOCOUNT ON;\r\nSET XACT_ABORT ON;\r\n";
    if ($cfg['wrap_transaction']) echo "BEGIN TRY\r\nBEGIN TRANSACTION;\r\n\r\n";
    $f = openCsv($file, $delimiter); $f->fgetcsv();
    $buffer = []; $total = 0;
    $writeBatch = static function(array $values) use($qtable, $cols): void {
        if (!$values) return;
        echo "INSERT INTO $qtable ($cols)\r\nVALUES\r\n" . implode(",\r\n", $values) . ";\r\n\r\n";
    };
    while (!$f->eof()) {
        $row = $f->fgetcsv();
        if (!is_array($row) || $row === [null]) continue;
        $blank = true;
        foreach ($row as $v) if ($v !== null && trim((string)$v) !== '') { $blank = false; break; }
        if ($blank) continue;
        $vals = [];
        foreach ($fields as $field) $vals[] = sqlLiteral((string)($row[$field['index']] ?? ''), $cfg['blank_as_null']);
        $buffer[] = '(' . implode(', ', $vals) . ')';
        ++$total;
        if (count($buffer) >= $cfg['batch_size']) { $writeBatch($buffer); $buffer = []; }
    }
    $writeBatch($buffer);
    if ($cfg['wrap_transaction']) echo "COMMIT TRANSACTION;\r\nEND TRY\r\nBEGIN CATCH\r\nIF XACT_STATE() <> 0 ROLLBACK TRANSACTION;\r\nTHROW;\r\nEND CATCH;\r\n";
    echo "-- $total row(s) generated.\r\n";
}

/** Normalize one or all Department workbook sheets to the actual consol_data columns. */
function departmentWorkbookToCsv(string $xlsxFile, string $selectedSheet, string $csvFile, int $limit): void {
    require_once __DIR__ . '/xlsx.php';
    $sheets = getXlsxSheets($xlsxFile, $limit, $selectedSheet);
    if ($selectedSheet !== '__ALL__' && !isset($sheets[$selectedSheet])) throw new RuntimeException('Worksheet not found.');
    $wanted = ['ReportCode','SMFCode','ReportName','Recipient','Department',
               'GroupCode','Frequency','Schedule','Fields','SourceSystem','Remarks'];
    $fh = fopen($csvFile, 'wb');
    if (!$fh) throw new RuntimeException('Cannot write temporary CSV.');
    $found = 0;
    try {
        fputcsv($fh, $wanted, ',', '"', '');
        foreach ($sheets as $name => $rows) {
            if ($selectedSheet !== '__ALL__' && $name !== $selectedSheet) continue;
            if ($name === 'Summary Count Per Dept') {
                if ($selectedSheet !== '__ALL__') throw new RuntimeException('Summary Count Per Dept is not a report-data worksheet.');
                continue;
            }
            $first = null; $map = [];
            foreach ($rows as $rowNo => $values) {
                if (!array_filter($values, static fn($v) => trim((string)$v) !== '')) continue;
                if ($first === null) {
                    $first = $rowNo;
                    foreach ($values as $ix => $raw) {
                        if (trim((string)$raw) === '') continue;
                        $col = consolHeaderName((string)$raw);
                        if ($col === null) throw new RuntimeException('Unrecognized column in ' . $name . ': ' . (string)$raw);
                        if (isset($map[$col])) throw new RuntimeException('Duplicate header ' . $col . ' in ' . $name);
                        $map[$col] = $ix;
                    }
                    if (!isset($map['ReportCode'], $map['ReportName'])) throw new RuntimeException('Missing required report headers in ' . $name);
                    continue;
                }
                $out = [];
                foreach ($values as $ix => $value) {
                    if (trim((string)$value) !== '' && !in_array($ix, $map, true)) throw new RuntimeException('Worksheet ' . $name . ' row ' . $rowNo . ' contains data without a header.');
                }
                foreach ($wanted as $column) $out[] = (string)($values[$map[$column] ?? -1] ?? '');
                if (!array_filter($out, static fn($v) => trim($v) !== '')) continue;
                fputcsv($fh, $out, ',', '"', '');
                $found++;
                if ($found > 100000) throw new RuntimeException('Use no more than 100,000 data rows per conversion.');
            }
        }
    } finally { fclose($fh); }
    if (!$found) throw new RuntimeException('No report records in selected worksheet(s).');
}
