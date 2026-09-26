<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W13 — when did a signal actually start?
 *
 * A cause has to come before its effect. The date a finding was first flagged
 * is not good enough to check that: rules run on different clocks and
 * thresholds, so a late PO flagged tonight may have been late for a week. This
 * reads the start of each signal from the data itself, as a range:
 *
 *   earliest  the signal cannot have started before this date (null = unknown)
 *   latest    the signal was certainly under way by this date
 *
 *   PO overdue / late         the date the PO was due (exact)
 *   receiving discrepancy     the date the short delivery was received (exact)
 *   sales drop / spike        the first day the daily sales moved to the new
 *                             level (daily data — exact)
 *   stock-out risk / safety   the start of the current run of stock snapshots
 *   stock / negative stock    in that state (between the previous snapshot
 *                             and the first one in the run)
 *   phantom inventory         the day after the last sale
 *   anything else             not known from data: under way by the day it
 *                             was first flagged
 */
class SignalOnset
{
    public const LOOKBACK_DAYS = 180;

    /** @var array<int, array{earliest:?string, latest:?string, basis:string, source:string}> */
    private array $memo = [];

    /** @return array{earliest:?string, latest:?string, basis:string, source:string} source: data|flagged */
    public function of(Anomaly $a): array
    {
        return $this->memo[$a->id] ??= $this->resolve($a);
    }

    private function resolve(Anomaly $a): array
    {
        $ctx = (array) ($a->context ?? []);
        $flagged = ($a->first_seen_at ?? $a->detected_at ?? $a->created_at)?->toDateString();
        $fallback = ['earliest' => null, 'latest' => $flagged, 'basis' => 'first flagged', 'source' => 'flagged'];

        try {
            $data = match ($a->rule_type) {
                'po_overdue', 'po_late_receipt' => $this->exact($ctx['expected_date'] ?? $this->poDate($a, $ctx, 'expected_date'), 'PO due date'),
                'receiving_discrepancy'         => $this->exact($ctx['received_date'] ?? $this->poDate($a, $ctx, 'received_date'), 'short delivery received'),
                'sales_drop', 'sales_spike'     => $this->salesShift($a, $ctx),
                'stockout_risk', 'safety_stock_breach' => $this->stockRun($a, 'low'),
                'negative_inventory'            => $this->stockRun($a, 'negative'),
                'phantom_inventory'             => $this->lastSale($a),
                default                         => null,
            };
        } catch (\Throwable) {
            $data = null;
        }
        if (! empty($ctx['onset_date'])) {
            $data = $this->exact($ctx['onset_date'], 'recorded start');
        }
        if ($data === null || $data['latest'] === null) {
            return $fallback;
        }
        // The data can only move the start earlier than the first flag, never later.
        if ($flagged !== null && $data['latest'] > $flagged) {
            $data['latest'] = $flagged;
            if ($data['earliest'] !== null && $data['earliest'] > $flagged) {
                $data['earliest'] = $flagged;
            }
        }

        return $data + ['source' => 'data'];
    }

    private function exact(mixed $date, string $basis): ?array
    {
        if (empty($date)) {
            return null;
        }
        $d = Carbon::parse($date)->toDateString();

        return ['earliest' => $d, 'latest' => $d, 'basis' => $basis];
    }

    private function poDate(Anomaly $a, array $ctx, string $column): ?string
    {
        if (empty($ctx['po_number'])) {
            return null;
        }
        $v = DB::table('purchase_orders')->where('tenant_id', $a->tenant_id)->where('po_number', $ctx['po_number'])
            ->when($a->sku, fn ($q) => $q->where('sku', $a->sku))->min($column);

        return $v ? (string) $v : null;
    }

    /**
     * The first day sales moved to the new level: the earliest day from which
     * the average to the end of the window sits past the midpoint between the
     * expected and the recent rate, that day included.
     */
    private function salesShift(Anomaly $a, array $ctx): ?array
    {
        $window = $ctx['window'] ?? null;
        if (! is_array($window) || count($window) < 2 || ! isset($ctx['expected_daily'], $ctx['recent_daily']) || ! $a->sku) {
            return null;
        }
        $to = Carbon::parse($window[1]);
        $days = max(1, (int) ($ctx['days'] ?? Carbon::parse($window[0])->diffInDays($to, absolute: true) + 1));
        $from = $to->copy()->subDays(2 * $days - 1);
        $mid = ((float) $ctx['expected_daily'] + (float) $ctx['recent_daily']) / 2;
        $drop = $a->rule_type === 'sales_drop';

        $rows = DB::table('sales_daily')->where('tenant_id', $a->tenant_id)->where('sku', $a->sku)
            ->when($a->store_id, fn ($q) => $q->where('store_id', $a->store_id))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('date')->selectRaw('date::date AS d, SUM(units_sold) AS u')->pluck('u', 'd');

        $series = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $series[$d->toDateString()] = (float) ($rows[$d->toDateString()] ?? 0);
        }
        $dates = array_keys($series);
        $n = count($dates);
        $suffix = 0.0;
        $onset = null;
        for ($i = $n - 1; $i >= 0; $i--) {
            $suffix += $series[$dates[$i]];
            $avg = $suffix / ($n - $i);
            $day = $series[$dates[$i]];
            if ($drop ? ($avg <= $mid && $day <= $mid) : ($avg >= $mid && $day >= $mid)) {
                $onset = $dates[$i];
            }
        }

        return $onset ? ['earliest' => $onset, 'latest' => $onset, 'basis' => $drop ? 'sales fell' : 'sales jumped'] : null;
    }

    /** The start of the current run of stock snapshots in the state (low / negative). */
    private function stockRun(Anomaly $a, string $state): ?array
    {
        if (! $a->sku) {
            return null;
        }
        $rows = DB::table('inventory_levels')->where('tenant_id', $a->tenant_id)->where('sku', $a->sku)
            ->when($a->store_id, fn ($q) => $q->where('store_id', $a->store_id))
            ->whereNotNull('as_of_date')->where('as_of_date', '>=', now()->subDays(self::LOOKBACK_DAYS)->toDateString())
            ->orderByDesc('as_of_date')->get(['store_id', 'as_of_date', 'on_hand_qty', 'reorder_point']);

        $best = null;
        foreach ($rows->groupBy('store_id') as $snaps) {
            $start = null;
            $before = null;
            foreach ($snaps as $s) {
                $qty = (float) $s->on_hand_qty;
                $in = $state === 'negative' ? $qty < 0 : $qty <= max(0.0, (float) ($s->reorder_point ?? 0));
                if (! $in) {
                    $before = Carbon::parse($s->as_of_date)->toDateString();
                    break;
                }
                $start = Carbon::parse($s->as_of_date)->toDateString();
            }
            if ($start === null) {
                continue; // this position is not in the state now
            }
            $earliest = $before ? Carbon::parse($before)->addDay()->toDateString() : null;
            // Chain-level signal: the first position to enter the state starts it.
            if ($best === null || $start < $best['latest']) {
                $best = ['earliest' => $earliest, 'latest' => $start];
            }
        }

        return $best ? $best + ['basis' => $state === 'negative' ? 'stock went negative' : 'stock ran low'] : null;
    }

    private function lastSale(Anomaly $a): ?array
    {
        if (! $a->sku) {
            return null;
        }
        $last = DB::table('sales_daily')->where('tenant_id', $a->tenant_id)->where('sku', $a->sku)
            ->when($a->store_id, fn ($q) => $q->where('store_id', $a->store_id))
            ->where('units_sold', '>', 0)->max('date');
        if (! $last) {
            return null;
        }
        $d = Carbon::parse($last)->addDay()->toDateString();

        return ['earliest' => $d, 'latest' => $d, 'basis' => 'last sale'];
    }
}
