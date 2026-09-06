<?php

namespace App\Platform\Traceability;

use App\Models\Batch;
use Illuminate\Support\Collection;

/**
 * P3.3 — batch/expiry anomaly detection. The "batch/expiry anomalies" half of the
 * P3.3 definition of done: which lots are past expiry but still in stock, and
 * which are about to expire. Returns anomaly rows apps can raise, publish to the
 * P2.4 planning feed, or turn into recovery actions.
 */
class ExpiryMonitor
{
    /**
     * Active batches expiring within $days from today (not yet expired).
     *
     * @return Collection<int,Batch>
     */
    public function expiringWithin(int $tenantId, int $days): Collection
    {
        $today = now()->startOfDay();

        return Batch::query()
            ->where('tenant_id', $tenantId)
            ->where('status', Batch::STATUS_ACTIVE)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $today->copy()->addDays($days))
            ->where('quantity', '>', 0)
            ->orderBy('expiry_date')
            ->get();
    }

    /**
     * Batches already past expiry but still holding stock (the risk case).
     *
     * @return Collection<int,Batch>
     */
    public function expired(int $tenantId): Collection
    {
        return Batch::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', [Batch::STATUS_DISPOSED])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->startOfDay())
            ->where('quantity', '>', 0)
            ->orderBy('expiry_date')
            ->get();
    }

    /**
     * Structured expiry anomalies for a tenant: expired (critical) + expiring
     * (warning), each with days-to-expiry.
     *
     * @return array<int,array{batch_id:int,sku:string,batch_code:string,severity:string,days_to_expiry:int}>
     */
    public function anomalies(int $tenantId, int $warnDays = 7): array
    {
        $today = now()->startOfDay();
        $rows = [];

        foreach ($this->expired($tenantId) as $b) {
            $rows[] = [
                'batch_id'       => $b->id,
                'sku'            => $b->sku,
                'batch_code'     => $b->batch_code,
                'severity'       => 'critical',
                'days_to_expiry' => (int) $today->diffInDays($b->expiry_date, false),
            ];
        }

        foreach ($this->expiringWithin($tenantId, $warnDays) as $b) {
            $rows[] = [
                'batch_id'       => $b->id,
                'sku'            => $b->sku,
                'batch_code'     => $b->batch_code,
                'severity'       => 'warning',
                'days_to_expiry' => (int) $today->diffInDays($b->expiry_date, false),
            ];
        }

        return $rows;
    }
}
