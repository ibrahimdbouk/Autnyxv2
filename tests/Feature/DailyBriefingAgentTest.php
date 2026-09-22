<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Services\Agents\DailyBriefingAgent;
use Tests\TestCase;

/**
 * Daily Briefing — the deterministic inputs the agent narrates. We test the
 * governed aggregation directly (no AI call): the numbers must be real counts of
 * today's movement, so the narration on top can never invent them.
 */
class DailyBriefingAgentTest extends TestCase
{
    public function test_daily_stats_aggregate_todays_movement(): void
    {
        $tenant = $this->createTenant();

        // Opened today, high priority, still open.
        Investigation::factory()->create([
            'tenant_id'       => $tenant->id,
            'opened_at'       => now(),
            'revenue_at_risk' => 1500,
            'status'          => Investigation::STATUS_OPEN,
            'priority'        => Investigation::PRIORITY_HIGH,
        ]);

        // A signal detected today.
        Anomaly::create([
            'tenant_id'   => $tenant->id,
            'rule_type'   => 'stockout_risk',
            'severity'    => 'high',
            'sku'         => 'SKU-1',
            'description' => 'x',
            'detected_at' => now(),
            'context'     => ['revenue_impact' => 500],
        ]);

        // Noise that must NOT count as "today": opened a week ago.
        Investigation::factory()->create([
            'tenant_id' => $tenant->id,
            'opened_at' => now()->subDays(7),
            'status'    => Investigation::STATUS_OPEN,
            'priority'  => Investigation::PRIORITY_LOW,
        ]);

        $stats = app(DailyBriefingAgent::class)->dailyStats($tenant->id);

        $this->assertSame(1, $stats['opened_today'], 'only today\'s opened investigation counts');
        $this->assertGreaterThanOrEqual(1, $stats['new_signals']);
        $this->assertSame(2, $stats['open_now'], 'both open investigations are standing open');
        $this->assertSame(1, $stats['high']);
        $this->assertSame(0, $stats['blocked_feeds'], 'no RED feed → none blocked');
        $this->assertIsArray($stats['top_campaigns']);
        $this->assertArrayHasKey('currency', $stats);
    }
}
