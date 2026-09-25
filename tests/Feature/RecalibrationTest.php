<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\InvestigationCorrelationService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * WP4.6 (D5) — the side-by-side diff writes nothing; recalibration supersedes
 * (never deletes), keeps work in progress, switches the tenant, and can run twice.
 */
class RecalibrationTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        // Two overdue PO lines of one SKU: v1 collapses them, v2 keeps both.
        foreach (['P1', 'P2'] as $po) {
            PurchaseOrder::create(['tenant_id' => $this->tenant->id, 'po_number' => $po, 'supplier' => 'Acme', 'sku' => 'X',
                'qty_ordered' => 10, 'order_date' => Carbon::today()->subDays(20), 'expected_date' => Carbon::today()->subDays(10)]);
        }
        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);   // v1 (default)
        app(InvestigationCorrelationService::class)->correlateForTenant($this->tenant->id);
    }

    public function test_the_diff_reports_both_rule_sets_and_writes_nothing(): void
    {
        $before = [Anomaly::count(), Investigation::count(), \App\Models\SkuBaseline::count()];

        $this->artisan('detection:diff', ['--tenant' => $this->tenant->id])
            ->expectsOutputToContain('nothing written')
            ->assertSuccessful();

        $this->assertSame($before, [Anomaly::count(), Investigation::count(), \App\Models\SkuBaseline::count()]);
        $this->assertFalse(AnomalyDetectionService::rulesV2For($this->tenant->id));
    }

    public function test_recalibration_supersedes_old_signals_keeps_worked_investigations_and_is_repeatable(): void
    {
        $old = Anomaly::where('rule_type', 'po_overdue')->get();
        $this->assertCount(1, $old, 'v1: both PO lines collapse into one anomaly');
        $worked = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'status' => Investigation::STATUS_IN_PROGRESS]);
        Action::create(['investigation_id' => $worked->id, 'action_type' => 'call', 'title' => 'Call Acme', 'status' => 'pending']);
        $idle = Investigation::where('id', '!=', $worked->id)->where('status', Investigation::STATUS_OPEN)->pluck('id');

        $this->artisan('detection:recalibrate', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->assertFalse(AnomalyDetectionService::rulesV2For($this->tenant->id), 'dry run changes nothing');

        $this->artisan('detection:recalibrate', ['--tenant' => $this->tenant->id, '--apply' => true])->assertSuccessful();

        $this->assertTrue(AnomalyDetectionService::rulesV2For($this->tenant->id));
        $this->assertSame('superseded_by_recalibration', $old->first()->fresh()->dismiss_reason, 'kept, not deleted');
        $this->assertSame(2, Anomaly::active()->where('rule_type', 'po_overdue')->count(), 'v2: one per PO line');
        $this->assertSame(Investigation::STATUS_IN_PROGRESS, $worked->fresh()->status);
        $this->assertTrue(Investigation::whereIn('id', $idle)->get()->every(fn ($i) => $i->status === Investigation::STATUS_CLOSED));

        $this->artisan('detection:recalibrate', ['--tenant' => $this->tenant->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(2, Anomaly::active()->where('rule_type', 'po_overdue')->count(), 'a second run adds nothing');
    }
}
