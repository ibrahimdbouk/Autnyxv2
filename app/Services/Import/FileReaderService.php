<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Reads CSV and Excel files, returning headers and sample rows.
 *
 * Memory-safe: the sample read loads ONLY the header + the first $sampleLimit
 * rows (via a read filter), and derives the total row count from the worksheet
 * metadata rather than loading every row. Previously this loaded the entire
 * file into memory just to preview 10 rows, which exhausted PHP's memory limit
 * on real-world files — a fatal that bypassed the caller's try/catch and left
 * the upload button hung on "Analysing file…".
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
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        // Total data-row count without loading the whole file into memory.
        $totalRows = 0;
        try {
            $info     = $reader->listWorksheetInfo($path);
            $rawTotal = (int) ($info[0]['totalRows'] ?? 0);
            $totalRows = max(0, $rawTotal - 1); // exclude the header row
        } catch (\Throwable $e) {
            // Non-fatal: leave the count at 0 if metadata isn't available.
        }

        // Only read the header + sample rows into memory.
        $maxRows = $sampleLimit + 1;
        $reader->setReadFilter(new class($maxRows) implements IReadFilter {
            public function __construct(private int $maxRows)
            {
            }

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return (int) $row <= $this->maxRows;
            }
        });

        $spreadsheet = $reader->load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $data        = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if (empty($data)) {
            return ['headers' => [], 'rows' => [], 'total_rows' => 0];
        }

        // First row = headers
        $headers = array_map('strval', array_shift($data));
        $headers = array_map('trim', $headers);

        // Drop completely empty rows from the sample
        $data = array_values(array_filter($data, fn ($row) => ! empty(array_filter(array_map('strval', $row)))));

        $sampleRows = array_slice($data, 0, $sampleLimit);

        // Build sample as array of assoc arrays keyed by header.
        // toArray() above is called with formatData = true, so Excel date cells
        // are already returned as formatted strings — no manual conversion needed.
        $sample = array_map(function ($row) use ($headers) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                $value = $row[$i] ?? null;
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
