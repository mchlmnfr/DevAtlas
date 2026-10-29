<?php

/**
 * Filename: ProgramInventoryReport.php
 * Purpose: Provide program inventory report processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/**
 * lib/ProgramInventoryReport.php
 * Shared logic for the Program Inventory / File Catalog exporter, used by
 * BOTH scan modes:
 *   - modules/proginventory/export.php        (scans a path on the SERVER's disk)
 *   - modules/proginventory/export_client.php (scans a folder on the USER's
 *     own machine, via the browser's File API — since this app is hosted
 *     centrally and users connect remotely, PHP has no access to their
 *     local disk; only their browser does)
 *
 * Both modes end up with the same normalized row shape and call
 * render_inventory_workbook() to produce an identically-styled .xlsx.
 */

require_once __DIR__ . '/vendor_bootstrap.php';

/** Derives an ID prefix from the initials of each word, e.g. "BMI Online Clearance" -> "BOC". */
function PiDeriveInitials(string $text): string
{
    $words = preg_split('/\s+/', trim($text)) ?: [];
    $initials = '';
    foreach ($words as $w)
    {
        if ($w === '')
        {
            continue;
        }
        $initials .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $initials !== '' ? $initials : 'SYS';
}

/**
 * Builds one normalized row (matching the 11-column layout) for a single file.
 *
 * @param string $fileName    Leaf filename, e.g. "index.php"
 * @param int    $sizeBytes   File size in bytes
 * @param int    $mtimeUnix   Last-modified time, Unix timestamp (seconds)
 * @param string $dirPath     Backslash-separated directory path, WITHOUT a
 *                             leading backslash (it's added here), e.g. "BMIPORTAL\HROCS"
 */
function PiBuildRow(string $idPrefix, int $seq, string $fileName, int $sizeBytes, int $mtimeUnix, string $dirPath, string $systemAffected, string $fileVersion, string $programmerDeveloper): array
{
    $ext = pathinfo($fileName, PATHINFO_EXTENSION);
    if ($ext === '')
    {
        $ext = 'FILE';
    }

    return [
        $idPrefix . '-' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT),
        $fileName,
        round($sizeBytes / 1024, 2),
        $ext,
        Date('m-d-Y h:i A', $mtimeUnix),
        $systemAffected,
        $fileVersion,
        $programmerDeveloper,
        '\\' . ltrim($dirPath, '\\'),
        $systemAffected, // INTERFACE (ACTION/SYSTEM) mirrors SYSTEM AFFECTED, per spec
        '', // REMARKS — left blank for manual annotation
    ];
}

/**
 * Renders the fully-styled Program Inventory workbook from an array of rows
 * (each row shaped exactly like pi_build_row()'s return value) and streams
 * it directly to the browser as an .xlsx download. Does not return.
 */
function PiStreamWorkbook(array $rows, string $systemAffected, string $sourceLabel, int $applicationId = 0, string $origin = 'server'): void
{
    RequireVendorAutoload();

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class))
    {
        http_response_code(500);
        die("PhpSpreadsheet isn't installed. Run 'composer install' in the application folder.");
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Program Inventory');
    $sheet->setShowGridlines(true);
    $spreadsheet->getProperties()
        ->setTitle('Program Inventory - ' . $systemAffected)
        ->setDescription('DevAtlas Program Inventory / File Catalog export');

    $HEADER_FILL = '1E3A8A';
    $ZEBRA_FILL = 'F8FAFC';
    $HEADER_FONT = 'Segoe UI';

    $headers = [
        'ID', 'FILE NAME', 'FILE SIZE (KB)', 'FILE TYPE', 'DATE MODIFIED', 'SYSTEM AFFECTED',
        'FILE VERSION', 'PROGRAMMER/DEVELOPER', 'PATH', 'INTERFACE (ACTION/SYSTEM)', 'REMARKS',
    ];

    $sheet->mergeCells('A1:K2');
    $sheet->setCellValue('A1', "PROGRAM INVENTORY\nSystem Affected: {$systemAffected}   |   Source: {$sourceLabel}");
    $sheet->getStyle('A1')->applyFromArray([
        'font' => ['name' => $HEADER_FONT, 'size' => 16, 'bold' => true, 'color' => ['rgb' => $HEADER_FILL]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(20);
    $sheet->getRowDimension(2)->setRowHeight(20);

    $headerRow = 3;
    $col = 'A';
    foreach ($headers as $h)
    {
        $sheet->setCellValue("{$col}{$headerRow}", $h);
        $col++;
    }
    $sheet->getStyle("A{$headerRow}:K{$headerRow}")->applyFromArray([
        'font' => ['name' => $HEADER_FONT, 'size' => 11, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $HEADER_FILL]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(24);
    $sheet->freezePane('A' . ($headerRow + 1));

    $centerCols = ['A', 'C', 'D', 'E', 'G', 'H'];
    $leftCols = ['B', 'F', 'I', 'J', 'K'];

    $row = $headerRow + 1;
    foreach ($rows as $i => $rowData)
    {
        $col = 'A';
        foreach ($rowData as $value)
        {
            if (is_string($value))
            {
                $sheet->setCellValueExplicit("{$col}{$row}", $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            else
            {
                $sheet->setCellValue("{$col}{$row}", $value);
            }
            $col++;
        }
        $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0.00');

        $isZebra = ($i % 2 === 1);
        $rowStyle = [
            'font' => ['name' => $HEADER_FONT, 'size' => 10],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => false],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'D0D5DD']]],
        ];
        if ($isZebra)
        {
            $rowStyle['fill'] = ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $ZEBRA_FILL]];
        }
        $sheet->getStyle("A{$row}:K{$row}")->applyFromArray($rowStyle);

        foreach ($centerCols as $c)
        {
            $sheet->getStyle("{$c}{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        }
        foreach ($leftCols as $c)
        {
            $sheet->getStyle("{$c}{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        }
        $row++;
    }

    foreach (range('A', 'K') as $c)
    {
        $sheet->getColumnDimension($c)->setAutoSize(true);
    }
    $sheet->setSelectedCell('A1');

    $filename = 'ProgramInventory_' . preg_replace('/[^A-Za-z0-9_\-]+/', '_', $systemAffected) . '_' . Date('Ymd_His') . '.xlsx';

    Header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    Header('Content-Disposition: attachment; filename="' . $filename . '"');
    Header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $inventoryDb = null;
    if ($applicationId)
    {
        require_once __DIR__.'/Applications.php';
        Permissions::require('applications');
        $inventoryDb = Database::GetConnection('app');
        $inventoryDb->transBegin();
        $lockSql = 'SELECT application_id FROM applications WHERE application_id=? FOR KEY SHARE';
        $lock = $inventoryDb->query($lockSql, [$applicationId]);
        if (!Database::Scalar($lock))
        {
            $inventoryDb->transRollback();
            throw new OutOfBoundsException('Application no longer exists.');
        }
        $inventoryDb->table('application_inventory_runs')->set('application_id', $applicationId)->set('created_by', (int)$_SESSION['user_id'])->set('origin', $origin)->set('file_count', count($rows))->set('file_version', mb_substr((string)($rows[0][6] ?? ''), 0, 100))->insert();
    }
    try
    {
        $writer->Save('php://output');
        if ($inventoryDb)
        {
            $inventoryDb->transCommit();
        }
    }
    catch (Throwable $e)
    {
        if ($inventoryDb && Database::InTransaction($inventoryDb))
        {
            $inventoryDb->transRollback();
        }
        throw $e;
    }
    \FinishRequest();
}
