<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\SkuBaseline;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDismissal;
use Tests\TestCase;

/**
 * WP4.3 (audit H14) — a dismissal carries a reason; only "false positive"
 * teaches the detector, capped and audited; the inflation left by the pre-W1
 * bug can be repaired.
 */
class DismissalFeedbackTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
    }

    private function anomaly(string $sku = 'A'): Anomaly
    {
        return Anomaly::create(['tenant_id' => $this->tenant->id, 'rule_type' => 'sales_drop', 'severity' => 'low', 'sku' => $sku,
            'description' => 'x', 'context' => [], 'detected_at' => now()]);
    }

    private function baseline(string $sku = 'A', float $m = 2.0, int $fp = 0): SkuBaseline
    {
        return SkuBaseline::create(['tenant_id' => $this->tenant->id, 'sku' => $sku, 'rule_type' => 'sales_drop', 'metric' => 'daily_sales_qty',
            'baseline_mean' => 10, 'baseline_stddev' => 2, 'sample_count' => 60, 'sensitivity_multiplier' => $m, 'fp_count' => $fp]);
    }

    public function test_a_quick_dismissal_for_a_known_cause_is_not_false_positive_feedback(): void
    {
        $b = $this->baseline();
        $user = $this->createUser($this->tenant, admin: true);

        app(AnomalyDismissal::class)->dismiss($this->anomaly(), AnomalyDismissal::REASON_KNOWN_CAUSE, $user);

        $this->assertEqualsWithDelta(2.0, $b->fresh()->sensitivity_multiplier, 0.0001);
        $a = Anomaly::sole();
        $this->assertSame('known_cause', $a->dismiss_reason);
        $this->assertFalse((bool) $a->is_false_positive);
        $this->assertSame($user->id, (int) $a->dismissed_by);
    }

    public function test_a_false_positive_widens_sensitivity_up_to_a_cap_and_is_audited(): void
    {
        $b = $this->baseline(m: 3.9);

        app(AnomalyDismissal::class)->dismiss($this->anomaly(), AnomalyDismissal::REASON_FALSE_POSITIVE);
        $this->assertEqualsWithDelta(4.0, $b->fresh()->sensitivity_multiplier, 0.0001, 'capped at 4');
        $this->assertTrue(AuditLog::where('event_type', 'baseline_sensitivity_changed')->exists());

        app(AnomalyDismissal::class)->dismiss($this->anomaly(), AnomalyDismissal::REASON_FALSE_POSITIVE);
        $this->assertEqualsWithDelta(4.0, $b->fresh()->sensitivity_multiplier, 0.0001);
    }

    public function test_the_repair_resets_inflated_sensitivity_but_keeps_explicit_false_positives(): void
    {
        $inflated = $this->baseline('A', 3.0, 5);
        $explicit = $this->baseline('B', 3.0, 5);
        $this->anomaly('B')->update(['is_false_positive' => true, 'dismissed_at' => now()]);

        $this->artisan('baselines:repair-sensitivity')->assertSuccessful();
        $this->assertEqualsWithDelta(3.0, $inflated->fresh()->sensitivity_multiplier, 0.0001, 'dry run writes nothing');

        $this->artisan('baselines:repair-sensitivity', ['--apply' => true])->assertSuccessful();
        $this->assertEqualsWithDelta(2.0, $inflated->fresh()->sensitivity_multiplier, 0.0001);
        $this->assertSame(0, (int) $inflated->fresh()->fp_count);
        $this->assertEqualsWithDelta(2.2, $explicit->fresh()->sensitivity_multiplier, 0.0001, 'one explicit false positive kept');

        $this->artisan('baselines:repair-sensitivity', ['--apply' => true])
            ->expectsOutputToContain('0 baseline(s) to reset')->assertSuccessful();
    }
}
