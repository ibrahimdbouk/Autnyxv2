<?php

namespace Tests\Unit;

use App\Services\Import\FileReaderService;
use PHPUnit\Framework\TestCase;

/**
 * Guards the memory-safe import sample read: the reader must return the header
 * and only the first N rows (not the whole file), while still reporting the
 * full row count. Regression guard for the "Analysing file…" hang caused by
 * loading the entire spreadsheet into memory to preview 10 rows.
 */
class FileReaderServiceTest extends TestCase
{
    private function makeCsv(int $dataRows): string
    {
        // Include NUMERIC columns (qty, price) and a date — this is what real
        // sales/returns files look like, and what exposed the crash in the
        // numeric-value handling path (isDateTimeValue on a non-existent method).
        $path = sys_get_temp_dir() . '/import_test_' . uniqid() . '.csv';
        $fh   = fopen($path, 'w');
        fputcsv($fh, ['date', 'sku', 'qty', 'price']);
        for ($i = 1; $i <= $dataRows; $i++) {
            fputcsv($fh, ['2026-08-01', "SKU{$i}", (string) $i, '9.99']);
        }
        fclose($fh);

        return $path;
    }

    public function test_reads_header_and_limited_sample_with_full_count(): void
    {
        $path = $this->makeCsv(100);

        try {
            $result = (new FileReaderService())->read($path, 10);

            $this->assertSame(['date', 'sku', 'qty', 'price'], $result['headers']);

            // Only the sample is materialised, not all 100 rows.
            $this->assertCount(10, $result['rows']);
            $this->assertSame('SKU1', $result['rows'][0]['sku']);
            $this->assertSame('1', $result['rows'][0]['qty']);
            $this->assertSame('9.99', $result['rows'][0]['price']);

            // The total count reflects the whole file, not just the sampled rows.
            $this->assertGreaterThanOrEqual(100, $result['total_rows']);
        } finally {
            @unlink($path);
        }
    }
}
