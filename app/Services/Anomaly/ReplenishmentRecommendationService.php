<?php

namespace App\Services\Anomaly;

use App\Models\Action;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\SkuReplenishment;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * B4 slice 2 — prescriptive replenishment recommendations, and B5 — adopting one
 * as a tracked action.
 *
 * IMPORTANT (product principle): Autnyx RECOMMENDS, it never EXECUTES. Nothing
 * here writes to an ERP or transmits an order/transfer anywhere. It computes
 * options a human reads, and — when adopted — records an internal Action that
 * tracks the human's decision and its outcome. The human does the actual work
 * in their own system.
 *
 * For a stockout (or safety-stock breach) at a (store, SKU), it produces up to
 * two ranked options:
 *   • Transfer — move surplus from another store that has more than it needs.
 *   • Purchase order — order the shortfall from the SKU's usual supplier.
 * Quantities come from the derived order-up-to level (B4 slice 1).
 */
class ReplenishmentRecommendationService
{
    private const APPLICABLE_RULES = ['stockout_risk', 'safety_stock_breach'];

    /** Recommendation options for a single stockout-family anomaly (may be empty). */
    public function forAnomaly(Anomaly $a): array
    {
        if (! in_array($a->rule_type, self::APPLICABLE_RULES, true)) return [];
        if ($a->sku === null || $a->store_id === null) return [];

        $tenantId  = $a->tenant_id;
        $sku       = trim($a->sku);
        $needStore = (int) $a->store_id;

        $rep     = SkuReplenishment::where('tenant_id', $tenantId)->where('sku', $sku)->get()->keyBy('store_id');
        $needRep = $rep->get($needStore);
        if ($needRep === null) return []; // no derived target → nothing to size against

        // Live on-hand per store for this SKU (freshest snapshot each).
        $onHand = [];
        DB::table('inventory_levels')
            ->where('tenant_id', $tenantId)->where('sku', $sku)->whereNotNull('store_id')
            ->orderByRaw('store_id, as_of_date DESC NULLS LAST')
            ->distinct(['store_id'])
            ->get(['store_id', 'on_hand_qty'])
            ->each(function ($r) use (&$onHand) { $onHand[(int) $r->store_id] = (float) $r->on_hand_qty; });

        $curOnHand = $onHand[$needStore] ?? (float) ($needRep->on_hand ?? 0);
        $need      = (float) $needRep->order_up_to - $curOnHand;
        if ($need < 1) return []; // already at/above target
        $need = ceil($need);

        $storeNames = Store::where('tenant_id', $tenantId)->pluck('name', 'id');
        $needName   = $storeNames[$needStore] ?? "Store #{$needStore}";
        $unitCost   = (float) ($needRep->unit_cost ?? 0);
        $priority   = $this->priority($a->severity);

        $out = [];

        // ── Transfer option: the store with the most genuine surplus ──────────
        $bestDonor = null; $bestSurplus = 0.0;
        foreach ($onHand as $sid => $oh) {
            if ($sid === $needStore) continue;
            $donorRep    = $rep->get($sid);
            $donorTarget = (float) ($donorRep->order_up_to ?? $donorRep->reorder_point ?? 0);
            $surplus     = $oh - $donorTarget; // keep the donor at its own target
            if ($surplus > $bestSurplus) { $bestSurplus = $surplus; $bestDonor = $sid; }
        }
        if ($bestDonor !== null && $bestSurplus >= 1) {
            $qty = min($need, floor($bestSurplus));
            if ($qty >= 1) {
                $donorName = $storeNames[$bestDonor] ?? "Store #{$bestDonor}";
                $out[] = [
                    'anomaly_id'    => $a->id,
                    'kind'          => 'transfer',
                    'action_type'   => Action::TYPE_TRANSFER,
                    'qty'           => (float) $qty,
                    'value'         => round($qty * $unitCost, 2),
                    'from_store_id' => $bestDonor,
                    'to_store_id'   => $needStore,
                    'priority'      => $priority,
                    'label'         => 'Transfer ' . (int) $qty . " × {$sku}: {$donorName} → {$needName}",
                    'title'         => 'Transfer ' . (int) $qty . " units of {$sku} from {$donorName}",
                    'description'   => 'Recommended transfer of ' . (int) $qty . " units of {$sku} from {$donorName} "
                        . '(surplus ~' . (int) $bestSurplus . ") to {$needName} to cover the stockout. "
                        . 'Autnyx recommends only — carry out the transfer in your own ERP/WMS; this action tracks the decision and its outcome.',
                ];
            }
        }

        // ── Purchase-order option: order the shortfall from the usual supplier ─
        $supplier = $needRep->supplier;
        $lead     = (float) ($needRep->lead_time_days ?? 0);
        $out[] = [
            'anomaly_id'  => $a->id,
            'kind'        => 'purchase_order',
            'action_type' => Action::TYPE_REORDER,
            'qty'         => (float) $need,
            'value'       => round($need * $unitCost, 2),
            'supplier'    => $supplier,
            'priority'    => $priority,
            'label'       => 'Order ' . (int) $need . " × {$sku}" . ($supplier ? " from {$supplier}" : '') . ' (PO)',
            'title'       => 'Order ' . (int) $need . " units of {$sku}" . ($supplier ? " from {$supplier}" : ''),
            'description' => 'Recommended purchase order for ' . (int) $need . " units of {$sku}"
                . ($supplier ? " from {$supplier}" : '')
                . ($lead > 0 ? ' (typical lead ~' . round($lead) . ' days)' : '')
                . " to reach the target stock level at {$needName}. "
                . 'Autnyx recommends only — raise the PO in your own ERP; this action tracks the decision and its outcome.',
        ];

        return $out;
    }

    /** Recommendation options across every stockout-family anomaly in an investigation. */
    public function forInvestigation(Investigation $inv): array
    {
        $out = [];
        foreach ($inv->anomalies()->whereIn('rule_type', self::APPLICABLE_RULES)->get() as $anomaly) {
            foreach ($this->forAnomaly($anomaly) as $rec) {
                $out[] = $rec;
            }
        }

        return $out;
    }

    /**
     * B5: adopt a recommendation as a tracked Action on its investigation. This is
     * a record of a decision — Autnyx does not execute it.
     */
    public function adopt(Investigation $inv, array $rec, ?int $userId): Action
    {
        return $inv->actions()->create([
            'anomaly_id'  => $rec['anomaly_id'] ?? null,
            'action_type' => $rec['action_type'] ?? Action::TYPE_OTHER,
            'title'       => $rec['title'] ?? 'Recommended action',
            'description' => $rec['description'] ?? null,
            'status'      => Action::STATUS_UNASSIGNED,
            'priority'    => $rec['priority'] ?? Action::PRIORITY_MEDIUM,
            'created_by'  => $userId,
        ]);
    }

    private function priority(?string $severity): string
    {
        return match ($severity) {
            Anomaly::SEVERITY_HIGH   => Action::PRIORITY_HIGH,
            Anomaly::SEVERITY_MEDIUM => Action::PRIORITY_MEDIUM,
            default                  => Action::PRIORITY_LOW,
        };
    }
}
