<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Services\Recovery\AnomalyIdentity;
use Tests\TestCase;

/**
 * R1 — creating an anomaly materialises its identity + lifecycle foundation, and
 * the value-at-open is frozen (later context changes must not rewrite it).
 */
class AnomalyLifecycleFoundationTest extends TestCase
{
    private function makeAnomaly(array $overrides = []): Anomaly
    {
        $tenant = $this->createTenant();

        return Anomaly::create(array_merge([
            'tenant_id'   => $tenant->id,
            'rule_type'   => 'sales_drop',
            'severity'    => 'medium',
            'sku'         => 'SKU-1',
            'store_id'    => null,
            'description' => 'Sales fell.',
            'context'     => ['revenue_impact' => 1000],
            'detected_at' => now(),
        ], $overrides));
    }

    public function test_creating_materialises_identity_and_state(): void
    {
        $a = $this->makeAnomaly();

        $this->assertSame(AnomalyIdentity::forAnomaly($a), $a->identity_key);
        $this->assertSame(Anomaly::LIFECYCLE_OPEN, $a->lifecycle_state);
        $this->assertSame(1, $a->episode_seq);
        $this->assertSame(1, $a->occurrence_count);
        $this->assertNotNull($a->first_seen_at);
        $this->assertNotNull($a->last_seen_at);
        $this->assertFalse($a->backfilled);
    }

    public function test_value_at_open_is_frozen_from_context(): void
    {
        $a = $this->makeAnomaly(['context' => ['revenue_impact' => 1000]]);
        $this->assertSame(1000.0, $a->value_at_open);
    }

    public function test_value_at_open_does_not_change_when_context_is_updated(): void
    {
        $a = $this->makeAnomaly(['context' => ['revenue_impact' => 1000]]);

        // A persisting update rewrites detection fields incl. context — but the
        // frozen value-at-open must survive so recovery history is not rewritten.
        $a->update(['context' => ['revenue_impact' => 5000]]);
        $a->refresh();

        $this->assertSame(1000.0, $a->value_at_open);
    }

    public function test_value_at_open_is_null_when_rule_records_no_impact(): void
    {
        $a = $this->makeAnomaly(['context' => ['segment' => 'intermittent']]);
        $this->assertNull($a->value_at_open);
    }

    public function test_previous_episode_relation_links_recurrence(): void
    {
        $tenant = $this->createTenant();
        $ep1 = Anomaly::create([
            'tenant_id' => $tenant->id, 'rule_type' => 'stockout_risk', 'severity' => 'high',
            'sku' => 'SKU-9', 'store_id' => null, 'description' => 'x', 'detected_at' => now()->subDays(10),
        ]);
        $ep2 = Anomaly::create([
            'tenant_id' => $tenant->id, 'rule_type' => 'stockout_risk', 'severity' => 'high',
            'sku' => 'SKU-9', 'store_id' => null, 'description' => 'x', 'detected_at' => now(),
            'previous_episode_id' => $ep1->id, 'episode_seq' => 2,
        ]);

        $this->assertTrue($ep2->previousEpisode->is($ep1));
        $this->assertSame($ep1->identity_key, $ep2->identity_key, 'same subject → same identity across episodes');
        $this->assertSame(2, $ep2->episode_seq);
    }
}
