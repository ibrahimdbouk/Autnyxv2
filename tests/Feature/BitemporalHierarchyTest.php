<?php

namespace Tests\Feature;

use App\Models\LocationNode;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P1.3 — bitemporal reconstruction: superseding a node is non-destructive (the
 * old version is preserved with a closed valid window), and as-of queries return
 * the version that was valid on a given date. History is never overwritten.
 */
class BitemporalHierarchyTest extends TestCase
{
    public function test_supersede_closes_old_version_and_opens_a_successor(): void
    {
        $tenant = $this->createTenant();

        // A node that has been effective since the start of the year.
        $node = LocationNode::create([
            'tenant_id'      => $tenant->id,
            'type'           => LocationNode::TYPE_REGION,
            'name'           => 'North',
            'effective_from' => '2026-01-01',
        ]);

        // Re-name it today (a real business change): old version closes, new opens.
        $successor = $node->supersede(['name' => 'Northern']);

        $node->refresh();
        $this->assertNotNull($node->effective_to, 'the old version is closed, not deleted');
        $this->assertSame('North', $node->name, 'the old version keeps its old value');
        $this->assertNull($successor->effective_to, 'the successor is the current open version');
        $this->assertSame('Northern', $successor->name);
        $this->assertNotNull($successor->recorded_at, 'the successor carries a system-time stamp');

        // Two versions now exist — history preserved.
        $this->assertSame(2, LocationNode::where('tenant_id', $tenant->id)->where('type', 'region')->count());
    }

    public function test_effective_on_reconstructs_the_version_valid_at_a_date(): void
    {
        $tenant = $this->createTenant();

        $node = LocationNode::create([
            'tenant_id'      => $tenant->id,
            'type'           => LocationNode::TYPE_REGION,
            'name'           => 'North',
            'effective_from' => Carbon::today()->subDays(30)->toDateString(),
        ]);
        $node->supersede(['name' => 'Northern']);

        // A month ago, the region was still "North".
        $past = LocationNode::where('tenant_id', $tenant->id)
            ->effectiveOn(Carbon::today()->subDays(10)->toDateString())
            ->get();
        $this->assertCount(1, $past);
        $this->assertSame('North', $past->first()->name);

        // Today, exactly one version is current, and it is "Northern".
        $current = LocationNode::where('tenant_id', $tenant->id)->effectiveOn()->get();
        $this->assertCount(1, $current);
        $this->assertSame('Northern', $current->first()->name);
    }
}
