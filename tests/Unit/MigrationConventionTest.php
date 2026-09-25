<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WP6.1 (audit M22) — docs/migrations.md, enforced on every migration from
 * 2026-09-25 onwards: indexes on hot tables are built concurrently outside a
 * transaction, and there is no raw CREATE INDEX without CONCURRENTLY.
 */
class MigrationConventionTest extends TestCase
{
    private const FROM = '2026_09_25_000013';

    private const HOT = [
        'sales_transactions', 'sales_daily', 'inventory_levels', 'inventory_current', 'purchase_orders',
        'sales_returns', 'anomalies', 'investigations', 'investigation_entities', 'investigation_evidence',
        'products', 'sku_profiles', 'sku_baselines', 'sku_replenishment', 'quarantined_rows', 'import_rows',
    ];

    /** @return array<string,string> file => source */
    private function migrations(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/../../database/migrations/*.php') as $file) {
            if (strcmp(basename($file), self::FROM) >= 0) {
                $out[basename($file)] = file_get_contents($file);
            }
        }

        return $out;
    }

    public function test_the_convention_applies_to_at_least_one_migration(): void
    {
        $this->assertNotEmpty($this->migrations());
    }

    public function test_no_blocking_index_on_a_hot_table(): void
    {
        $bad = [];
        foreach ($this->migrations() as $file => $src) {
            // Schema::table('hot', function … { … ->index( / ->unique( … })
            foreach (self::HOT as $table) {
                if (preg_match_all("/Schema::table\\(\\s*'{$table}'.*?\\n\\s*\\}\\);/s", $src, $m)) {
                    foreach ($m[0] as $block) {
                        if (preg_match('/->(index|unique|spatialIndex|fullText)\(/', $block)) {
                            $bad[] = "{$file}: ->index()/->unique() on hot table {$table} (use ConcurrentIndex)";
                        }
                    }
                }
            }
            if (preg_match('/CREATE\s+(UNIQUE\s+)?INDEX\s+(?!CONCURRENTLY)/i', $src)) {
                $bad[] = "{$file}: CREATE INDEX without CONCURRENTLY";
            }
            if (str_contains($src, 'ConcurrentIndex::') && ! preg_match('/\$withinTransaction\s*=\s*false/', $src)) {
                $bad[] = "{$file}: ConcurrentIndex needs \$withinTransaction = false";
            }
        }

        $this->assertSame([], $bad, "See docs/migrations.md:\n" . implode("\n", $bad));
    }

    public function test_the_check_catches_a_blocking_index(): void
    {
        $src = "Schema::table('anomalies', function (Blueprint \$t) {\n    \$t->index(['tenant_id']);\n});";
        $this->assertMatchesRegularExpression("/Schema::table\\(\\s*'anomalies'.*?\\n\\s*\\}\\);/s", $src);
        $this->assertMatchesRegularExpression('/CREATE\s+(UNIQUE\s+)?INDEX\s+(?!CONCURRENTLY)/i', 'CREATE INDEX foo ON bar (x)');
        $this->assertDoesNotMatchRegularExpression('/CREATE\s+(UNIQUE\s+)?INDEX\s+(?!CONCURRENTLY)/i', 'CREATE INDEX CONCURRENTLY foo ON bar (x)');
    }
}
