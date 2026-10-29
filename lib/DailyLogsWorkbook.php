<?php

/**
 * Filename: DailyLogsWorkbook.php
 * Purpose: Provide daily logs workbook processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

require_once __DIR__.'/WeeklySummaryWorkbook.php';
class DailyLogsWorkbook
{
    public static function Build(array $data): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->removeSheetByIndex(0);
        $used = [];
        $book->getProperties()->setTitle('Section Daily Logs');
        foreach ($data['reports'] as $report)
        {
            $employee = $report['employee'];
            $sheet = $book->createSheet();
            $sheet->setTitle(WeeklySummaryWorkbook::SheetTitle($employee, $used));
            $literal = static function (string $cell, string $value) use ($sheet): void
            {
                $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            };
            foreach ([1,2,3,4] as $r)
            {
                $sheet->mergeCells('A'.$r.':O'.$r);
            }
            $literal('A1', 'DAILY LOGS');
            $literal('A2', $employee['report_name']);
            $literal('A3', $employee['employee_name'].' ('.$employee['employee_id'].') | '.$data['section']['unit_name']);
            $literal('A4', $data['date_from'].' – '.$data['date_to'].' | '.($data['status'] ?: 'All submitted, reviewed and returned entries').' | Private drafts excluded');
            $sheet->fromArray([['Date','Entry Type','Classification','Activity Type','Projected / Planned Task','Actual Activity Performed','Hours','Leave Type','Leave Period','Remarks','Status','Review Comment','Reviewed / Returned By','Review Date','Submitted Date']], null, 'A5');
            $r = 6;
            foreach ($report['logs'] as $log)
            {
                $values = ['A' => $log['activity_date'],'B' => $log['entry_kind'] === 'activity' ? 'Work activity' : 'Leave / Undertime','C' => $log['classification'] ?? '',
                    'D' => $log['activity_type'] ?? '','E' => $log['planned_task'],'F' => $log['actual_activity'],'H' => $log['leave_type'] ?? '',
                    'I' => $log['leave_type'] === 'UT' ? 'Undertime' : (['AM' => 'AM','PM' => 'PM','WHOLE' => 'Whole day'][$log['leave_period'] ?? ''] ?? ''),
                    'J' => $log['remarks'],'K' => $log['status'],'L' => $log['review_comment'],'M' => $log['reviewer_name'] ?? ''];
                foreach (['N' => 'reviewed_at','O' => 'submitted_at'] as $col => $key)
                {
                    $values[$col] = empty($log[$key]) ? '' : (new DateTimeImmutable($log[$key]))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
                }
                foreach ($values as $col => $value)
                {
                    $literal($col.$r, (string)$value);
                }
                $sheet->setCellValue('G'.$r, (float)$log['duration_hours']);
                $r++;
            }
            $last = max(6, $r - 1);
            if (!$report['logs'])
            {
                $sheet->mergeCells('A6:O6');
                $literal('A6', 'No daily logs match this period and status.');
            }
            foreach (['Total logged hours' => $report['work_hours'] + $report['leave_hours'],'Work hours' => $report['work_hours'],'Leave / undertime hours' => $report['leave_hours']] as $label => $hours)
            {
                $row = $last + 1;
                $sheet->mergeCells('A'.$row.':F'.$row);
                $literal('A'.$row, $label);
                $sheet->setCellValue('G'.$row, $hours);
                $last++;
            }
            $sheet->getStyle('A1:O'.$last)->getFont()->setName('Calibri')->setSize(11);
            $sheet->getStyle('A1:O'.$last)->getAlignment()->setVertical('top')->setWrapText(true);
            $sheet->getStyle('A1:O2')->getFill()->setFillType('solid')->getStartColor()->setRGB('F1BA8D');
            $sheet->getStyle('A1:O2')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1:O4')->getAlignment()->setHorizontal('center');
            $sheet->getStyle('A5:O5')->getFill()->setFillType('solid')->getStartColor()->setRGB('B8D1EE');
            $sheet->getStyle('A5:O5')->getFont()->setBold(true);
            $sheet->getStyle('G6:G'.$last)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('A'.($last - 2).':O'.$last)->getFont()->setBold(true);
            $sheet->getStyle('A5:O'.$last)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('CBD5E1');
            foreach (['A' => 14,'B' => 20,'C' => 16,'D' => 16,'E' => 42,'F' => 48,'G' => 12,'H' => 14,'I' => 16,'J' => 38,'K' => 16,'L' => 38,'M' => 24,'N' => 22,'O' => 22] as $col => $width)
            {
                $sheet->getColumnDimension($col)->setWidth($width);
            }
            $sheet->getRowDimension(1)->setRowHeight(28);
            $sheet->getRowDimension(2)->setRowHeight(28);
            $sheet->getRowDimension(5)->setRowHeight(32);
            $sheet->freezePane('C6');
            $sheet->setAutoFilter('A5:O'.max(5, $r - 1));
            $sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A3)->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 5)->setPrintArea('A1:O'.$last);
        }
        $book->setActiveSheetIndex(0);
        return $book;
    }
}
