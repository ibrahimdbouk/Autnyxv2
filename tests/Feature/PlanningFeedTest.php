<?php

namespace Tests\Feature;

use App\Models\PlanningSignal;
use App\Platform\Planning\ForecastBaseline;
use App\Platform\Planning\ForecastIngestor;
use App\Platform\Planning\ForecastPoint;
use App\Platform\Planning\PlanningSignalFeed;
use App\Platform\Planning\SignalEnvelope;
use Tests\TestCase;

/**
 * P2.4 — the bidirectional sensing↔planning feed. Inbound: ingest a planning
 * baseline and read the expectation back. Outbound: publish signals, pull the
 * canonical feed, acknowledge them. Together this is "a signal feed consumable by
 * the tenant's replenishment system, and its forecasts consumed by Autnyx."
 */
class PlanningFeedTest extends TestCase
{
    public function test_ingested_forecast_becomes_the_readable_baseline(): void
    {
        $tenant = $this->createTenant();
        $ingestor = app(ForecastIngestor::class);

        $written = $ingestor->ingest($tenant->id, [
            new ForecastPoint(sku: 'SKU-1', targetDate: '2026-09-10', forecastQty: 42.0, storeId: 7, plannedOrderQty: 40.0),
        ], source: 'relex');

        $this->assertSame(1, $written);

        $baseline = app(ForecastBaseline::class);
        $this->assertSame(42.0, $baseline->expectedDemand($tenant->id, 'SKU-1', 7, '2026-09-10'));
        $this->assertSame(40.0, $baseline->plannedOrder($tenant->id, 'SKU-1', 7, '2026-09-10'));
    }

    public function test_reingesting_the_same_key_upserts_not_duplicates(): void
    {
        $tenant = $this->createTenant();
        $ingestor = app(ForecastIngestor::class);

        $ingestor->ingest($tenant->id, [
            new ForecastPoint(sku: 'SKU-1', targetDate: '2026-09-10', forecastQty: 42.0, storeId: 7),
        ], source: 'relex');
        $ingestor->ingest($tenant->id, [
            new ForecastPoint(sku: 'SKU-1', targetDate: '2026-09-10', forecastQty: 55.0, storeId: 7),
        ], source: 'relex');

        $baseline = app(ForecastBaseline::class);
        // One row, refreshed to the latest plan.
        $this->assertSame(55.0, $baseline->expectedDemand($tenant->id, 'SKU-1', 7, '2026-09-10'));
    }

    public function test_baseline_is_null_when_planning_has_no_view(): void
    {
        $tenant = $this->createTenant();
        $baseline = app(ForecastBaseline::class);

        $this->assertNull($baseline->expectedDemand($tenant->id, 'UNKNOWN', 1, '2026-09-10'));
    }

    public function test_published_signals_surface_in_the_feed_as_canonical_envelopes(): void
    {
        $tenant = $this->createTenant();
        $feed = app(PlanningSignalFeed::class);

        $feed->publish(
            $tenant->id,
            PlanningSignal::TYPE_EXCEPTION,
            sku: 'SKU-1',
            storeId: 7,
            severity: PlanningSignal::SEVERITY_CRITICAL,
            delta: -120.0,
            rationale: 'Sales collapsed vs plan',
            source: 'investigation:1',
        );

        $envelopes = $feed->feed($tenant->id);
        $this->assertCount(1, $envelopes);

        $envelope = $envelopes->first();
        $this->assertSame(SignalEnvelope::CONTRACT_VERSION, $envelope['contract_version']);
        $this->assertSame('exception', $envelope['signal_type']);
        $this->assertSame('SKU-1', $envelope['subject']['sku']);
        $this->assertSame(-120.0, $envelope['delta']);
    }

    public function test_consumed_signals_leave_the_feed(): void
    {
        $tenant = $this->createTenant();
        $feed = app(PlanningSignalFeed::class);

        $signal = $feed->publish($tenant->id, PlanningSignal::TYPE_RECOVERY, sku: 'SKU-9');

        $this->assertCount(1, $feed->feed($tenant->id));

        $acked = $feed->markConsumed($tenant->id, [$signal->id]);

        $this->assertSame(1, $acked);
        $this->assertCount(0, $feed->feed($tenant->id));
    }
}
