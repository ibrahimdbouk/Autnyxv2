<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Tenant;
use App\Services\Recovery\AnomalyRecoveryService;
use Tests\TestCase;

/**
 * R3 — OBSERVED recovery read straight from the lifecycle: resolved, genuinely
 * observed (not backfilled) episodes, valued at value_at_open, windowed by
 * resolved_at. Kept strictly separate from attributed (investigation) recovery.
 */
class AnomalyRecoveryServiceTest extends TestCase
{
    private Tenant $tenant;
    private AnomalyRecoveryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->svc = new AnomalyRecoveryService();
    }

    /**
     * Create an anomaly and force it into a lifecycle state (the creating hook
     * always opens it, so terminal states are set afterwards).
     *
     * @param  array<string,mixed>  $attrs
     */
    private function anomaly(array $attrs = []): Anomaly
    {
        $a = Anomaly::create([
            'tenant_id'   => $this->tenant->id,
            'rule_type'   => $attrs['rule_type'] ?? 'stockout_risk',
            'severity'    => 'high',
            'sku'         => $attrs['sku'] ?? 'SKU-1',
            'store_id'    => null,
            'description' => 'x',
            'detected_at' => now()->subDays(10),
            'value_at_open' => $attrs['value_at_open'] ?? 100.0,
        ]);

        unset($attrs['rule_type'], $attrs['sku'], $attrs['value_at_open']);
        if ($attrs !== []) {
            $a->forceFill($attrs)->save();
        }

        return $a->refresh();
    }

    private function resolved(float $value, $resolvedAt, bool $backfilled = false, string $rule = 'stockout_risk'): Anomaly
    {
        return $this->anomaly([
            'rule_type'       => $rule,
            'value_at_open'   => $value,
            'lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED,
            'resolved_at'     => $resolvedAt,
            'backfilled'      => $backfilled,
        ]);
    }

    public function test_observed_window_sums_resolved_non_backfilled(): void
    {
        $this->resolved(100, now()->subDays(2));
        $this->resolved(250, now()->subDays(5));

        // Window start is a fixed lookback, not startOfMonth(): on the first days
        // of a month the 2-/5-day-old resolutions fall in the previous month, so a
        // month-to-date start would (correctly) exclude them and this sum would be
        // 0. The month-boundary behaviour is covered separately below.
        $out = $this->svc->observedInWindow($this->tenant->id, now()->subDays(10), null);

        $this->assertSame(350.0, $out['amount']);
        $this->assertSame(2, $out['count']);
    }

    public function test_backfilled_is_excluded_but_counted(): void
    {
        $this->resolved(100, now()->subDays(1));               // observed
        $this->resolved(999, now()->subDays(1), backfilled: true); // historical

        $out = $this->svc->observedInWindow($this->tenant->id, null, null);
        $this->assertSame(100.0, $out['amount'], 'backfilled value must not count');
        $this->assertSame(1, $out['count']);
        $this->assertSame(1, $this->svc->backfilledResolvedCount($this->tenant->id));
    }

    public function test_active_episodes_are_not_observed_recovery(): void
    {
        // open / persisting / clearing are still live — not recovery.
        $this->anomaly(['value_at_open' => 40, 'lifecycle_state' => Anomaly::LIFECYCLE_OPEN]);
        $this->anomaly(['value_at_open' => 60, 'lifecycle_state' => Anomaly::LIFECYCLE_PERSISTING]);
        $this->anomaly(['value_at_open' => 30, 'lifecycle_state' => Anomaly::LIFECYCLE_CLEARING, 'cleared_at' => now()]);
        $this->resolved(200, now()->subDay());

        $out = $this->svc->observedInWindow($this->tenant->id, null, null);
        $this->assertSame(200.0, $out['amount']);

        // Still-at-risk = active episodes' value_at_open (40+60+30).
        $this->assertSame(130.0, $this->svc->activeValueAtRisk($this->tenant->id));
    }

    public function test_mtd_respects_the_calendar_window(): void
    {
        $this->resolved(500, now()->subMonthNoOverflow()->startOfMonth()->addDay()); // last month
        $this->resolved(120, now()->startOfMonth()->addDay());                        // this month

        $this->assertSame(120.0, $this->svc->mtd($this->tenant->id)['amount']);
        $this->assertSame(500.0, $this->svc->prevMtd($this->tenant->id)['amount']);
    }

    public function test_by_rule_family_groups_and_orders(): void
    {
        $this->resolved(100, now()->subDay(), rule: 'stockout_risk');
        $this->resolved(50,  now()->subDay(), rule: 'stockout_risk');
        $this->resolved(300, now()->subDay(), rule: 'overstock');

        $rows = $this->svc->byRuleFamily($this->tenant->id, null, null);

        $this->assertSame('overstock', $rows[0]['rule_type'], 'highest recovered first');
        $this->assertSame(300.0, $rows[0]['recovered']);
        $this->assertSame(150.0, $rows[1]['recovered']);
        $this->assertSame(2, $rows[1]['count']);
    }

    public function test_summary_clear_rate_is_observed_over_observed_plus_active(): void
    {
        $this->resolved(300, now()->subDay());                                          // observed cleared
        $this->anomaly(['value_at_open' => 100, 'lifecycle_state' => Anomaly::LIFECYCLE_OPEN]); // still at risk

        $summary = $this->svc->summary($this->tenant->id);

        $this->assertSame(300.0, $summary['observed_total']);
        $this->assertSame(100.0, $summary['active_at_risk']);
        $this->assertSame(75.0, $summary['clear_rate']); // 300 / (300+100)
    }
}
