<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Tenant;
use App\Services\Quality\QualityMetricsService;
use Tests\TestCase;

/**
 * R3 — Quality Center's recovery-lifecycle block reports the deterministic
 * lifecycle state (live vs. cleared), recurrence, and observed value.
 */
class QualityRecoveryLifecycleTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
    }

    private function make(string $sku): Anomaly
    {
        return Anomaly::create([
            'tenant_id'     => $this->tenant->id,
            'rule_type'     => 'stockout_risk',
            'severity'      => 'high',
            'sku'           => $sku,
            'store_id'      => null,
            'description'   => 'x',
            'detected_at'   => now()->subDays(6),
            'value_at_open' => 100.0,
        ]);
    }

    public function test_lifecycle_block_counts_states_and_recurrence(): void
    {
        // A live one and a cleared one on distinct subjects.
        $this->make('SKU-OPEN'); // stays open

        $resolvedFirst = $this->make('SKU-RECUR');
        $resolvedFirst->forceFill([
            'lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED,
            'first_seen_at'   => now()->subDays(6),
            'resolved_at'     => now()->subDay(),
        ])->save();

        // Same subject fails again → a fresh, linked episode (recurrence).
        $recurred = $this->make('SKU-RECUR');
        $this->assertNotNull($recurred->previous_episode_id, 'guard: recurrence linked');

        $report = app(QualityMetricsService::class)->recoveryLifecycle($this->tenant->id);

        $this->assertSame(1, $report['resolved']);
        $this->assertSame(2, $report['open'], 'SKU-OPEN plus the recurred episode are both open');
        $this->assertSame(2, $report['active']);
        $this->assertSame(1, $report['recurrences']);
        $this->assertEqualsWithDelta(5.0, $report['mean_days_to_clear'], 0.2);
        $this->assertSame(100.0, $report['observed_total']);
    }
}
