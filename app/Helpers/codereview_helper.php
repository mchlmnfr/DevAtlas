<?php
/**
 * Filename: codereview_helper.php
 * Purpose: Provide reusable Codereview request validation and export helpers.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Ods;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function CrSetText($sheet, int $column, int $row, $value): void
{
    $sheet->setCellValueExplicitByColumnAndRow($column, $row, mb_substr((string)$value, 0, 32000), DataType::TYPE_STRING);
}

function CrHeading($sheet, array $headers): void
{
    foreach ($headers as $index => $header)
    {
        CrSetText($sheet, $index + 1, 1, $header);
    }
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle("A1:{$last}1")->applyFromArray([
        'font' => ['name' => 'Segoe UI', 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
    ]);
    $sheet->freezePane('A2');
}
