<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Tenant;
use App\Services\Recovery\LifecycleReconciler;
use Tests\TestCase;

/**
 * R2b — recurrence is modelled as fresh episodes linked by identity, and
 * confirmation is segment-aware (intermittent/lumpy SKUs need more clears).
 */
class EpisodeRecurrenceTest extends TestCase
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
            'tenant_id'   => $this->tenant->id,
            'rule_type'   => 'stockout_risk',
            'severity'    => 'high',
            'sku'         => $sku,
            'store_id'    => null,
            'description' => 'x',
            'detected_at' => now(),
        ]);
    }

    public function test_first_episode_has_no_prior(): void
    {
        $a = $this->make('SKU-A');
        $this->assertSame(1, $a->episode_seq);
        $this->assertNull($a->previous_episode_id);
    }

    public function test_recurrence_after_resolution_is_a_new_linked_episode(): void
    {
        $ep1 = $this->make('SKU-B');
        $ep1->update(['lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED, 'resolved_at' => now()->subDay()]);

        // Same subject fails again → a brand-new row (as flag() would create,
        // since it no longer reuses a resolved episode).
        $ep2 = $this->make('SKU-B');

        $this->assertSame($ep1->identity_key, $ep2->identity_key, 'same subject → same identity');
        $this->assertSame(2, $ep2->episode_seq, 'next episode in the sequence');
        $this->assertSame($ep1->id, $ep2->previous_episode_id, 'linked to the prior episode');
        $this->assertTrue($ep2->previousEpisode->is($ep1));

        // A third recurrence continues the chain.
        $ep2->update(['lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED, 'resolved_at' => now()]);
        $ep3 = $this->make('SKU-B');
        $this->assertSame(3, $ep3->episode_seq);
        $this->assertSame($ep2->id, $ep3->previous_episode_id);
    }

    public function test_explicit_previous_episode_is_preserved(): void
    {
        $ep1 = $this->make('SKU-C');
        $ep2 = Anomaly::create([
            'tenant_id' => $this->tenant->id, 'rule_type' => 'stockout_risk', 'severity' => 'high',
            'sku' => 'SKU-C', 'store_id' => null, 'description' => 'x', 'detected_at' => now(),
            'previous_episode_id' => $ep1->id, 'episode_seq' => 5,
        ]);
        $this->assertSame(5, $ep2->episode_seq);
        $this->assertSame($ep1->id, $ep2->previous_episode_id);
    }

    public function test_segment_confirmation_map(): void
    {
        $this->assertSame(2, LifecycleReconciler::confirmRunsForSegment('smooth'));
        $this->assertSame(3, LifecycleReconciler::confirmRunsForSegment('intermittent'));
        $this->assertSame(4, LifecycleReconciler::confirmRunsForSegment('lumpy'));
        $this->assertSame(2, LifecycleReconciler::confirmRunsForSegment(null));
        $this->assertSame(2, LifecycleReconciler::confirmRunsForSegment('unknown'));
    }

    public function test_reconciler_applies_per_subject_confirmation(): void
    {
        $reconciler = new LifecycleReconciler();
        $confirmN3 = fn (Anomaly $a): int => 3; // as an intermittent SKU would resolve to

        // Two clears is not enough when the segment needs three.
        $a = $this->make('SKU-D');
        $a->forceFill([
            'lifecycle_state' => Anomaly::LIFECYCLE_CLEARING, 'clear_streak' => 1,
            'cleared_at' => now()->subDay(), 'first_seen_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(2),
        ])->save();

        $reconciler->reconcileRule($this->tenant->id, 'stockout_risk', [], fn () => true, $confirmN3);
        $this->assertSame(Anomaly::LIFECYCLE_CLEARING, $a->refresh()->lifecycle_state);
        $this->assertSame(2, $a->clear_streak);

        // The third clear confirms it.
        $reconciler->reconcileRule($this->tenant->id, 'stockout_risk', [], fn () => true, $confirmN3);
        $this->assertSame(Anomaly::LIFECYCLE_RESOLVED, $a->refresh()->lifecycle_state);
    }
}
