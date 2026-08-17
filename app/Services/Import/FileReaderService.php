<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads CSV and Excel files, returning headers and sample rows.
 */
class FileReaderService
{
    /**
     * Extract headers and up to $sampleLimit data rows from a file.
     *
     * @return array{headers: string[], rows: array[], total_rows: int}
     */
    public function read(string $path, int $sampleLimit = 10): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $data        = $sheet->toArray(null, true, true, false);

        if (empty($data)) {
            return ['headers' => [], 'rows' => [], 'total_rows' => 0];
        }

        // First non-empty row = headers
        $headers = array_map('strval', array_shift($data));
        $headers = array_map('trim', $headers);

        // Drop completely empty rows
        $data = array_values(array_filter($data, fn($row) => !empty(array_filter(array_map('strval', $row)))));

        $totalRows  = count($data);
        $sampleRows = array_slice($data, 0, $sampleLimit);

        // Build sample as array of assoc arrays keyed by header
        $sample = array_map(function ($row) use ($headers) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                $value = $row[$i] ?? null;
                // Convert Excel numeric dates to readable strings
                if (is_numeric($value) && ExcelDate::isDateTimeValue($value)) {
                    try {
                        $value = ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
                    } catch (\Exception) {
                        // keep raw value
                    }
                }
                $assoc[$header] = $value !== null ? (string) $value : '';
            }
            return $assoc;
        }, $sampleRows);

        return [
            'headers'    => $headers,
            'rows'       => $sample,
            'total_rows' => $totalRows,
        ];
    }
}
