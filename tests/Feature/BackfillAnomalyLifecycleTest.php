<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Services\Recovery\AnomalyIdentity;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R1 — the one-time backfill gives pre-existing anomalies an identity + sensible
 * lifecycle values, flags them `backfilled`, maps resolved rows to the resolved
 * state, and is idempotent.
 */
class BackfillAnomalyLifecycleTest extends TestCase
{
    /** Create an anomaly then strip the lifecycle columns to simulate a pre-R1 row. */
    private function preR1Anomaly(array $overrides = []): Anomaly
    {
        $tenant = $this->createTenant();
        $a = Anomaly::create(array_merge([
            'tenant_id'   => $tenant->id,
            'rule_type'   => 'stockout_risk',
            'severity'    => 'high',
            'sku'         => 'SKU-1',
            'store_id'    => null,
            'description' => 'Stockout risk.',
            'context'     => ['revenue_impact' => 2500],
            'detected_at' => now()->subDays(3),
        ], $overrides));

        DB::table('anomalies')->where('id', $a->id)->update([
            'identity_key'   => null,
            'first_seen_at'  => null,
            'last_seen_at'   => null,
            'value_at_open'  => null,
            'lifecycle_state' => 'open',
            'backfilled'     => false,
        ]);

        return $a->fresh();
    }

    public function test_backfill_assigns_identity_and_flags(): void
    {
        $a = $this->preR1Anomaly();
        $this->assertNull($a->identity_key);

        $this->artisan('autnyx:backfill-anomaly-lifecycle')->assertSuccessful();

        $a->refresh();
        $this->assertSame(AnomalyIdentity::forAnomaly($a), $a->identity_key);
        $this->assertTrue($a->backfilled);
        $this->assertNotNull($a->first_seen_at);
        $this->assertSame(2500.0, $a->value_at_open);
        $this->assertSame(Anomaly::LIFECYCLE_OPEN, $a->lifecycle_state);
    }

    public function test_resolved_row_backfills_to_resolved_state(): void
    {
        $a = $this->preR1Anomaly(['resolved_at' => now()->subDay()]);

        $this->artisan('autnyx:backfill-anomaly-lifecycle')->assertSuccessful();

        $this->assertSame(Anomaly::LIFECYCLE_RESOLVED, $a->refresh()->lifecycle_state);
    }

    public function test_backfill_is_idempotent(): void
    {
        $a = $this->preR1Anomaly();

        $this->artisan('autnyx:backfill-anomaly-lifecycle')->assertSuccessful();
        $firstSeen = $a->refresh()->first_seen_at;

        // Second run touches nothing (identity already set) — first_seen unchanged.
        $this->artisan('autnyx:backfill-anomaly-lifecycle')->assertSuccessful();
        $this->assertEquals($firstSeen, $a->refresh()->first_seen_at);
    }
}
