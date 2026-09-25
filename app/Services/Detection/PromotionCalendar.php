<?php

namespace App\Services\Detection;

use Illuminate\Support\Facades\DB;

/**
 * W10 — when a SKU was on promotion, and where.
 *
 * Two sources:
 *  1. the promotion calendar (the `promotions` import type): promotion × SKU,
 *     one store or every store, start and end dates;
 *  2. the sales lines themselves: a SKU sold under a promotion_ref in a store,
 *     when those lines are a real share (≥ 30%) of what it sold there on those
 *     days — a single coupon line is not a promotion.
 *
 * Detection asks it whether a demand swing is explained by a promotion: a
 * spike DURING one, or a drop measured against a promo-inflated baseline or
 * in the dip that follows one (customers stocked up).
 */
final class PromotionCalendar
{
    /** @var array<string,array<int,array{0:?int,1:string,2:string,3:string}>> sku → [store|null, from, to, ref] */
    private array $bySku = [];

    public static function load(int $tenantId, string $from, string $to): self
    {
        $cal = new self();
        foreach (DB::table('promotions')->where('tenant_id', $tenantId)
            ->where('ends_on', '>=', $from)->where('starts_on', '<=', $to)
            ->get(['sku', 'store_id', 'starts_on', 'ends_on', 'promotion_ref']) as $p) {
            $cal->add((string) $p->sku, $p->store_id !== null ? (int) $p->store_id : null,
                substr((string) $p->starts_on, 0, 10), substr((string) $p->ends_on, 0, 10), (string) $p->promotion_ref);
        }

        // Promotions seen on the sales lines (a real share of the SKU's units there).
        foreach (DB::select(
            "WITH p AS (
                SELECT promotion_ref, sku, store_id, MIN(date) AS f, MAX(date) AS t, SUM(quantity) AS pu
                  FROM sales_transactions
                 WHERE tenant_id = ? AND promotion_ref IS NOT NULL AND date >= ? AND date <= ?
                 GROUP BY promotion_ref, sku, store_id
             )
             SELECT p.promotion_ref, p.sku, p.store_id, p.f::text AS f, p.t::text AS t
               FROM p
               LEFT JOIN LATERAL (
                    SELECT SUM(d.units_sold) AS u FROM sales_daily d
                     WHERE d.tenant_id = ? AND d.sku = p.sku AND d.store_id IS NOT DISTINCT FROM p.store_id
                       AND d.date BETWEEN p.f AND p.t
               ) s ON true
              WHERE s.u IS NULL OR s.u <= 0 OR p.pu >= 0.3 * s.u",
            [$tenantId, $from, $to, $tenantId]
        ) as $p) {
            $cal->add((string) $p->sku, $p->store_id !== null ? (int) $p->store_id : null,
                substr($p->f, 0, 10), substr($p->t, 0, 10), (string) $p->promotion_ref);
        }

        return $cal;
    }

    public function add(string $sku, ?int $storeId, string $from, string $to, string $ref): void
    {
        $this->bySku[$sku][] = [$storeId, $from, $to, $ref];
    }

    public function isEmpty(): bool
    {
        return $this->bySku === [];
    }

    /**
     * The first promotion on this SKU (at this store, or chain-wide; any
     * store when $storeId is null) overlapping [from, to], or null.
     *
     * @return array{ref:string, from:string, to:string, store_id:?int}|null
     */
    public function overlapping(string $sku, ?int $storeId, string $from, string $to): ?array
    {
        foreach ($this->bySku[$sku] ?? [] as [$pStore, $pFrom, $pTo, $ref]) {
            if ($storeId !== null && $pStore !== null && $pStore !== $storeId) {
                continue;
            }
            if ($pFrom <= $to && $pTo >= $from) {
                return ['ref' => $ref, 'from' => $pFrom, 'to' => $pTo, 'store_id' => $pStore];
            }
        }

        return null;
    }
}
