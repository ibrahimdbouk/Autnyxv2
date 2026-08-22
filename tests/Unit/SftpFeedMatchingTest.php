<?php

namespace Tests\Unit;

use App\Models\SftpConnection;
use App\Models\SftpFeed;
use PHPUnit\Framework\TestCase;

/**
 * M14 — pure (no-DB) logic for SFTP feed matching and disk config building.
 */
class SftpFeedMatchingTest extends TestCase
{
    private function feed(string $pattern): SftpFeed
    {
        return new SftpFeed(['filename_pattern' => $pattern]);
    }

    public function test_glob_matches_are_case_insensitive(): void
    {
        $feed = $this->feed('sales_*.csv');

        $this->assertTrue($feed->matches('sales_2026-08.csv'));
        $this->assertTrue($feed->matches('SALES_2026-08.CSV'), 'matching must be case-insensitive');
        $this->assertTrue($feed->matches('Sales_Q3.Csv'));
    }

    public function test_non_matching_names_are_rejected(): void
    {
        $feed = $this->feed('sales_*.csv');

        $this->assertFalse($feed->matches('inventory_2026.csv'));
        $this->assertFalse($feed->matches('sales_2026.txt'));
        $this->assertFalse($feed->matches('notes.csv'));
    }

    public function test_blank_pattern_matches_everything(): void
    {
        $feed = new SftpFeed(['filename_pattern' => null]);

        $this->assertTrue($feed->matches('anything.xlsx'));
        $this->assertTrue($feed->matches('whatever.csv'));
    }

    public function test_extension_only_pattern(): void
    {
        $feed = $this->feed('*.xlsx');

        $this->assertTrue($feed->matches('report.xlsx'));
        $this->assertFalse($feed->matches('report.csv'));
    }

    // NOTE: diskConfig() tests live in tests/Feature/SftpConnectionTest — reading the
    // encrypted credential casts requires a booted app (the `encrypter` binding), which
    // a plain PHPUnit unit test does not have.
}
