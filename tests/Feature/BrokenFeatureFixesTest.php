<?php

namespace Tests\Feature;

use App\Filament\Resources\AnomalyResource\Pages\ListAnomalies;
use App\Mail\AnomalyDigestMail;
use App\Models\Action;
use App\Models\Anomaly;
use App\Models\IngestionRun;
use App\Models\Investigation;
use App\Models\PurchaseOrder;
use App\Models\SkuBaseline;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WP1.4 — features that were silently broken (audit H34, H14 Carbon part,
 * H19, dashboard overdue, anomalies list sort/filters, NULLS LAST).
 */
class BrokenFeatureFixesTest extends TestCase
{
    private function anomaly(Tenant $t, array $attrs = []): Anomaly
    {
        return Anomaly::create(array_merge([
            'tenant_id' => $t->id, 'rule_type' => 'stockout_risk', 'severity' => 'high', 'sku' => 'SKU-' . uniqid(),
            'description' => 'x', 'detected_at' => now()->subDays(2), 'context' => ['revenue_impact' => 100],
        ], $attrs));
    }

    private function inPanel(Tenant $tenant): void
    {
        // Boot the panel as a real request does, so Filament's tenancy global
        // scope is registered (Livewire::test alone does not boot the panel).
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        $panel->boot();
        Filament::setTenant($tenant);
    }

    // ── H34: anomaly digest ──────────────────────────────────────────────────

    public function test_digest_email_renders(): void
    {
        $t = $this->createTenant();
        $a = $this->anomaly($t);

        $html = (new AnomalyDigestMail($t, Anomaly::whereKey($a->id)->get()))->render();

        $this->assertStringContainsString($a->sku, $html);
    }

    public function test_digest_is_sent_and_marked_when_enabled_and_skipped_when_disabled(): void
    {
        Mail::fake();
        $t = $this->createTenant(['notification_email' => 'ops@example.com', 'notify_on_high' => true]);
        $a = $this->anomaly($t);

        config(['autnyx.digest_enabled' => false]);
        $this->artisan('anomalies:notify')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertNull($a->fresh()->notified_at);

        config(['autnyx.digest_enabled' => true]);
        $this->artisan('anomalies:notify')->assertSuccessful();
        Mail::assertSent(AnomalyDigestMail::class, fn ($m) => $m->hasTo('ops@example.com'));
        $this->assertNotNull($a->fresh()->notified_at);
    }

    // ── H14 (Carbon part): late dismissals are not false positives ───────────

    public function test_dismissing_days_after_detection_does_not_desensitize_detection(): void
    {
        $t = $this->createTenant();
        $a = $this->anomaly($t, ['detected_at' => now()->subDays(3)]);
        $baseline = SkuBaseline::create([
            'tenant_id' => $t->id, 'sku' => $a->sku, 'rule_type' => 'stockout_risk', 'metric' => 'daily_qty',
            'baseline_mean' => 10, 'baseline_stddev' => 2, 'sample_count' => 30, 'sensitivity_multiplier' => 2.0, 'fp_count' => 0,
        ]);
        $this->actingAsTenantAdmin($t);
        $this->inPanel($t);

        Livewire::test(ListAnomalies::class)->callTableAction('dismiss', $a);

        $this->assertNotNull($a->fresh()->dismissed_at);
        $this->assertSame(2.0, (float) $baseline->fresh()->sensitivity_multiplier, 'a dismissal 3 days later is not a quick false-positive signal');
        $this->assertSame(0, (int) $baseline->fresh()->fp_count);
    }

    public function test_ingestion_run_duration_is_positive(): void
    {
        $run = new IngestionRun(['started_at' => now()->subMinutes(5), 'completed_at' => now()]);
        $this->assertSame(300, $run->getDurationSeconds());
    }

    // ── H19: never-received POs ──────────────────────────────────────────────

    public function test_po_overdue_flags_a_po_with_nothing_received(): void
    {
        $t = $this->createTenant();
        PurchaseOrder::create([
            'tenant_id' => $t->id, 'po_number' => 'PO-NULL', 'supplier' => 'Acme', 'sku' => 'PO-X',
            'qty_ordered' => 100, 'qty_received' => null,
            'order_date' => Carbon::today()->subDays(20), 'expected_date' => Carbon::today()->subDays(10),
        ]);

        app(AnomalyDetectionService::class)->runForTenant($t->id);

        $a = Anomaly::where('tenant_id', $t->id)->where('rule_type', 'po_overdue')->where('sku', 'PO-X')->first();
        $this->assertNotNull($a, 'a PO with qty_received NULL and past its expected date is overdue');
        $this->assertStringNotContainsString('.0 day', $a->description);
    }

    // ── Dashboard: overdue actions ──────────────────────────────────────────

    public function test_dashboard_lists_active_actions_instead_of_all_clear(): void
    {
        $t = $this->createTenant();
        $this->actingAsTenantAdmin($t);
        $inv = Investigation::factory()->create(['tenant_id' => $t->id, 'status' => Investigation::STATUS_OPEN]);
        Action::create([
            'investigation_id' => $inv->id, 'action_type' => Action::TYPE_REORDER, 'title' => 'Chase the overdue PO',
            'status' => Action::STATUS_ASSIGNED, 'priority' => Action::PRIORITY_HIGH, 'due_at' => now()->subDay(),
        ]);

        $this->get(\App\Filament\Pages\Dashboard::getUrl(['tenant' => $t]))
            ->assertOk()
            ->assertSee('Chase the overdue PO');
    }

    // ── Anomalies list ──────────────────────────────────────────────────────

    public function test_anomalies_list_defaults_to_active_sorted_high_first_and_can_show_dismissed(): void
    {
        $t = $this->createTenant();
        $other = $this->createTenant();
        $low = $this->anomaly($t, ['severity' => 'low']);
        $high = $this->anomaly($t, ['severity' => 'high']);
        $medium = $this->anomaly($t, ['severity' => 'medium']);
        $dismissed = $this->anomaly($t, ['dismissed_at' => now()]);
        $foreignDismissed = $this->anomaly($other, ['dismissed_at' => now()]);
        $this->actingAsTenantAdmin($t);
        $this->inPanel($t);

        Livewire::test(ListAnomalies::class)
            ->assertCanSeeTableRecords([$high, $medium, $low], inOrder: true)
            ->assertCanNotSeeTableRecords([$dismissed])
            ->filterTable('dismissed', true)
            ->assertCanSeeTableRecords([$dismissed])
            ->assertCanNotSeeTableRecords([$high, $foreignDismissed]);
    }

    public function test_not_started_filter_matches_detected_anomalies(): void
    {
        $t = $this->createTenant();
        $fresh = $this->anomaly($t);
        $this->assertSame('detected', $fresh->fresh()->investigation_status);
        $this->actingAsTenantAdmin($t);
        $this->inPanel($t);

        Livewire::test(ListAnomalies::class)
            ->filterTable('investigation_status', 'detected')
            ->assertCanSeeTableRecords([$fresh]);
    }

    public function test_investigations_sorted_by_value_put_unvalued_rows_last(): void
    {
        $t = $this->createTenant();
        $none = Investigation::factory()->create(['tenant_id' => $t->id, 'status' => Investigation::STATUS_OPEN, 'revenue_at_risk' => null]);
        $big = Investigation::factory()->create(['tenant_id' => $t->id, 'status' => Investigation::STATUS_OPEN, 'revenue_at_risk' => 9000]);
        $this->actingAsTenantAdmin($t);
        $this->inPanel($t);

        $rows = Livewire::test(\App\Filament\Resources\InvestigationResource\Pages\ListInvestigations::class)
            ->set('sortField', 'revenue_at_risk')->set('sortDir', 'desc')
            ->instance()->getInvestigations();

        $this->assertSame($big->id, $rows->first()->id);
        $this->assertSame($none->id, $rows->last()->id);
    }
}
