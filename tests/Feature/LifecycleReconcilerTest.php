<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Tenant;
use App\Services\Recovery\LifecycleReconciler;
use Tests\TestCase;

/**
 * R2 — the reconciler's honest state machine, driven directly (no detection run):
 * open → persisting → clearing → resolved (dated to first clear), flap guard,
 * dormant-on-data-gap, and resolved rows left untouched.
 */
class LifecycleReconcilerTest extends TestCase
{
    private Tenant $tenant;
    private LifecycleReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->reconciler = new LifecycleReconciler();
    }

    private function anomaly(array $state = []): Anomaly
    {
        $a = Anomaly::create([
            'tenant_id'   => $this->tenant->id,
            'rule_type'   => 'stockout_risk',
            'severity'    => 'high',
            'sku'         => 'SKU-' . uniqid(),
            'store_id'    => null,
            'description' => 'x',
            'detected_at' => now()->subDays(5),
        ]);
        if ($state) {
            $a->forceFill($state)->save();
            $a->refresh();
        }
        return $a;
    }

    private function reconcile(array $touchedIds, \Closure $evaluable, ?int $n = null, $now = null): void
    {
        $this->reconciler->reconcileRule($this->tenant->id, 'stockout_risk', $touchedIds, $evaluable, $n, $now);
    }

    public function test_brand_new_touched_stays_open(): void
    {
        $a = $this->anomaly(); // first_seen == last_seen (created this "run")
        $this->reconcile([$a->id], fn () => true);
        $this->assertSame(Anomaly::LIFECYCLE_OPEN, $a->refresh()->lifecycle_state);
    }

    public function test_seen_again_advances_open_to_persisting(): void
    {
        $a = $this->anomaly(['first_seen_at' => now()->subDays(3), 'last_seen_at' => now()->subDay()]);
        $this->reconcile([$a->id], fn () => true);
        $a->refresh();
        $this->assertSame(Anomaly::LIFECYCLE_PERSISTING, $a->lifecycle_state);
        $this->assertSame(0, $a->clear_streak);
    }

    public function test_not_touched_but_evaluable_starts_clearing(): void
    {
        $a = $this->anomaly(['lifecycle_state' => Anomaly::LIFECYCLE_PERSISTING, 'first_seen_at' => now()->subDays(3), 'last_seen_at' => now()->subDay()]);
        $this->reconcile([], fn () => true, 2);
        $a->refresh();
        $this->assertSame(Anomaly::LIFECYCLE_CLEARING, $a->lifecycle_state);
        $this->assertSame(1, $a->clear_streak);
        $this->assertNotNull($a->cleared_at);
        $this->assertNull($a->resolved_at);
    }

    public function test_confirms_resolved_after_n_and_dates_to_first_clear(): void
    {
        $firstClear = now()->subDay()->startOfMinute();
        $a = $this->anomaly([
            'lifecycle_state' => Anomaly::LIFECYCLE_CLEARING,
            'clear_streak'    => 1,
            'cleared_at'      => $firstClear,
            'first_seen_at'   => now()->subDays(4),
            'last_seen_at'    => now()->subDays(2),
        ]);

        $this->reconcile([], fn () => true, 2, now());
        $a->refresh();

        $this->assertSame(Anomaly::LIFECYCLE_RESOLVED, $a->lifecycle_state);
        $this->assertSame(2, $a->clear_streak);
        // Honest duration: resolved_at is the FIRST clear, not the confirmation run.
        $this->assertSame($firstClear->toDateTimeString(), $a->resolved_at->toDateTimeString());
    }

    public function test_dormant_on_data_gap_never_resolves(): void
    {
        $a = $this->anomaly(['lifecycle_state' => Anomaly::LIFECYCLE_PERSISTING, 'clear_streak' => 0, 'first_seen_at' => now()->subDays(3), 'last_seen_at' => now()->subDay()]);
        $this->reconcile([], fn () => false); // not touched AND not evaluable
        $a->refresh();
        $this->assertSame(Anomaly::LIFECYCLE_PERSISTING, $a->lifecycle_state);
        $this->assertSame(0, $a->clear_streak);
        $this->assertNull($a->cleared_at);
    }

    public function test_flap_guard_resets_clear_streak_on_refire(): void
    {
        $a = $this->anomaly([
            'lifecycle_state' => Anomaly::LIFECYCLE_CLEARING,
            'clear_streak'    => 1,
            'cleared_at'      => now()->subDay(),
            'first_seen_at'   => now()->subDays(3),
            'last_seen_at'    => now()->subDays(2),
        ]);

        $this->reconcile([$a->id], fn () => true); // re-fired before confirmation
        $a->refresh();

        $this->assertSame(Anomaly::LIFECYCLE_PERSISTING, $a->lifecycle_state);
        $this->assertSame(0, $a->clear_streak);
        $this->assertNull($a->cleared_at);
    }

    public function test_resolved_row_is_left_untouched(): void
    {
        $resolvedAt = now()->subDays(2);
        $a = $this->anomaly([
            'lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED,
            'resolved_at'     => $resolvedAt,
            'clear_streak'    => 2,
            'cleared_at'      => $resolvedAt,
        ]);

        $this->reconcile([], fn () => true); // reconcile ignores resolved episodes
        $a->refresh();

        $this->assertSame(Anomaly::LIFECYCLE_RESOLVED, $a->lifecycle_state);
        $this->assertSame($resolvedAt->toDateTimeString(), $a->resolved_at->toDateTimeString());
    }

    public function test_full_lifecycle_open_to_resolved(): void
    {
        // A subject that fails, persists, then clears for two evaluated runs.
        $a = $this->anomaly(['first_seen_at' => now()->subDays(4), 'last_seen_at' => now()->subDays(3)]);
        $this->reconcile([$a->id], fn () => true);                 // seen again → persisting
        $this->assertSame(Anomaly::LIFECYCLE_PERSISTING, $a->refresh()->lifecycle_state);

        $this->reconcile([], fn () => true, 2);                    // clear 1 → clearing
        $this->assertSame(Anomaly::LIFECYCLE_CLEARING, $a->refresh()->lifecycle_state);

        $this->reconcile([], fn () => true, 2);                    // clear 2 → resolved
        $this->assertSame(Anomaly::LIFECYCLE_RESOLVED, $a->refresh()->lifecycle_state);
    }
}
