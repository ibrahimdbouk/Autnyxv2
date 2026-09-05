<?php

namespace Tests\Feature;

use App\Models\PromotionNode;
use Tests\TestCase;

/**
 * P1.1 — the canonical Promotion hierarchy: campaign → promotion → offer tree,
 * plus effective-dated filtering (which promotions are active on a date).
 */
class PromotionHierarchyTest extends TestCase
{
    public function test_campaign_promotion_offer_tree_and_ancestry(): void
    {
        $tenant = $this->createTenant();

        $campaign  = PromotionNode::create(['tenant_id' => $tenant->id, 'type' => PromotionNode::TYPE_CAMPAIGN, 'name' => 'Ramadan 2026', 'starts_at' => '2026-03-01', 'ends_at' => '2026-03-31']);
        $promotion = PromotionNode::create(['tenant_id' => $tenant->id, 'type' => PromotionNode::TYPE_PROMOTION, 'name' => 'Dairy 20% off', 'parent_id' => $campaign->id, 'mechanic' => 'pct_off', 'starts_at' => '2026-03-05', 'ends_at' => '2026-03-15']);
        $offer     = PromotionNode::create(['tenant_id' => $tenant->id, 'type' => PromotionNode::TYPE_OFFER, 'name' => 'Milk 1L −20%', 'parent_id' => $promotion->id, 'mechanic' => 'pct_off', 'starts_at' => '2026-03-05', 'ends_at' => '2026-03-15']);

        $this->assertEqualsCanonicalizing([$promotion->id, $offer->id], $campaign->descendantIds());
        $this->assertSame([$promotion->id, $campaign->id], $offer->ancestors()->pluck('id')->all());
    }

    public function test_effective_dated_active_filtering(): void
    {
        $tenant = $this->createTenant();

        $march = PromotionNode::create(['tenant_id' => $tenant->id, 'type' => PromotionNode::TYPE_PROMOTION, 'name' => 'March promo', 'starts_at' => '2026-03-01', 'ends_at' => '2026-03-31']);
        $ended = PromotionNode::create(['tenant_id' => $tenant->id, 'type' => PromotionNode::TYPE_PROMOTION, 'name' => 'January promo', 'starts_at' => '2026-01-01', 'ends_at' => '2026-01-31']);
        $open  = PromotionNode::create(['tenant_id' => $tenant->id, 'type' => PromotionNode::TYPE_PROMOTION, 'name' => 'Evergreen', 'starts_at' => '2026-01-01', 'ends_at' => null]);

        // On 2026-03-10: March + Evergreen active, January not.
        $activeIds = PromotionNode::where('tenant_id', $tenant->id)->activeOn('2026-03-10')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$march->id, $open->id], $activeIds);

        $this->assertTrue($march->isActiveOn('2026-03-10'));
        $this->assertFalse($ended->isActiveOn('2026-03-10'));
        $this->assertTrue($open->isActiveOn('2026-03-10'));
    }
}
