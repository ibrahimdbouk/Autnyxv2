<?php

namespace App\Services\Fresh;

use App\Models\Anomaly;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W11 — the fresh & expiry picture for one tenant: stock about to expire,
 * what of it will not sell in time (the expiry_risk findings), and waste —
 * how much, why, where and in which categories — against the period before.
 */
class FreshExpiryService
{
    /** @return array<string,mixed> */
    public function summary(int $tenantId): array
    {
        $invAsOf = DB::table('inventory_current')->where('tenant_id', $tenantId)->max('as_of_date');
        $asOf = Carbon::parse($invAsOf ?: now())->startOfDay();

        // Batches on hand at each position's current snapshot, by days to expiry.
        $exp = DB::selectOne(
            "SELECT COALESCE(SUM(l.on_hand_qty * COALESCE(l.unit_cost, c.unit_cost, p.unit_cost, 0)) FILTER (WHERE l.expiry_date < ?::date), 0) AS expired,
                    COALESCE(SUM(l.on_hand_qty * COALESCE(l.unit_cost, c.unit_cost, p.unit_cost, 0)) FILTER (WHERE l.expiry_date >= ?::date AND l.expiry_date < ?::date + 7), 0) AS d7,
                    COALESCE(SUM(l.on_hand_qty * COALESCE(l.unit_cost, c.unit_cost, p.unit_cost, 0)) FILTER (WHERE l.expiry_date >= ?::date AND l.expiry_date < ?::date + 14), 0) AS d14,
                    COUNT(*) FILTER (WHERE l.expiry_date IS NOT NULL) AS batches
               FROM inventory_current c
               JOIN inventory_levels l ON l.tenant_id = c.tenant_id AND l.sku = c.sku
                AND l.store_id IS NOT DISTINCT FROM c.store_id AND l.as_of_date = c.as_of_date
               LEFT JOIN products p ON p.tenant_id = c.tenant_id AND p.sku = c.sku
              WHERE c.tenant_id = ? AND l.on_hand_qty > 0 AND l.expiry_date IS NOT NULL",
            array_merge(array_fill(0, 5, $asOf->toDateString()), [$tenantId])
        );

        $risk = Anomaly::where('tenant_id', $tenantId)->where('rule_type', 'expiry_risk')->active();
        $riskItems = (clone $risk)->get(['id', 'sku', 'store_id', 'severity', 'context', 'description'])
            ->sortByDesc(fn ($a) => (float) ($a->context['inventory_value'] ?? 0))->take(15)->values();

        $wasteLast = DB::table('waste_events')->where('tenant_id', $tenantId)->max('date');
        $waste = null;
        if ($wasteLast) {
            $to = Carbon::parse($wasteLast)->startOfDay()->addDay();      // exclusive
            $from = $to->copy()->subDays(28);
            $pFrom = $from->copy()->subDays(28);
            [$fromD, $toD, $pFromD] = [$from->toDateString(), $to->toDateString(), $pFrom->toDateString()];
            $val = 'COALESCE(e.value, e.quantity * COALESCE(p.unit_cost, 0))';
            $base = fn () => DB::table('waste_events as e')->leftJoin('products as p', fn ($j) => $j->on('p.tenant_id', '=', 'e.tenant_id')->on('p.sku', '=', 'e.sku'))
                ->where('e.tenant_id', $tenantId);

            $now = $base()->where('e.date', '>=', $fromD)->where('e.date', '<', $toD)->selectRaw("COALESCE(SUM(e.quantity), 0) AS q, COALESCE(SUM({$val}), 0) AS v")->first();
            $prev = $base()->where('e.date', '>=', $pFromD)->where('e.date', '<', $fromD)->selectRaw("COALESCE(SUM({$val}), 0) AS v")->first();
            $sold = (float) DB::table('sales_daily')->where('tenant_id', $tenantId)->where('date', '>=', $fromD)->where('date', '<', $toD)
                ->whereIn('sku', DB::table('waste_events')->select('sku')->where('tenant_id', $tenantId)->where('date', '>=', $fromD)->where('date', '<', $toD))
                ->sum('units_sold');

            $group = fn (string $expr, string $alias) => $base()->where('e.date', '>=', $fromD)->where('e.date', '<', $toD)
                ->selectRaw("{$expr} AS k, COALESCE(SUM(e.quantity), 0) AS q, COALESCE(SUM({$val}), 0) AS v")
                ->groupByRaw($expr)->orderByDesc('v')->limit(10)->get()
                ->map(fn ($r) => ['key' => $r->k, 'units' => (float) $r->q, 'value' => round((float) $r->v, 2)])->all();

            $byStore = $base()->leftJoin('stores as s', 's.id', '=', 'e.store_id')->where('e.date', '>=', $fromD)->where('e.date', '<', $toD)
                ->selectRaw("COALESCE(s.name, 'No store') AS k, e.store_id, COALESCE(SUM(e.quantity), 0) AS q, COALESCE(SUM({$val}), 0) AS v")
                ->groupBy('s.name', 'e.store_id')->orderByDesc('v')->limit(10)->get()
                ->map(fn ($r) => ['key' => $r->k, 'units' => (float) $r->q, 'value' => round((float) $r->v, 2)])->all();

            $waste = [
                'from'     => $from->toDateString(),
                'to'       => $to->copy()->subDay()->toDateString(),
                'units'    => (float) $now->q,
                'value'    => round((float) $now->v, 2),
                'prev'     => round((float) $prev->v, 2),
                'rate'     => (float) $now->q + $sold > 0 ? round(100 * (float) $now->q / ((float) $now->q + $sold), 1) : null,
                'by_reason'   => $group("COALESCE(e.reason, 'not given')", 'reason'),
                'by_category' => $group("COALESCE(p.department, p.category, 'Uncategorised')", 'category'),
                'by_store'    => $byStore,
                'findings'    => Anomaly::where('tenant_id', $tenantId)->where('rule_type', 'waste_rate')->active()->count(),
            ];
        }

        return [
            'as_of'         => $invAsOf ? $asOf->toDateString() : null,
            'has_expiry'    => (int) ($exp->batches ?? 0) > 0,
            'expired_value' => round((float) ($exp->expired ?? 0), 2),
            'expiring_7'    => round((float) ($exp->d7 ?? 0), 2),
            'expiring_14'   => round((float) ($exp->d14 ?? 0), 2),
            'at_risk_value' => round((float) (clone $risk)->get(['context'])->sum(fn ($a) => (float) ($a->context['inventory_value'] ?? 0)), 2),
            'at_risk_count' => (clone $risk)->count(),
            'risk_items'    => $riskItems,
            'waste'         => $waste,
        ];
    }
}
