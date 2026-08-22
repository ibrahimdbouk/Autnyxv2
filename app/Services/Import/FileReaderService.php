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

    /**
     * Read a window of data rows [$offset, $offset + $limit) as assoc arrays
     * keyed by header. Memory-safe: only the header + the requested window is
     * ever held in memory, so a 200k-row file is processed one chunk at a time.
     *
     * `consumed` is the number of raw data-row positions advanced (so the next
     * call passes offset + consumed); it can exceed count(rows) because blank
     * lines are skipped from the returned rows but still advance the cursor.
     *
     * @return array{headers: string[], rows: array[], consumed: int, eof: bool}
     */
    public function readRange(string $path, int $offset, int $limit): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($ext, ['csv', 'txt'], true)) {
            return $this->readCsvRange($path, $offset, $limit);
        }

        return $this->readSpreadsheetRange($path, $offset, $limit);
    }

    /**
     * Fast native CSV window read (fgetcsv streaming, no spreadsheet engine).
     */
    private function readCsvRange(string $path, int $offset, int $limit): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return ['headers' => [], 'rows' => [], 'consumed' => 0, 'eof' => true];
        }

        // Strip a UTF-8 BOM if present so the first header isn't corrupted.
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $headerRow = fgetcsv($fh);
        if ($headerRow === false) {
            fclose($fh);
            return ['headers' => [], 'rows' => [], 'consumed' => 0, 'eof' => true];
        }
        $headers = array_map(fn ($h) => trim((string) $h), $headerRow);

        // Skip rows already processed.
        for ($i = 0; $i < $offset; $i++) {
            if (fgetcsv($fh) === false) {
                fclose($fh);
                return ['headers' => $headers, 'rows' => [], 'consumed' => 0, 'eof' => true];
            }
        }

        $rows = [];
        $read = 0;
        $eof  = false;
        while ($read < $limit) {
            $line = fgetcsv($fh);
            if ($line === false) {
                $eof = true;
                break;
            }
            $read++;

            $assoc = [];
            foreach ($headers as $i => $header) {
                $value = $line[$i] ?? null;
                $assoc[$header] = $value !== null ? (string) $value : '';
            }

            // Skip completely blank lines (still counted in `consumed`).
            if (! empty(array_filter($assoc, fn ($v) => $v !== '' && $v !== null))) {
                $rows[] = $assoc;
            }
        }

        fclose($fh);

        return ['headers' => $headers, 'rows' => $rows, 'consumed' => $read, 'eof' => $eof];
    }

    /**
     * Windowed read for Excel files using a row-range read filter so only the
     * header + the requested rows are materialised.
     */
    private function readSpreadsheetRange(string $path, int $offset, int $limit): array
    {
        $startRow = $offset + 2;              // +1 header, +1 to 1-index
        $endRow   = $offset + 1 + $limit;

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class(1, $startRow, $endRow) implements IReadFilter {
            public function __construct(
                private int $headerRow,
                private int $start,
                private int $end,
            ) {
            }

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                $row = (int) $row;

                return $row === $this->headerRow || ($row >= $this->start && $row <= $this->end);
            }
        });

        $spreadsheet = $reader->load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        // Row-number-keyed so we can address the header and window rows directly.
        $data        = $sheet->toArray(null, true, true, true);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $headerRow = $data[1] ?? [];
        $headers   = array_map(fn ($v) => trim((string) $v), array_values($headerRow));

        $rows       = [];
        $maxPresent = 0;
        for ($r = $startRow; $r <= $endRow; $r++) {
            if (! isset($data[$r])) {
                continue;
            }
            $maxPresent = $r;
            $values = array_values($data[$r]);
            $assoc  = [];
            foreach ($headers as $i => $header) {
                $value = $values[$i] ?? null;
                $assoc[$header] = $value !== null ? (string) $value : '';
            }
            if (! empty(array_filter($assoc, fn ($v) => $v !== '' && $v !== null))) {
                $rows[] = $assoc;
            }
        }

        // How many raw row positions we actually advanced through this window.
        $consumed = $maxPresent >= $startRow ? ($maxPresent - $startRow + 1) : 0;

        // EOF when the window didn't fill — no more rows beyond it.
        $eof = $consumed < $limit;

        return ['headers' => $headers, 'rows' => $rows, 'consumed' => $consumed, 'eof' => $eof];
    }
}
