<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationEvidence;
use Tests\TestCase;

/**
 * Investigation → PDF export. A route-hitting test that actually renders the PDF,
 * so a bad field/relation reference in the Blade is caught here (CI's resource
 * smoke never hits this controller route — cf. INC-009).
 */
class InvestigationPdfExportTest extends TestCase
{
    public function test_investigation_pdf_renders_for_a_tenant_user(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, admin: true);

        $inv = Investigation::factory()->create([
            'tenant_id'             => $tenant->id,
            'title'                 => 'Stockout on SKU-1',
            'status'                => Investigation::STATUS_OPEN,
            'priority'              => Investigation::PRIORITY_HIGH,
            'revenue_at_risk'       => 1500,
            'ai_summary'            => 'Demand outran cover.',
            'ai_root_cause'         => 'Supplier fill-rate slipped.',
            'ai_recommended_action' => 'Expedite the open PO.',
        ]);

        $a = Anomaly::create([
            'tenant_id' => $tenant->id, 'investigation_id' => $inv->id,
            'rule_type' => 'stockout_risk', 'severity' => 'high', 'sku' => 'SKU-1',
            'description' => 'x', 'detected_at' => now(), 'context' => ['revenue_impact' => 500],
        ]);
        Action::create([
            'investigation_id' => $inv->id, 'action_type' => Action::TYPE_REORDER,
            'title' => 'Reorder 40 units', 'status' => Action::STATUS_ASSIGNED, 'priority' => Action::PRIORITY_HIGH,
        ]);
        InvestigationEvidence::create([
            'investigation_id' => $inv->id, 'anomaly_id' => $a->id,
            'evidence_type' => InvestigationEvidence::TYPE_SNAPSHOT, 'source' => 'inventory_levels',
            'label' => 'Current on-hand quantity', 'value_numeric' => 3, 'unit' => 'units',
            'direction' => InvestigationEvidence::DIRECTION_SUPPORTS, 'strength' => InvestigationEvidence::STRENGTH_STRONG,
            'observed_at' => now(),
        ]);

        $resp = $this->actingAs($user)->get(route('investigation.report.pdf', $inv->id));

        $resp->assertOk();
        $this->assertStringContainsString('pdf', strtolower((string) $resp->headers->get('content-type')));
    }

    public function test_pdf_is_tenant_scoped(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $userB = $this->createUser($tenantB);

        $inv = Investigation::factory()->create(['tenant_id' => $tenantA->id, 'title' => 'A']);

        // A user from another tenant cannot export tenant A's investigation.
        $this->actingAs($userB)->get(route('investigation.report.pdf', $inv->id))->assertForbidden();
    }
}
