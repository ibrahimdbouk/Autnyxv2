<?php

namespace Tests\Unit;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Services\InvestigationNarratorService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvestigationFlowTest extends TestCase
{
    /**
     * An anomaly can be linked to an investigation via investigation_id,
     * and the relationship is retrievable from both sides.
     */
    public function test_investigation_can_be_linked_to_anomalies(): void
    {
        $tenant = $this->createTenant();

        $investigation = Investigation::factory()->create(['tenant_id' => $tenant->id]);
        $anomaly       = Anomaly::factory()->create(['tenant_id' => $tenant->id]);

        // Link the anomaly to the investigation
        $anomaly->update(['investigation_id' => $investigation->id]);

        // Relationship from anomaly side
        $this->assertEquals($investigation->id, $anomaly->fresh()->investigation->id);

        // Relationship from investigation side
        $linked = $investigation->anomalies()->first();
        $this->assertNotNull($linked);
        $this->assertEquals($anomaly->id, $linked->id);
    }

    /**
     * An investigation progresses through open → in_progress → resolved.
     * resolved_at is set when the status transitions to 'resolved'.
     */
    public function test_investigation_status_progression(): void
    {
        $tenant = $this->createTenant();

        $investigation = Investigation::factory()->create([
            'tenant_id' => $tenant->id,
            'status'    => Investigation::STATUS_OPEN,
        ]);

        $this->assertNull($investigation->resolved_at);
        $this->assertEquals(Investigation::STATUS_OPEN, $investigation->status);

        // Transition to in_progress
        $investigation->update(['status' => Investigation::STATUS_IN_PROGRESS]);
        $this->assertEquals(Investigation::STATUS_IN_PROGRESS, $investigation->fresh()->status);
        $this->assertNull($investigation->fresh()->resolved_at);

        // Transition to resolved
        $investigation->update([
            'status'      => Investigation::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        $fresh = $investigation->fresh();
        $this->assertEquals(Investigation::STATUS_RESOLVED, $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
    }

    /**
     * InvestigationNarratorService correctly parses a Claude API JSON response
     * and persists ai_summary, ai_root_cause, ai_confidence, and ai_recommended_action
     * on the Investigation model.
     */
    public function test_narrator_response_parsing(): void
    {
        $tenant = $this->createTenant();

        $investigation = Investigation::factory()->create([
            'tenant_id' => $tenant->id,
            'status'    => Investigation::STATUS_IN_PROGRESS,
        ]);

        // Fake the Anthropic API response with a valid narrator schema payload
        $fakePayload = [
            'summary'            => 'Sales of SKU-TEST dropped 45% below 30-day average.',
            'root_cause'         => 'Regional promotional campaign ended, reducing footfall at affected stores.',
            'confidence'         => Investigation::CONFIDENCE_PROBABLE,
            'recommended_action' => 'Re-activate regional promotions or adjust replenishment targets.',
            'revenue_at_risk'    => 12500.00,
        ];

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => json_encode($fakePayload)],
                ],
            ], 200),
        ]);

        // Create minimal evidence so the narrator doesn't skip
        $investigation->evidence()->create([
            'evidence_type' => 'sales_summary',
            'source'        => 'sales_transactions',   // required (NOT NULL); the collector always sets this
            'direction'     => 'supports',
            'label'         => 'Recent sales data',
            'value_text'    => '45% drop vs 30-day avg',
        ]);

        $narrator = app(InvestigationNarratorService::class);
        $result   = $narrator->narrate($investigation, force: true);

        $this->assertEquals($fakePayload['summary'],            $result->ai_summary);
        $this->assertEquals($fakePayload['root_cause'],         $result->ai_root_cause);
        $this->assertEquals($fakePayload['confidence'],         $result->ai_confidence);
        $this->assertEquals($fakePayload['recommended_action'], $result->ai_recommended_action);
        $this->assertNotNull($result->ai_generated_at);
    }
}
