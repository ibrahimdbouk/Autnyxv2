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
        $path = sys_get_temp_dir() . '/import_test_' . uniqid() . '.csv';
        $fh   = fopen($path, 'w');
        fputcsv($fh, ['name', 'code', 'city']);
        for ($i = 1; $i <= $dataRows; $i++) {
            fputcsv($fh, ["Store {$i}", "S{$i}", "City {$i}"]);
        }
        fclose($fh);

        return $path;
    }

    public function test_reads_header_and_limited_sample_with_full_count(): void
    {
        $path = $this->makeCsv(100);

        try {
            $result = (new FileReaderService())->read($path, 10);

            $this->assertSame(['name', 'code', 'city'], $result['headers']);

            // Only the sample is materialised, not all 100 rows.
            $this->assertCount(10, $result['rows']);
            $this->assertSame('Store 1', $result['rows'][0]['name']);
            $this->assertSame('S1', $result['rows'][0]['code']);

            // The total count reflects the whole file, not just the sampled rows.
            $this->assertGreaterThanOrEqual(100, $result['total_rows']);
        } finally {
            @unlink($path);
        }
    }
}
