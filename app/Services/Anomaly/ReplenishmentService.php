<?php

namespace App\Services\Anomaly;

use App\Models\SkuProfile;
use App\Models\SkuReplenishment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * B4 — Prescriptive replenishment.
 *
 * Promotes the best-fit demand layer from detection to prescription. For every
 * stocked (store, SKU) it derives, from the demand profile and observed supplier
 * lead times:
 *
 *   daily rate   d = mean_nonzero / ADI            (the Croston rate: size × prob/day)
 *   demand σ/day σ = mean_nonzero · √(p·(cv² + 1 − p))   p = 1/ADI  (compound Bernoulli–size)
 *   safety stock SS = Z · σ · √L                    L = observed lead time, Z = service level
 *   reorder pt   ROP = d·L + SS
 *   order-up-to  S   = d·(L + R) + SS               R = review period
 *   suggest qty  = max(0, S − on_hand)
 *
 * The variance form is the intermittent-demand one (most days are zero), so the
 * safety stock is honest for lumpy items rather than a fast-mover approximation.
 * Nothing here overwrites a reorder point the tenant supplied — the engine only
 * *falls back* to these where the tenant has none.
 */
class ReplenishmentService
{
    /** Service-level Z (1.65 ≈ 95%). */
    private const Z = 1.65;
    private const SERVICE_LEVEL_PCT = 95.0;

    /** Review period in days (how often replenishment is revisited). */
    private const REVIEW_DAYS = 7;

    /** Lead time when a SKU/tenant has no received-PO history to measure from. */
    private const DEFAULT_LEAD_DAYS = 7.0;
    private const MIN_LEAD_DAYS = 1.0;

    /** Segments that don't warrant a replenishment target. */
    private const SKIP_SEGMENTS = [SkuProfile::SEG_DEAD, SkuProfile::SEG_NEW, SkuProfile::SEG_UNKNOWN];

    public function computeForTenant(int $tenantId): int
    {
        [$leadBySku, $supplierBySku, $tenantAvgLead] = $this->leadTimes($tenantId);
        // WP6.3: on-hand comes with each profile row (joined), not as a map of every position.
        app(\App\Services\Inventory\InventoryCurrentService::class)->ensure($tenantId);
        $costBySku = $this->costs($tenantId);

        // Rows the tenant supplied from an F&R params feed are authoritative — the
        // compute never touches them (skipped on write, excluded from the sweep).
        $ingested = $this->ingestedKeys($tenantId);

        $now      = Carbon::now();
        $rows     = [];
        $written  = 0;

        // Store-level profiles only (store_id != 0): replenishment is per location.
        DB::table('sku_profiles as p')
            ->leftJoin('inventory_current as c', fn ($j) => $j->on('c.tenant_id', '=', 'p.tenant_id')
                ->on('c.store_id', '=', 'p.store_id')->on('c.sku', '=', 'p.sku'))
            ->where('p.tenant_id', $tenantId)
            ->where('p.store_id', '!=', 0)
            ->whereNotNull('p.adi')
            ->select(['p.sku', 'p.store_id', 'p.segment', 'p.mean_nonzero', 'p.adi', 'p.cv2', 'c.on_hand_qty as on_hand'])
            ->orderBy('p.id')
            ->cursor()
            ->each(function ($p) use (
                &$rows, &$written, $tenantId, $now,
                $leadBySku, $supplierBySku, $tenantAvgLead, $costBySku, $ingested
            ) {
                if (in_array($p->segment, self::SKIP_SEGMENTS, true)) return;

                $sku  = trim((string) $p->sku);
                if (isset($ingested[$p->store_id . '|' . $sku])) return; // tenant-supplied wins
                $mean = (float) $p->mean_nonzero;
                $adi  = (float) $p->adi;
                $cv2  = (float) $p->cv2;
                if ($mean <= 0 || $adi <= 0) return;

                $prob = 1.0 / max(1.0, $adi);              // demand probability per day
                $rate = $mean * $prob;                     // expected units/day
                if ($rate <= 0) return;

                $varTerm = max(0.0, $cv2 + 1.0 - $prob);
                $sigmaDaily = $mean * sqrt($prob * $varTerm);

                $lead = $leadBySku[$sku] ?? $tenantAvgLead ?? self::DEFAULT_LEAD_DAYS;
                $lead = max(self::MIN_LEAD_DAYS, $lead);

                $safety   = self::Z * $sigmaDaily * sqrt($lead);
                $rop      = $rate * $lead + $safety;
                $orderUp  = $rate * ($lead + self::REVIEW_DAYS) + $safety;

                $oh       = $p->on_hand !== null ? (float) $p->on_hand : null;
                $suggest  = max(0.0, $orderUp - (float) ($oh ?? 0.0));
                $cost     = $costBySku[$sku] ?? null;

                $rows[] = [
                    'tenant_id'           => $tenantId,
                    'sku'                 => $sku,
                    'store_id'            => (int) $p->store_id,
                    'supplier'            => $supplierBySku[$sku] ?? null,
                    'segment'             => $p->segment,
                    'daily_rate'          => round($rate, 4),
                    'lead_time_days'      => round($lead, 2),
                    'safety_stock'        => round($safety, 2),
                    'reorder_point'       => round($rop, 2),
                    'order_up_to'         => round($orderUp, 2),
                    'on_hand'             => $oh !== null ? round((float) $oh, 2) : null,
                    'suggested_order_qty' => round($suggest, 2),
                    'unit_cost'           => $cost !== null ? round($cost, 4) : null,
                    'order_value'         => round($suggest * (float) ($cost ?? 0), 2),
                    'service_level'       => self::SERVICE_LEVEL_PCT,
                    'source'              => SkuReplenishment::SOURCE_COMPUTED,
                    'computed_at'         => $now,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ];

                if (count($rows) >= 1000) {
                    $written += $this->flush($rows);
                    $rows = [];
                }
            });

        if (! empty($rows)) {
            $written += $this->flush($rows);
        }

        // Drop COMPUTED rows for (store, SKU) that no longer qualify this run.
        // Ingested (tenant-supplied) rows are never swept.
        DB::table('sku_replenishment')
            ->where('tenant_id', $tenantId)
            ->where('source', SkuReplenishment::SOURCE_COMPUTED)
            ->where('computed_at', '<', $now)
            ->delete();

        return $written;
    }

    /** (store|sku) keys whose row is tenant-supplied (ingested); the compute skips these. */
    private function ingestedKeys(int $tenantId): array
    {
        $keys = [];
        DB::table('sku_replenishment')
            ->where('tenant_id', $tenantId)
            ->where('source', SkuReplenishment::SOURCE_INGESTED)
            ->select(['sku', 'store_id'])
            ->cursor()
            ->each(function ($r) use (&$keys) {
                $keys[$r->store_id . '|' . trim((string) $r->sku)] = true;
            });

        return $keys;
    }

    private function flush(array $rows): int
    {
        DB::table('sku_replenishment')->upsert(
            $rows,
            ['tenant_id', 'sku', 'store_id'],
            ['supplier', 'segment', 'daily_rate', 'lead_time_days', 'safety_stock',
             'reorder_point', 'order_up_to', 'on_hand', 'suggested_order_qty',
             'unit_cost', 'order_value', 'service_level', 'source', 'computed_at', 'updated_at']
        );

        return count($rows);
    }

    /**
     * Observed lead time (order_date → received_date) and the dominant supplier
     * per SKU, plus a tenant-wide average as fallback. One grouped pass each.
     */
    private function leadTimes(int $tenantId): array
    {
        $leadBySku = [];
        $tenantAvg = null;

        $agg = DB::table('purchase_orders')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('received_date')
            ->whereNotNull('order_date')
            ->selectRaw("sku, AVG(received_date::date - order_date::date) AS lead")
            ->groupBy('sku')
            ->get();

        $sum = 0.0; $n = 0;
        foreach ($agg as $r) {
            $lead = (float) $r->lead;
            if ($lead <= 0) continue;
            $leadBySku[trim((string) $r->sku)] = $lead;
            $sum += $lead; $n++;
        }
        if ($n > 0) $tenantAvg = $sum / $n;

        // Dominant supplier per SKU (most POs), for the "order from" hint.
        $supplierBySku = [];
        DB::table('purchase_orders')
            ->where('tenant_id', $tenantId)
            ->selectRaw('sku, supplier, COUNT(*) AS c')
            ->groupBy('sku', 'supplier')
            ->orderBy('sku')->orderByDesc('c')
            ->get()
            ->each(function ($r) use (&$supplierBySku) {
                $sku = trim((string) $r->sku);
                if (! isset($supplierBySku[$sku])) {
                    $supplierBySku[$sku] = $r->supplier;   // first = highest count for this sku
                }
            });

        return [$leadBySku, $supplierBySku, $tenantAvg];
    }

    /** sku => unit cost (unit_cost ?? selling_price). */
    private function costs(int $tenantId): array
    {
        $map = [];
        DB::table('products')
            ->where('tenant_id', $tenantId)
            ->select(['sku', 'unit_cost', 'selling_price'])
            ->cursor()
            ->each(function ($p) use (&$map) {
                $map[trim((string) $p->sku)] = (float) ($p->unit_cost ?: $p->selling_price ?: 0);
            });

        return $map;
    }
}
