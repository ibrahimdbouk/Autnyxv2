<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchMovement;
use App\Models\ComplianceEvent;
use App\Platform\Traceability\BatchTraceability;
use App\Platform\Traceability\ComplianceLog;
use App\Platform\Traceability\ExpiryMonitor;
use Tests\TestCase;

/**
 * P3.3 — vertical data model: batch/expiry anomalies + traceability end-to-end.
 */
class TraceabilityTest extends TestCase
{
    private function batch(int $tenantId, array $attrs = []): Batch
    {
        return Batch::create(array_merge([
            'tenant_id'  => $tenantId,
            'sku'        => 'SKU-1',
            'batch_code' => 'LOT-1',
            'quantity'   => 100,
            'status'     => Batch::STATUS_ACTIVE,
        ], $attrs));
    }

    public function test_trace_returns_the_chain_and_on_hand_nets_movements(): void
    {
        $tenant = $this->createTenant();
        $batch = $this->batch($tenant->id);
        $trace = app(BatchTraceability::class);

        $trace->record($batch, BatchMovement::TYPE_RECEIPT, 100, null, 'DC', occurredAt: now()->subDays(3));
        $trace->record($batch, BatchMovement::TYPE_TRANSFER, 100, 'DC', 'Store-7', occurredAt: now()->subDays(2));
        $trace->record($batch, BatchMovement::TYPE_SALE, 30, 'Store-7', null, occurredAt: now()->subDay());

        $chain = $trace->trace($batch);
        $this->assertCount(3, $chain);
        $this->assertSame('receipt', $chain->first()->movement_type); // oldest first

        // 100 received − 30 sold = 70 on hand; transfer nets zero.
        $this->assertSame(70.0, $trace->onHand($batch));
        // current location is the last destination that was set (the transfer to Store-7)
        $this->assertSame('Store-7', $trace->locate($batch));
    }

    public function test_expiry_monitor_flags_expired_and_expiring(): void
    {
        $tenant = $this->createTenant();
        $this->batch($tenant->id, ['batch_code' => 'FRESH', 'expiry_date' => now()->addDays(60)]);
        $this->batch($tenant->id, ['batch_code' => 'SOON', 'expiry_date' => now()->addDays(3)]);
        $this->batch($tenant->id, ['batch_code' => 'GONE', 'expiry_date' => now()->subDays(2)]);

        $monitor = app(ExpiryMonitor::class);

        $this->assertEqualsCanonicalizing(['SOON'], $monitor->expiringWithin($tenant->id, 7)->pluck('batch_code')->all());
        $this->assertEqualsCanonicalizing(['GONE'], $monitor->expired($tenant->id)->pluck('batch_code')->all());

        $anoms = collect($monitor->anomalies($tenant->id, 7));
        $this->assertSame('critical', $anoms->firstWhere('batch_code', 'GONE')['severity']);
        $this->assertSame('warning', $anoms->firstWhere('batch_code', 'SOON')['severity']);
        $this->assertNull($anoms->firstWhere('batch_code', 'FRESH')); // not flagged
    }

    public function test_recall_flips_status_and_logs_a_critical_event(): void
    {
        $tenant = $this->createTenant();
        $batch = $this->batch($tenant->id);
        $log = app(ComplianceLog::class);

        $event = $log->recall($batch, 'Supplier recall notice #42');

        $this->assertSame(Batch::STATUS_RECALLED, $batch->fresh()->status);
        $this->assertSame(ComplianceEvent::TYPE_RECALL, $event->event_type);
        $this->assertSame('critical', $event->severity);
        $this->assertCount(1, $log->open($tenant->id));
    }

    public function test_traceability_is_tenant_scoped(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $this->batch($a->id, ['batch_code' => 'A-ONLY', 'expiry_date' => now()->subDay()]);

        $monitor = app(ExpiryMonitor::class);
        $this->assertCount(1, $monitor->expired($a->id));
        $this->assertCount(0, $monitor->expired($b->id));
    }
}
