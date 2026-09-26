<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\ApiKey;
use App\Models\Investigation;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * W13 — BI exports: flat, tenant-scoped tables for Power BI, incremental by
 * ?since=, paged by id, or the whole table as CSV.
 */
class BiExportApiTest extends TestCase
{
    public function test_findings_export_is_flat_tenant_scoped_incremental_and_paged(): void
    {
        $this->travelTo(Carbon::parse('2026-09-26 06:00'));
        $a = $this->createTenant();
        $b = $this->createTenant();
        $store = Store::create(['tenant_id' => $a->id, 'name' => 'Marina', 'code' => 'MAR']);
        foreach (range(1, 3) as $i) {
            Anomaly::create(['tenant_id' => $a->id, 'rule_type' => 'stockout_risk', 'severity' => 'high', 'sku' => "S{$i}", 'store_id' => $store->id,
                'description' => "Stock-out S{$i}", 'context' => ['revenue_impact' => 100 * $i], 'detected_at' => now()]);
        }
        Anomaly::create(['tenant_id' => $b->id, 'rule_type' => 'stockout_risk', 'severity' => 'high', 'sku' => 'THEIRS',
            'description' => 'Theirs', 'context' => [], 'detected_at' => now()]);
        [, $token] = ApiKey::generate($a->id, 'bi', [ApiKey::SCOPE_READ_EXPORTS]);
        $h = ['X-Api-Key' => $token];

        $this->getJson('/api/v1/exports', $h)->assertOk()->assertJsonFragment(['dataset' => 'findings']);

        $page = $this->getJson('/api/v1/exports/findings?limit=2', $h)->assertOk();
        $this->assertSame(['S1', 'S2'], array_column($page->json('data'), 'sku'));
        $this->assertSame(\App\Models\AnomalySetting::RULES['stockout_risk']['label'], $page->json('data.0.rule_label'));
        $this->assertSame(100.0, (float) $page->json('data.0.value'));
        $this->assertArrayNotHasKey('context', $page->json('data.0'));
        $next = $page->json('next');
        $this->assertNotNull($next);
        $rest = $this->getJson(parse_url($next, PHP_URL_PATH) . '?' . parse_url($next, PHP_URL_QUERY), $h)->assertOk();
        $this->assertSame(['S3'], array_column($rest->json('data'), 'sku'));
        $this->assertNull($rest->json('next'));

        // Incremental: only what changed since.
        $this->travel(1)->days();
        Anomaly::where('sku', 'S2')->first()->update(['feedback' => 'real']);
        $since = now()->subHour()->toIso8601ZuluString();
        $this->assertSame(['S2'], array_column($this->getJson('/api/v1/exports/findings?since=' . urlencode($since), $h)->json('data'), 'sku'));

        // CSV: the whole table, header first, nobody else's rows.
        $csv = $this->get('/api/v1/exports/findings?format=csv', $h)->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertStringStartsWith('id,rule_type,rule_label,severity', $lines[0]);
        $this->assertCount(4, $lines);
        $this->assertStringNotContainsString('THEIRS', $csv);

        $this->getJson('/api/v1/exports/nope', $h)->assertNotFound();
        $this->getJson('/api/v1/exports/findings?since=yesterday-ish', $h)->assertStatus(422);
    }

    public function test_exports_need_their_own_scope(): void
    {
        $t = $this->createTenant();
        [, $token] = ApiKey::generate($t->id, 'x', [ApiKey::SCOPE_READ_ANOMALIES]);
        $this->getJson('/api/v1/exports/findings', ['X-Api-Key' => $token])->assertForbidden();
    }

    public function test_computed_and_dimension_tables(): void
    {
        $this->travelTo(Carbon::parse('2026-09-26 06:00'));
        $t = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => '=HYPERLINK("x")', 'code' => 'MAR']);
        Investigation::factory()->create(['tenant_id' => $t->id, 'opened_at' => now()->subDays(3), 'revenue_at_risk' => 1200, 'capital_at_risk' => 300]);
        DB::table('sales_weekly')->insert(['tenant_id' => $t->id, 'store_id' => $store->id, 'sku' => 'A', 'week_start' => '2026-09-14',
            'units_sold' => 10, 'revenue' => 50, 'transaction_count' => 4, 'days_sold' => 3, 'created_at' => now(), 'updated_at' => now()]);
        [, $token] = ApiKey::generate($t->id, 'bi', [ApiKey::SCOPE_READ_EXPORTS]);
        $h = ['X-Api-Key' => $token];

        $months = collect($this->getJson('/api/v1/exports/value_by_month', $h)->assertOk()->json('data'))->keyBy('month');
        $this->assertSame(1200.0, (float) $months['2026-09']['lost_revenue_found']);
        $this->assertCount(24, $months);

        $this->assertSame('2026-09-14', substr($this->getJson('/api/v1/exports/sales_weekly', $h)->json('data.0.week_start'), 0, 10));
        $this->getJson('/api/v1/exports/supplier_scorecard', $h)->assertOk()->assertJsonPath('data', []);

        // A value a spreadsheet would run as a formula is neutralised in CSV.
        $csv = $this->get('/api/v1/exports/stores?format=csv', $h)->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
