<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\Suppression;
use App\Services\Anomaly\InvestigationCorrelationService;
use App\Services\Noise\SnoozeService;
use App\Services\Noise\SuppressionService;
use Tests\TestCase;

class SnoozeSuppressTest extends TestCase
{
    public function test_suppression_scope_matches_rule_and_sku(): void
    {
        $tenant  = $this->createTenant();
        $anomaly = Anomaly::factory()->create([
            'tenant_id' => $tenant->id, 'rule_type' => 'sales_drop', 'sku' => 'SKU-X', 'store_id' => null,
        ]);

        Suppression::create([
            'tenant_id'  => $tenant->id,
            'scope_type' => Suppression::SCOPE_RULE_SKU,
            'rule_type'  => 'sales_drop',
            'sku'        => 'SKU-X',
            'reason'     => Suppression::REASON_KNOWN_ISSUE,
            'starts_at'  => now()->subDay(),
            'expires_at' => now()->addDay(),
            'active'     => true,
        ]);

        $service = new SuppressionService();
        $this->assertTrue($service->isSuppressed($anomaly));

        // A different SKU is not suppressed
        $other = Anomaly::factory()->create(['tenant_id' => $tenant->id, 'rule_type' => 'sales_drop', 'sku' => 'SKU-Y']);
        $this->assertFalse($service->isSuppressed($other));
    }

    public function test_correlation_skips_suppressed_anomaly(): void
    {
        $tenant  = $this->createTenant();
        $anomaly = Anomaly::factory()->create([
            'tenant_id' => $tenant->id, 'rule_type' => 'sales_drop', 'sku' => 'SKU-X', 'store_id' => null,
        ]);

        $suppression = Suppression::create([
            'tenant_id'  => $tenant->id,
            'scope_type' => Suppression::SCOPE_RULE_SKU,
            'rule_type'  => 'sales_drop',
            'sku'        => 'SKU-X',
            'reason'     => Suppression::REASON_KNOWN_ISSUE,
            'starts_at'  => now()->subDay(),
            'expires_at' => now()->addDay(),
            'active'     => true,
        ]);

        app(InvestigationCorrelationService::class)->correlateForTenant($tenant->id);

        // Anomaly stays recorded but is NOT correlated into an investigation
        $this->assertNull($anomaly->fresh()->investigation_id);
        $this->assertSame(0, Investigation::where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, $suppression->fresh()->match_count);
    }

    public function test_snooze_sets_and_expires(): void
    {
        $tenant = $this->createTenant();
        $inv    = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        $service = new SnoozeService();
        $service->snooze($inv, now()->addDay(), SnoozeService::REASONS ? 'known_issue' : 'other');
        $this->assertTrue($inv->fresh()->isSnoozed());

        // Force the window into the past and clear
        $inv->update(['snoozed_until' => now()->subHour()]);
        $cleared = $service->clearExpired($tenant->id);
        $this->assertSame(1, $cleared);
        $this->assertNull($inv->fresh()->snoozed_until);
    }

    public function test_suppression_expires_due(): void
    {
        $tenant = $this->createTenant();
        Suppression::create([
            'tenant_id'  => $tenant->id,
            'scope_type' => Suppression::SCOPE_RULE,
            'rule_type'  => 'sales_drop',
            'reason'     => Suppression::REASON_OTHER,
            'starts_at'  => now()->subDays(2),
            'expires_at' => now()->subHour(),
            'active'     => true,
        ]);

        $expired = (new SuppressionService())->expireDue($tenant->id);
        $this->assertSame(1, $expired);
        $this->assertSame(0, Suppression::currentlyActive()->where('tenant_id', $tenant->id)->count());
    }
}
