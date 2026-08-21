<?php

namespace App\Services\Reporting;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ReportExcelWriter — turns a ReportDataService payload into an .xlsx download.
 *
 * Sheet 1 "Summary": title, period, KPIs and the summary tables (same content
 * as the PDF). Then one sheet per detail table (full row-level data).
 */
class ReportExcelWriter
{
    public function download(array $payload, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        $this->buildSummarySheet($spreadsheet->getActiveSheet(), $payload);

        foreach ($payload['detail_sheets'] ?? [] as $sheetSpec) {
            $sheet = $spreadsheet->createSheet();
            $this->buildDetailSheet($sheet, $sheetSpec);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function buildSummarySheet($sheet, array $payload): void
    {
        $sheet->setTitle('Summary');
        $row = 1;

        $sheet->setCellValue("A{$row}", 'Autnyx — ' . ($payload['title'] ?? 'Report'));
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
        $row += 1;

        $sheet->setCellValue("A{$row}", $payload['tenant'] ?? '');
        $sheet->getStyle("A{$row}")->getFont()->setSize(11);
        $row += 1;

        $sheet->setCellValue("A{$row}", 'Period: ' . ($payload['period'] ?? ''));
        $row += 1;
        $sheet->setCellValue("A{$row}", 'Generated: ' . ($payload['generated_at'] ?? ''));
        $row += 1;

        if (! empty($payload['note'])) {
            $sheet->setCellValue("A{$row}", $payload['note']);
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setRGB('6B7280');
            $row += 1;
        }
        $row += 1;

        // KPIs
        if (! empty($payload['kpis'])) {
            $sheet->setCellValue("A{$row}", 'Key figures');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row += 1;
            foreach ($payload['kpis'] as $kpi) {
                $sheet->setCellValue("A{$row}", $kpi['label']);
                $sheet->setCellValue("B{$row}", $kpi['value']);
                $row += 1;
            }
            $row += 1;
        }

        // Summary sections
        foreach ($payload['summary_sections'] ?? [] as $section) {
            $sheet->setCellValue("A{$row}", $section['heading']);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row += 1;

            $sheet->fromArray($section['columns'], null, "A{$row}");
            $this->styleHeaderRow($sheet, $row, count($section['columns']));
            $row += 1;

            if (! empty($section['rows'])) {
                $sheet->fromArray($section['rows'], null, "A{$row}");
                $row += count($section['rows']);
            }
            $row += 1;
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function buildDetailSheet($sheet, array $spec): void
    {
        $sheet->setTitle(substr($spec['name'] ?? 'Detail', 0, 31));

        $sheet->fromArray($spec['columns'] ?? [], null, 'A1');
        $this->styleHeaderRow($sheet, 1, count($spec['columns'] ?? []));

        if (! empty($spec['rows'])) {
            $sheet->fromArray($spec['rows'], null, 'A2');
        }

        $lastCol = $this->columnLetter(max(1, count($spec['columns'] ?? [1])));
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        // Freeze header row
        $sheet->freezePane('A2');
    }

    private function styleHeaderRow($sheet, int $row, int $colCount): void
    {
        if ($colCount < 1) {
            return;
        }
        $last = $this->columnLetter($colCount);
        $range = "A{$row}:{$last}{$row}";
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EDE9FE');
    }

    private function columnLetter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, $index));
    }
}
