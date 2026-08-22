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

    public function test_disk_config_uses_password_when_password_auth(): void
    {
        $conn = new SftpConnection([
            'host'      => 'sftp.example.com',
            'port'      => 2222,
            'username'  => 'acme',
            'auth_type' => SftpConnection::AUTH_PASSWORD,
            'password'  => 'secret',
            'base_path' => '/inbound',
        ]);

        $config = $conn->diskConfig();

        $this->assertSame('sftp', $config['driver']);
        $this->assertSame('sftp.example.com', $config['host']);
        $this->assertSame(2222, $config['port']);
        $this->assertSame('acme', $config['username']);
        $this->assertSame('/inbound', $config['root']);
        $this->assertSame('secret', $config['password']);
        $this->assertArrayNotHasKey('privateKey', $config);
    }

    public function test_disk_config_uses_private_key_when_key_auth(): void
    {
        $conn = new SftpConnection([
            'host'                   => 'sftp.example.com',
            'username'               => 'acme',
            'auth_type'              => SftpConnection::AUTH_KEY,
            'private_key'            => '-----BEGIN KEY-----',
            'private_key_passphrase' => 'phrase',
        ]);

        $config = $conn->diskConfig();

        $this->assertSame('-----BEGIN KEY-----', $config['privateKey']);
        $this->assertSame('phrase', $config['passphrase']);
        $this->assertArrayNotHasKey('password', $config);
        $this->assertSame(22, $config['port'], 'port must default to 22');
    }
}
