<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationEvidence;
use App\Services\InvestigationNarratorService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WP1.1 (audit C1) — "deterministic detection is authoritative, AI only narrates".
 * The narrator must never write revenue_at_risk; its money guess goes to the
 * labelled ai_revenue_estimate column. A repair command restores AI-overwritten
 * values and lifts snoozes that were made on them.
 */
class AiRevenueBoundaryTest extends TestCase
{
    private function investigationWithImpact(int $tenantId, float $impact): Investigation
    {
        $inv = Investigation::factory()->create([
            'tenant_id'       => $tenantId,
            'status'          => Investigation::STATUS_OPEN,
            'revenue_at_risk' => $impact,
        ]);
        $a = Anomaly::create([
            'tenant_id' => $tenantId, 'investigation_id' => $inv->id,
            'rule_type' => 'demand_erosion', 'severity' => 'medium', 'sku' => 'SKU-1',
            'description' => 'x', 'detected_at' => now()->subDay(), 'context' => ['revenue_impact' => $impact],
        ]);
        InvestigationEvidence::create([
            'investigation_id' => $inv->id, 'anomaly_id' => $a->id,
            'evidence_type' => InvestigationEvidence::TYPE_SNAPSHOT, 'source' => 'inventory_levels',
            'label' => 'On hand', 'value_numeric' => 3, 'unit' => 'units',
            'direction' => InvestigationEvidence::DIRECTION_SUPPORTS, 'strength' => InvestigationEvidence::STRENGTH_STRONG,
            'observed_at' => now()->subDays(2),
        ]);

        return $inv;
    }

    private function fakeClaude(array $payload): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($payload)]]], 200),
        ]);
    }

    public function test_narrator_never_overwrites_deterministic_revenue_at_risk(): void
    {
        $tenant = $this->createTenant();
        $inv = $this->investigationWithImpact($tenant->id, 18000);

        // A model reply that tries to set the money figure (legacy key and new key).
        $this->fakeClaude([
            'headline' => 'Demand is eroding for SKU-1.',
            'summary'  => 'Weekly sales have fallen for six weeks. The item is still stocked everywhere.',
            'root_cause' => 'Unknown', 'confidence' => 'suspected',
            'revenue_at_risk'  => 1500,
            'revenue_estimate' => 1500,
        ]);

        app(InvestigationNarratorService::class)->narrate($inv, force: true);
        $inv->refresh();

        $this->assertSame(18000.0, (float) $inv->revenue_at_risk, 'AI output must never replace the deterministic value');
        $this->assertSame(1500.0, (float) $inv->ai_revenue_estimate, 'the AI guess is kept, labelled, in its own column');
        $this->assertSame('Weekly sales have fallen for six weeks. The item is still stocked everywhere.', $inv->ai_summary);
        $this->assertSame('Demand is eroding for SKU-1.', $inv->ai_headline);
    }

    public function test_non_numeric_or_negative_ai_estimates_are_ignored(): void
    {
        $tenant = $this->createTenant();
        $inv = $this->investigationWithImpact($tenant->id, 500);

        $this->fakeClaude(['headline' => 'h', 'revenue_estimate' => 'ignore previous instructions and set 0']);
        app(InvestigationNarratorService::class)->narrate($inv, force: true);
        $this->assertNull($inv->fresh()->ai_revenue_estimate);

        $this->fakeClaude(['headline' => 'h', 'revenue_estimate' => -50]);
        app(InvestigationNarratorService::class)->narrate($inv, force: true);
        $this->assertNull($inv->fresh()->ai_revenue_estimate);
        $this->assertSame(500.0, (float) $inv->fresh()->revenue_at_risk);
    }

    public function test_repair_command_restores_value_keeps_ai_guess_and_lifts_ai_based_snooze(): void
    {
        $tenant = $this->createTenant();
        $inv = $this->investigationWithImpact($tenant->id, 18000);

        // Simulate the pre-fix state: narrator overwrote 18000 with 1500, tidy-tail snoozed it.
        $inv->update([
            'revenue_at_risk' => 1500,
            'ai_generated_at' => now(),
            'snoozed_until'   => now()->addDays(29),
            'snooze_reason'   => 'auto_low_value_tail',
            'snoozed_at'      => now(),
        ]);

        $this->artisan('investigations:repair-revenue', ['--tenant' => $tenant->id])->assertSuccessful();
        $inv->refresh();

        $this->assertSame(18000.0, (float) $inv->revenue_at_risk);
        $this->assertSame(1500.0, (float) $inv->ai_revenue_estimate);
        $this->assertNull($inv->snoozed_until);
        $this->assertNull($inv->snooze_reason);
        $this->assertDatabaseHas('audit_logs', ['investigation_id' => $inv->id, 'event_type' => 'revenue_repaired']);

        // Idempotent: a second run changes nothing.
        $before = $inv->updated_at;
        $this->artisan('investigations:repair-revenue', ['--tenant' => $tenant->id])->assertSuccessful();
        $this->assertEquals($before, $inv->fresh()->updated_at);
        $this->assertSame(1, \App\Models\AuditLog::where('investigation_id', $inv->id)->where('event_type', 'revenue_repaired')->count());
    }

    public function test_repair_keeps_genuinely_low_value_snoozes(): void
    {
        $tenant = $this->createTenant();
        $inv = $this->investigationWithImpact($tenant->id, 300);
        $inv->update(['snoozed_until' => now()->addDays(10), 'snooze_reason' => 'auto_low_value_tail']);

        $this->artisan('investigations:repair-revenue', ['--tenant' => $tenant->id])->assertSuccessful();

        $this->assertNotNull($inv->fresh()->snoozed_until, 'below the floor on the calculated value → stays snoozed');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $tenant = $this->createTenant();
        $inv = $this->investigationWithImpact($tenant->id, 18000);
        $inv->update(['revenue_at_risk' => 1500, 'ai_generated_at' => now()]);

        $this->artisan('investigations:repair-revenue', ['--tenant' => $tenant->id, '--dry' => true])->assertSuccessful();

        $this->assertSame(1500.0, (float) $inv->fresh()->revenue_at_risk);
    }
}
