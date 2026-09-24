<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads CSV and Excel files: a header + sample for mapping, and memory-safe
 * windows of rows for processing.
 *
 * WP3.2 (audit C7, H26):
 *  • CSV dialect — delimiter (, ; tab |) and encoding (UTF-8, UTF-16 LE/BE,
 *    Windows-1252) are detected once, stored on the import, and used by every
 *    read, so a semicolon or UTF-16 file no longer lands as one giant column.
 *    Everything is converted to UTF-8.
 *  • Excel — cells are read by TYPE, not by display format: date cells become
 *    ISO dates (never a locale-formatted "9/1/2026" that could be misread),
 *    zero-padded code formats keep their leading zeros, numbers never come back
 *    in scientific notation, formulas give their cached result.
 */
class FileReaderService
{
    public const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * Extract headers and up to $sampleLimit data rows from a file, plus the
     * detected CSV dialect (null for spreadsheets).
     *
     * @return array{headers: string[], rows: array[], total_rows: int, delimiter: ?string, encoding: ?string}
     */
    public function read(string $path, int $sampleLimit = 10): array
    {
        if ($this->isCsv($path)) {
            $dialect = $this->dialect($path);
            $window  = $this->readCsvRange($path, 0, $sampleLimit, $dialect);

            return [
                'headers'    => $window['headers'],
                'rows'       => $window['rows'],
                'total_rows' => $this->countCsvRecords($path, $dialect),
                'delimiter'  => $dialect['delimiter'],
                'encoding'   => $dialect['encoding'],
            ];
        }

        $totalRows = 0;
        try {
            $info      = IOFactory::createReaderForFile($path)->listWorksheetInfo($path);
            $totalRows = max(0, (int) ($info[0]['totalRows'] ?? 0) - 1); // exclude the header row
        } catch (\Throwable) {
            // Non-fatal: leave the count at 0 if metadata isn't available.
        }

        $window = $this->readSpreadsheetRange($path, 0, $sampleLimit);

        return [
            'headers'    => $window['headers'],
            'rows'       => $window['rows'],
            'total_rows' => $totalRows,
            'delimiter'  => null,
            'encoding'   => null,
        ];
    }

    /**
     * Read a window of data rows [$offset, $offset + $limit) as assoc arrays
     * keyed by header. `consumed` is the number of raw row positions advanced
     * (blank lines are skipped from `rows` but still advance the cursor).
     *
     * @param  array{delimiter?: ?string, encoding?: ?string}  $dialect  stored on the import; detected when absent
     * @return array{headers: string[], rows: array[], consumed: int, eof: bool}
     */
    public function readRange(string $path, int $offset, int $limit, array $dialect = []): array
    {
        if ($this->isCsv($path)) {
            if (empty($dialect['delimiter']) || empty($dialect['encoding'])) {
                $dialect = array_merge($this->dialect($path), array_filter($dialect));
            }

            return $this->readCsvRange($path, $offset, $limit, $dialect);
        }

        return $this->readSpreadsheetRange($path, $offset, $limit);
    }

    /** Every data row (single-pass processing path). */
    public function readAll(string $path, array $dialect = []): array
    {
        return $this->readRange($path, 0, PHP_INT_MAX, $dialect)['rows'];
    }

    public function isCsv(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['csv', 'txt', 'tsv'], true);
    }

    // ── CSV ──────────────────────────────────────────────────────────────────

    /**
     * Detect encoding (BOM, then UTF-8 validity, else Windows-1252; UTF-16
     * without a BOM by its NUL pattern) and the delimiter (the candidate that
     * occurs most in the header line, outside quotes; comma on a tie).
     *
     * @return array{delimiter: string, encoding: string}
     */
    public function dialect(string $path): array
    {
        $fh = @fopen($path, 'rb');
        $sample = $fh ? (string) fread($fh, 65536) : '';
        if ($fh) {
            fclose($fh);
        }

        $encoding = match (true) {
            str_starts_with($sample, "\xEF\xBB\xBF") => 'UTF-8',
            str_starts_with($sample, "\xFF\xFE")     => 'UTF-16LE',
            str_starts_with($sample, "\xFE\xFF")     => 'UTF-16BE',
            default                                  => null,
        };

        if ($encoding === null) {
            $nuls = substr_count(substr($sample, 0, 4000), "\0");
            if ($nuls > 0 && $nuls >= strlen(substr($sample, 0, 4000)) / 4) {
                $encoding = ($sample[0] ?? '') === "\0" ? 'UTF-16BE' : 'UTF-16LE';
            } else {
                // Cut at the last newline so a multi-byte char split by the 64KB read doesn't fail the check.
                $cut = strrpos($sample, "\n");
                $probe = $cut !== false ? substr($sample, 0, $cut) : $sample;
                $encoding = mb_check_encoding($probe, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
            }
        }

        $text = $encoding === 'UTF-8' ? $sample : (string) @mb_convert_encoding($sample, 'UTF-8', $encoding);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $header = '';
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                $header = $line;
                break;
            }
        }
        $unquoted = preg_replace('/"[^"]*"/', '', $header) ?? $header;

        $best = ',';
        $bestCount = 0;
        foreach (self::DELIMITERS as $d) {
            $n = substr_count($unquoted, $d);
            if ($n > $bestCount) {
                $best = $d;
                $bestCount = $n;
            }
        }

        return ['delimiter' => $best, 'encoding' => $encoding];
    }

    /** @return resource|false  positioned after any BOM, transcoding to UTF-8 */
    private function openCsv(string $path, string $encoding)
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }

        $bom = match ($encoding) {
            'UTF-8'              => "\xEF\xBB\xBF",
            'UTF-16LE'           => "\xFF\xFE",
            'UTF-16BE'           => "\xFE\xFF",
            default              => '',
        };
        if ($bom !== '' && fread($fh, strlen($bom)) !== $bom) {
            rewind($fh);
        }

        if ($encoding !== 'UTF-8') {
            if (in_array('convert.iconv.*', stream_get_filters(), true)) {
                stream_filter_append($fh, "convert.iconv.{$encoding}/UTF-8//TRANSLIT", STREAM_FILTER_READ);
            } else {
                $tmp = fopen('php://temp', 'r+b');
                fwrite($tmp, (string) mb_convert_encoding((string) stream_get_contents($fh), 'UTF-8', $encoding));
                fclose($fh);
                rewind($tmp);
                $fh = $tmp;
            }
        }

        return $fh;
    }

    /** @return array{headers: string[], rows: array[], consumed: int, eof: bool} */
    private function readCsvRange(string $path, int $offset, int $limit, array $dialect): array
    {
        $delimiter = $dialect['delimiter'] ?? ',';
        $fh = $this->openCsv($path, $dialect['encoding'] ?? 'UTF-8');
        if ($fh === false) {
            return ['headers' => [], 'rows' => [], 'consumed' => 0, 'eof' => true];
        }

        $headerRow = $this->csvLine($fh, $delimiter);
        if ($headerRow === false) {
            fclose($fh);

            return ['headers' => [], 'rows' => [], 'consumed' => 0, 'eof' => true];
        }
        $headers = array_map(fn ($h) => trim((string) $h), $headerRow);

        for ($i = 0; $i < $offset; $i++) {
            if ($this->csvLine($fh, $delimiter) === false) {
                fclose($fh);

                return ['headers' => $headers, 'rows' => [], 'consumed' => 0, 'eof' => true];
            }
        }

        $rows = [];
        $read = 0;
        $eof  = false;
        while ($read < $limit) {
            $line = $this->csvLine($fh, $delimiter);
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

            if (! empty(array_filter($assoc, fn ($v) => $v !== ''))) {
                $rows[] = $assoc;
            }
        }

        fclose($fh);

        return ['headers' => $headers, 'rows' => $rows, 'consumed' => $read, 'eof' => $eof];
    }

    /** @param resource $fh */
    private function csvLine($fh, string $delimiter): array|false
    {
        return fgetcsv($fh, null, $delimiter, '"', '');
    }

    private function countCsvRecords(string $path, array $dialect): int
    {
        $fh = $this->openCsv($path, $dialect['encoding']);
        if ($fh === false) {
            return 0;
        }
        $n = -1; // header
        while (($line = $this->csvLine($fh, $dialect['delimiter'])) !== false) {
            if ($line !== [null]) {
                $n++;
            }
        }
        fclose($fh);

        return max(0, $n);
    }

    // ── Spreadsheets ─────────────────────────────────────────────────────────

    /** @return array{headers: string[], rows: array[], consumed: int, eof: bool} */
    private function readSpreadsheetRange(string $path, int $offset, int $limit): array
    {
        $startRow = $offset + 2;                                   // +1 header, +1 to 1-index
        $endRow   = $limit >= PHP_INT_MAX - $offset - 2 ? PHP_INT_MAX : $offset + 1 + $limit;

        $reader = IOFactory::createReaderForFile($path);
        // Formats are needed to tell a date cell from a number (and to keep
        // zero-padded codes); the read filter still bounds memory to the window.
        $reader->setReadDataOnly(false);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new class($startRow, $endRow) implements IReadFilter {
            public function __construct(private int $start, private int $end)
            {
            }

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                $row = (int) $row;

                return $row === 1 || ($row >= $this->start && $row <= $this->end);
            }
        });

        $spreadsheet = $reader->load($path);
        $sheet       = $spreadsheet->getActiveSheet();

        $lastCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn(1));
        $headers = [];
        for ($c = 1; $c <= $lastCol; $c++) {
            $headers[] = trim($this->cellString($sheet, $c, 1));
        }
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }

        $highest = $sheet->getHighestDataRow();
        $last    = min($endRow, $highest);
        $rows    = [];
        for ($r = $startRow; $r <= $last; $r++) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = $this->cellString($sheet, $i + 1, $r);
            }
            if (! empty(array_filter($assoc, fn ($v) => $v !== ''))) {
                $rows[] = $assoc;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $consumed = $last >= $startRow ? ($last - $startRow + 1) : 0;

        return ['headers' => $headers, 'rows' => $rows, 'consumed' => $consumed, 'eof' => $consumed < $limit];
    }

    /** One cell as the string the import pipeline expects. */
    private function cellString(Worksheet $sheet, int $col, int $row): string
    {
        $coord = Coordinate::stringFromColumnIndex($col) . $row;
        if (! $sheet->cellExists($coord)) {
            return '';
        }
        $cell  = $sheet->getCell($coord);
        $value = $this->cellValue($cell);

        if ($value === null) {
            return '';
        }
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            if (ExcelDate::isDateTime($cell)) {
                $dt = ExcelDate::excelToDateTimeObject((float) $value);

                return fmod((float) $value, 1.0) == 0.0 ? $dt->format('Y-m-d') : $dt->format('Y-m-d H:i:s');
            }
            $format = (string) $cell->getStyle()->getNumberFormat()->getFormatCode();
            if (preg_match('/^0+$/', $format) && floor((float) $value) == $value) {
                return str_pad(number_format((float) $value, 0, '.', ''), strlen($format), '0', STR_PAD_LEFT);
            }

            return $this->plainNumber((float) $value);
        }

        return (string) $value;
    }

    private function cellValue(Cell $cell): mixed
    {
        if (! $cell->isFormula()) {
            return $cell->getValue();
        }
        $cached = $cell->getOldCalculatedValue();
        if ($cached !== null) {
            return $cached;
        }
        try {
            return $cell->getCalculatedValue();
        } catch (\Throwable) {
            return null;
        }
    }

    private function plainNumber(float $f): string
    {
        if (floor($f) == $f && abs($f) < 1e15) {
            return number_format($f, 0, '.', '');
        }
        $s = rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }
}
