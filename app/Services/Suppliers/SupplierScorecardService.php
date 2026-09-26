<?php

namespace App\Services\Suppliers;

use App\Models\Anomaly;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W11 — the supplier scorecard: every supplier's delivery performance over a
 * window of order dates, from the PO lines alone.
 *
 *   fill rate     units received ÷ units ordered, on lines that are due
 *                 (expected date reached) or received;
 *   on time       received lines that arrived by their expected date;
 *   lead time     order → receipt, days: this window vs the one before;
 *   cost change   unit cost on the same SKUs, last 30 days vs before;
 *   overdue       open lines past their expected date, and their value;
 *   impact        live stock-out findings on the supplier's SKUs, and the
 *                 lost revenue they carry.
 *
 * Score = 45 × fill + 45 × on-time + 10 × cost stability (0–100); grade
 * A ≥ 90, B ≥ 80, C ≥ 65, else D. A supplier with fewer than MIN_LINES due
 * lines is listed but not graded.
 */
class SupplierScorecardService
{
    public const MIN_LINES = 5;

    private const STOCKOUT_RULES = ['stockout_risk', 'safety_stock_breach'];

    /** A PO line's supplier: its supplier id, else the supplier master row of the same name, else the name. */
    private const JOIN_SQL = "LEFT JOIN suppliers s ON s.tenant_id = po.tenant_id AND (s.id = po.supplier_id OR (po.supplier_id IS NULL AND lower(s.name) = lower(po.supplier)))";
    private const KEY_SQL = "COALESCE(COALESCE(po.supplier_id, s.id)::text, 'name:' || lower(po.supplier))";

    /** @return array<int,array<string,mixed>> one row per supplier with orders in the window, worst first */
    public function scorecard(int $tenantId, int $days = 90): array
    {
        $clock = $this->clock($tenantId);
        $from = $clock->copy()->subDays($days)->toDateString();
        $prevFrom = $clock->copy()->subDays(2 * $days)->toDateString();
        $asOf = $clock->toDateString();
        $recentFrom = $clock->copy()->subDays(30)->toDateString();

        $rows = DB::select(
            "WITH po AS (
                SELECT COALESCE(po.supplier_id, s.id) AS sid, COALESCE(s.name, po.supplier) AS name, po.*
                  FROM purchase_orders po " . self::JOIN_SQL . "
                 WHERE po.tenant_id = ? AND po.order_date >= ? AND po.order_date <= ?
                   AND COALESCE(lower(po.status), '') NOT IN ('cancelled', 'canceled')
             )
             SELECT sid, MAX(name) AS name,
                    COUNT(*) FILTER (WHERE order_date >= ?) AS lines, COUNT(DISTINCT po_number) FILTER (WHERE order_date >= ?) AS pos,
                    SUM(qty_ordered * COALESCE(unit_cost, 0)) FILTER (WHERE order_date >= ?) AS ordered_value,
                    SUM(qty_ordered) FILTER (WHERE order_date >= ? AND (received_date IS NOT NULL OR expected_date <= ?)) AS due_qty,
                    SUM(LEAST(COALESCE(qty_received, 0), qty_ordered)) FILTER (WHERE order_date >= ? AND (received_date IS NOT NULL OR expected_date <= ?)) AS got_qty,
                    COUNT(*) FILTER (WHERE order_date >= ? AND (received_date IS NOT NULL OR expected_date <= ?)) AS due_lines,
                    COUNT(*) FILTER (WHERE order_date >= ? AND received_date IS NOT NULL AND expected_date IS NOT NULL) AS rec_lines,
                    COUNT(*) FILTER (WHERE order_date >= ? AND received_date IS NOT NULL AND expected_date IS NOT NULL AND received_date <= expected_date) AS ontime_lines,
                    AVG(received_date - order_date) FILTER (WHERE order_date >= ? AND received_date IS NOT NULL) AS lead,
                    AVG(received_date - order_date) FILTER (WHERE order_date < ? AND received_date IS NOT NULL) AS prev_lead,
                    COUNT(*) FILTER (WHERE received_date IS NULL AND expected_date < ? AND COALESCE(open_qty, qty_ordered - COALESCE(qty_received, 0)) > 0) AS overdue_lines,
                    SUM(COALESCE(open_qty, qty_ordered - COALESCE(qty_received, 0)) * COALESCE(unit_cost, 0))
                        FILTER (WHERE received_date IS NULL AND expected_date < ? AND COALESCE(open_qty, qty_ordered - COALESCE(qty_received, 0)) > 0) AS overdue_value
               FROM po
              GROUP BY sid",
            [$tenantId, $prevFrom, $asOf,
             $from, $from, $from, $from, $asOf, $from, $asOf, $from, $asOf, $from, $from, $from, $from, $asOf, $asOf]
        );
        $rows = array_values(array_filter($rows, fn ($r) => (int) $r->lines > 0 || (int) $r->overdue_lines > 0));

        $cost = $this->costChange($tenantId, $from, $recentFrom, $asOf);
        $impact = $this->stockoutImpact($tenantId, $from, $asOf);

        $out = [];
        foreach ($rows as $r) {
            $sid = $r->sid !== null ? (int) $r->sid : null;
            $key = $sid ?? ('name:' . mb_strtolower((string) $r->name));
            $fill = (float) $r->due_qty > 0 ? round(100 * (float) $r->got_qty / (float) $r->due_qty, 1) : null;
            $onTime = (int) $r->rec_lines > 0 ? round(100 * (int) $r->ontime_lines / (int) $r->rec_lines, 1) : null;
            $costPct = $cost[$key] ?? null;
            $graded = (int) $r->due_lines >= self::MIN_LINES && $fill !== null && $onTime !== null;
            $stability = $costPct === null ? 1.0 : max(0.0, 1 - max(0.0, $costPct) / 10);   // +10% or more = 0
            $score = $graded ? (int) round(45 * $fill / 100 + 45 * $onTime / 100 + 10 * $stability) : null;

            $out[] = [
                'supplier_id'    => $sid,
                'name'           => (string) ($r->name ?: 'Unknown supplier'),
                'lines'          => (int) $r->lines,
                'pos'            => (int) $r->pos,
                'ordered_value'  => round((float) $r->ordered_value, 2),
                'fill_rate'      => $fill,
                'on_time'        => $onTime,
                'lead_days'      => $r->lead !== null ? round((float) $r->lead, 1) : null,
                'prev_lead_days' => $r->prev_lead !== null ? round((float) $r->prev_lead, 1) : null,
                'cost_change'    => $costPct,
                'overdue_lines'  => (int) $r->overdue_lines,
                'overdue_value'  => round((float) $r->overdue_value, 2),
                'stockouts'      => $impact[$key]['count'] ?? 0,
                'lost_revenue'   => round($impact[$key]['value'] ?? 0, 2),
                'score'          => $score,
                'grade'          => $score === null ? null : ($score >= 90 ? 'A' : ($score >= 80 ? 'B' : ($score >= 65 ? 'C' : 'D'))),
            ];
        }
        usort($out, fn ($a, $b) => [$a['score'] === null, $a['score'] ?? 0, -$a['lost_revenue']] <=> [$b['score'] === null, $b['score'] ?? 0, -$b['lost_revenue']]);

        return $out;
    }

    /**
     * One supplier in detail: its worst-filled SKUs, open overdue lines and
     * biggest cost increases in the window.
     *
     * @return array<string,mixed>
     */
    public function detail(int $tenantId, int $supplierId, int $days = 90): array
    {
        $clock = $this->clock($tenantId);
        $from = $clock->copy()->subDays($days)->toDateString();
        $asOf = $clock->toDateString();
        $sup = DB::table('suppliers')->where('tenant_id', $tenantId)->where('id', $supplierId)->first(['id', 'name', 'lead_time_days']);
        if (! $sup) {
            return [];
        }
        $lines = fn () => DB::table('purchase_orders as po')->where('po.tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('po.supplier_id', $supplierId)->orWhere(fn ($w) => $w->whereNull('po.supplier_id')->whereRaw('lower(po.supplier) = lower(?)', [$sup->name])))
            ->whereRaw("COALESCE(lower(po.status), '') NOT IN ('cancelled', 'canceled')");

        $skus = $lines()->where('po.order_date', '>=', $from)->where('po.order_date', '<=', $asOf)
            ->where(fn ($q) => $q->whereNotNull('po.received_date')->orWhere('po.expected_date', '<=', $asOf))
            ->leftJoin('products as p', fn ($j) => $j->on('p.tenant_id', '=', 'po.tenant_id')->on('p.sku', '=', 'po.sku'))
            ->groupBy('po.sku')
            ->selectRaw('po.sku, MAX(p.name) AS name, SUM(po.qty_ordered) AS ordered, SUM(LEAST(COALESCE(po.qty_received, 0), po.qty_ordered)) AS received, COUNT(*) AS lines')
            ->havingRaw('SUM(po.qty_ordered) > 0')
            ->orderByRaw('SUM(LEAST(COALESCE(po.qty_received, 0), po.qty_ordered)) / SUM(po.qty_ordered)')
            ->limit(10)->get()
            ->map(fn ($r) => ['sku' => $r->sku, 'name' => $r->name, 'ordered' => (float) $r->ordered, 'received' => (float) $r->received,
                'fill_rate' => round(100 * (float) $r->received / (float) $r->ordered, 1), 'lines' => (int) $r->lines])->all();

        $overdue = $lines()->whereNull('po.received_date')->where('po.expected_date', '<', $asOf)
            ->whereRaw('COALESCE(po.open_qty, po.qty_ordered - COALESCE(po.qty_received, 0)) > 0')
            ->orderBy('po.expected_date')->limit(15)
            ->get(['po.po_number', 'po.sku', 'po.expected_date', 'po.qty_ordered', 'po.qty_received', 'po.unit_cost'])
            ->map(fn ($r) => ['po' => $r->po_number, 'sku' => $r->sku, 'expected' => (string) $r->expected_date,
                'days_late' => (int) Carbon::parse($r->expected_date)->diffInDays($clock, false),
                'open_qty' => (float) $r->qty_ordered - (float) $r->qty_received])->all();

        return ['id' => (int) $sup->id, 'name' => $sup->name, 'contracted_lead' => $sup->lead_time_days, 'skus' => $skus, 'overdue' => $overdue];
    }

    /** The PO clock: the newest order or receipt date, capped at today. */
    private function clock(int $tenantId): Carbon
    {
        $d = DB::table('purchase_orders')->where('tenant_id', $tenantId)->selectRaw('GREATEST(MAX(order_date), MAX(received_date)) AS d')->value('d');
        $today = Carbon::today();
        if (! $d) {
            return $today;
        }
        $c = Carbon::parse($d)->startOfDay();

        return $c->lt($today) ? $c : $today;
    }

    /** @return array<int|string,float> supplier key => % change in unit cost on the same SKUs (last 30 days vs before) */
    private function costChange(int $tenantId, string $from, string $recentFrom, string $asOf): array
    {
        $rows = DB::select(
            "WITH c AS (
                SELECT " . self::KEY_SQL . " AS k, po.sku,
                       SUM(po.unit_cost * po.qty_ordered) FILTER (WHERE po.order_date >= ?) / NULLIF(SUM(po.qty_ordered) FILTER (WHERE po.order_date >= ?), 0) AS recent,
                       SUM(po.unit_cost * po.qty_ordered) FILTER (WHERE po.order_date < ?) / NULLIF(SUM(po.qty_ordered) FILTER (WHERE po.order_date < ?), 0) AS before,
                       SUM(po.qty_ordered) FILTER (WHERE po.order_date >= ?) AS q
                  FROM purchase_orders po " . self::JOIN_SQL . "
                 WHERE po.tenant_id = ? AND po.order_date >= ? AND po.order_date <= ? AND po.unit_cost > 0
                 GROUP BY 1, 2
             )
             SELECT k, SUM((recent / before - 1) * 100 * q) / NULLIF(SUM(q), 0) AS pct
               FROM c WHERE recent IS NOT NULL AND before IS NOT NULL AND before > 0
              GROUP BY k",
            [$recentFrom, $recentFrom, $recentFrom, $recentFrom, $recentFrom, $tenantId, $from, $asOf]
        );
        $out = [];
        foreach ($rows as $r) {
            $key = str_starts_with((string) $r->k, 'name:') ? (string) $r->k : (int) $r->k;
            $out[$key] = $r->pct !== null ? round((float) $r->pct, 1) : null;
        }

        return $out;
    }

    /** @return array<int|string,array{count:int,value:float}> supplier key => live stock-out findings on its SKUs */
    private function stockoutImpact(int $tenantId, string $from, string $asOf): array
    {
        $skuSupplier = [];
        foreach (DB::select("SELECT po.sku, " . self::KEY_SQL . " AS k, COUNT(*) AS n FROM purchase_orders po " . self::JOIN_SQL . "
                WHERE po.tenant_id = ? AND po.order_date >= ? AND po.order_date <= ? GROUP BY 1, 2 ORDER BY 3 DESC",
                [$tenantId, $from, $asOf]) as $r) {
            $skuSupplier[$r->sku] ??= str_starts_with((string) $r->k, 'name:') ? (string) $r->k : (int) $r->k;   // main supplier per SKU
        }
        $out = [];
        if ($skuSupplier === []) {
            return $out;
        }
        foreach (Anomaly::where('tenant_id', $tenantId)->active()->whereIn('rule_type', self::STOCKOUT_RULES)
            ->whereIn('sku', array_keys($skuSupplier))->get(['sku', 'context']) as $a) {
            $k = $skuSupplier[$a->sku] ?? null;
            if ($k === null) {
                continue;
            }
            $out[$k]['count'] = ($out[$k]['count'] ?? 0) + 1;
            $out[$k]['value'] = ($out[$k]['value'] ?? 0) + (float) ($a->context['revenue_impact'] ?? 0);
        }

        return $out;
    }
}
