<?php

namespace App\Services\Supply;

use App\Services\Fx\FxService;
use Illuminate\Support\Facades\DB;

/**
 * W13 — PO lines in selling units and the tenant's currency.
 *
 *   pack   a line ordered in cases (order unit case / carton / box / pack /
 *          outer / tray / crate) is multiplied by the product's units per
 *          case — quantities up, unit cost down. A line in eaches gets factor
 *          1. A case line whose product has no units per case waits (null)
 *          and is picked up when the product master supplies it.
 *   fx     a line in another currency has its unit cost converted at the rate
 *          in force on its order date (the original is kept).
 *
 * Idempotent: each line is converted once (pack_factor / fx_rate record it),
 * and re-importing a line resets those markers with the raw values.
 */
class PurchaseOrderNormalizer
{
    public const CASE_UNITS = ['cs', 'case', 'cases', 'ctn', 'carton', 'cartons', 'box', 'boxes', 'bx', 'pk', 'pack', 'packs', 'outer', 'outers', 'tray', 'trays', 'crate', 'crates', 'cse'];
    public const EACH_UNITS = ['ea', 'each', 'pc', 'pcs', 'piece', 'pieces', 'unit', 'units', 'u', 'ea.', 'nos', 'no', 'item', 'items'];

    public function __construct(private readonly FxService $fx) {}

    /** @return array{packed:int, eaches:int, waiting:int, converted:int} */
    public function normalize(int $tenantId): array
    {
        $cases = implode(',', array_map(fn ($u) => DB::getPdo()->quote($u), self::CASE_UNITS));
        $eaches = implode(',', array_map(fn ($u) => DB::getPdo()->quote($u), self::EACH_UNITS));

        $eachN = DB::update(
            "UPDATE purchase_orders SET pack_factor = 1
              WHERE tenant_id = ? AND pack_factor IS NULL AND (order_uom IS NULL OR lower(trim(order_uom)) IN ({$eaches}))",
            [$tenantId]
        );
        $packed = DB::update(
            "UPDATE purchase_orders po
                SET qty_ordered  = po.qty_ordered * p.units_per_case,
                    qty_received = po.qty_received * p.units_per_case,
                    open_qty     = po.open_qty * p.units_per_case,
                    unit_cost    = po.unit_cost / p.units_per_case,
                    pack_factor  = p.units_per_case
               FROM products p
              WHERE po.tenant_id = ? AND p.tenant_id = po.tenant_id AND p.sku = po.sku
                AND po.pack_factor IS NULL AND lower(trim(po.order_uom)) IN ({$cases})
                AND p.units_per_case > 0",
            [$tenantId]
        );
        $waiting = (int) DB::table('purchase_orders')->where('tenant_id', $tenantId)->whereNull('pack_factor')->count();

        $converted = 0;
        $base = $this->fx->base($tenantId);
        $foreign = DB::table('purchase_orders')->where('tenant_id', $tenantId)->whereNull('fx_rate')->whereNotNull('unit_cost')
            ->whereNotNull('currency')->whereRaw("upper(currency) <> ?", [$base])
            ->selectRaw('DISTINCT upper(currency) AS c')->pluck('c');
        foreach ($foreign as $cur) {
            // One rate per validity period, applied in SQL (no per-line lookups).
            $rate = "(SELECT f.rate FROM fx_rates f WHERE f.tenant_id = po.tenant_id AND f.currency = ?
                        AND f.valid_from <= po.order_date ORDER BY f.valid_from DESC LIMIT 1)";
            $converted += DB::update(
                "UPDATE purchase_orders po
                    SET unit_cost_original = po.unit_cost, unit_cost = po.unit_cost * {$rate}, fx_rate = {$rate}
                  WHERE po.tenant_id = ? AND po.fx_rate IS NULL AND upper(po.currency) = ? AND po.unit_cost IS NOT NULL
                    AND {$rate} IS NOT NULL",
                [$cur, $cur, $tenantId, $cur, $cur]
            );
        }

        return ['packed' => $packed, 'eaches' => $eachN, 'waiting' => $waiting, 'converted' => $converted];
    }
}
