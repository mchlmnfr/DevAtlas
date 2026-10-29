<?php

/**
 * Filename: WeeklySummaryWorkbook.php
 * Purpose: Provide weekly summary workbook processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** One formatted worksheet per employee, with literal names and numeric hours. */
class WeeklySummaryWorkbook
{
    public static function SheetTitle(array $employee, array &$used): string
    {
        $base = preg_replace('/[\\\\\/?*\[\]:\x00-\x1F]/u', '_', $employee['report_name']);
        $base = trim($base, " '");
        if ($base === '')
        {
            $base = 'Employee '.$employee['user_id'];
        }
        $title = mb_substr($base, 0, 31);
        $n = 1;
        while (isset($used[mb_strtolower($title)]))
        {
            $suffix = ' ('.++$n.')';
            $title = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
        }
        $used[mb_strtolower($title)] = true;
        return $title;
    }
    public static function Build(array $data): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->removeSheetByIndex(0);
        $used = [];
        $book->getProperties()->setTitle('Section Weekly Summaries');
        foreach ($data['reports'] as $report)
        {
            $employee = $report['employee'];
            $title = self::SheetTitle($employee, $used);
            $sheet = $book->createSheet();
            $sheet->setTitle($title);
            $literal = static function (string $cell, string $value) use ($sheet): void
            {
                $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            };
            foreach (['A1:E1','A2:E2','A3:E3'] as $range)
            {
                $sheet->mergeCells($range);
            }
            $literal('A1', 'WEEKLY SUMMARY');
            $literal('A2', $employee['report_name']);
            $literal('A3', $data['week_start'].' – '.$data['week_end'].' | '.$data['section']['unit_name'].' | '.$employee['employee_name'].' ('.$employee['employee_id'].')');
            $sheet->fromArray([['ACTIVITY TYPE','SD (Hrs)','SCR (Hrs)','Project (Hrs)','Total Hrs']], null, 'A4');
            foreach ($report['rows'] as $i => $row)
            {
                $r = $i + 5;
                $literal('A'.$r, $row['activity_type']);
                foreach (['B' => 'SD','C' => 'SCR','D' => 'PROJECT','E' => 'total'] as $col => $key)
                {
                    $sheet->setCellValue($col.$r, $row[$key]);
                }
            }
            $literal('A25', 'Total Hrs');
            foreach (['B' => 'SD','C' => 'SCR','D' => 'PROJECT','E' => 'total'] as $col => $key)
            {
                $sheet->setCellValue($col.'25', $report['totals'][$key]);
            }
            $literal('B27', 'Date');
            $literal('C27', 'Leave Type');
            $literal('D27', 'Leave Period');
            $literal('E27', 'Hours');
            $literal('A28', 'Leaves:');
            $leaveRow = 28;
            foreach ($report['leaves'] ?? [] as $leave)
            {
                $literal('B'.$leaveRow, $leave['activity_date']);
                $literal('C'.$leaveRow, $leave['leave_type']);
                $literal('D'.$leaveRow, $leave['leave_type'] === 'UT' ? 'Undertime' : (['AM' => 'AM','PM' => 'PM','WHOLE' => 'Whole day'][$leave['leave_period']] ?? ''));
                $sheet->setCellValue('E'.$leaveRow, (float)$leave['duration_hours']);
                $leaveRow++;
            }
            if ($leaveRow === 28)
            {
                $literal('B28', 'None');
                $leaveRow++;
            }
            $last = $leaveRow + 2;
            $sheet->mergeCells('A'.$last.':E'.$last);
            $literal('A'.$last, 'Approved work hours, Monday-Friday. Leave shown separately; UT in the activity table means Unit Testing.');
            $sheet->getStyle('A1:E'.$last)->getFont()->setName('Calibri')->setSize(11);
            $sheet->getStyle('A1:E'.$last)->getAlignment()->setHorizontal('center')->setVertical('center');
            foreach (['A1:E2','A25:E25'] as $range)
            {
                $sheet->getStyle($range)->getFill()->setFillType('solid')->getStartColor()->setRGB('FCD5B5');
                $sheet->getStyle($range)->getFont()->setBold(true);
            }
            $sheet->getStyle('A1:E2')->getFont()->setSize(16);
            $sheet->getStyle('A4:E4')->getFill()->setFillType('solid')->getStartColor()->setRGB('C6D9F1');
            $sheet->getStyle('A4:E4')->getFont()->setBold(true);
            $sheet->getStyle('A5:E25')->getFont()->setBold(true);
            $sheet->getStyle('E28:E'.$last)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('B5:E25')->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('A4:E25')->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('7D8795');
            $sheet->getStyle('A3:E4')->getAlignment()->setWrapText(true);
            $sheet->getStyle('A27:E'.$last)->getAlignment()->setWrapText(true);
            $sheet->getStyle('A3:E3')->getFont()->setSize(10);
            $sheet->getStyle('A27:E'.$last)->getFont()->setSize(9);
            foreach (['A','B','C','D','E'] as $col)
            {
                $sheet->getColumnDimension($col)->setWidth(19);
            }
            for ($r = 1;$r <= 25;$r++)
            {
                $sheet->getRowDimension($r)->setRowHeight($r <= 4 ? 30 : 22);
            }
            $sheet->getRowDimension(3)->setRowHeight(42);
            $sheet->freezePane('B5');
            $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(1)->setPrintArea('A1:E'.$last);
        }
        $book->setActiveSheetIndex(0);
        return $book;
    }
}
