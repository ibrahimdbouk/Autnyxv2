<?php

namespace App\Services\Counts;

use App\Models\Action;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\CycleCount;
use App\Models\Store;
use App\Models\User;
use App\Support\Detection\ValueModel;
use Illuminate\Support\Facades\DB;

/**
 * W11 — cycle-count lists.
 *
 * Every store gets a short list, ranked by the money behind the doubt, of the
 * positions whose stock figure the system does not trust: phantom inventory,
 * shrink, negative on hand. The store counts them (on screen or on the CSV);
 * each count records the variance, and a count on a live finding becomes a
 * completed "cycle count" action on its investigation — so the measurement
 * follows what happened next (sales returning once the shelf is refilled, or
 * stock value corrected).
 *
 * Lists are refreshed nightly after detection: new doubts are added, doubts
 * that have cleared are cancelled, and a position counted in the last
 * RECOUNT_DAYS is not listed again.
 */
class CycleCountService
{
    public const PER_STORE    = 25;
    public const RECOUNT_DAYS = 14;

    /** @return array{added:int, kept:int, cancelled:int} */
    public function generate(int $tenantId, int $perStore = self::PER_STORE): array
    {
        $rules = array_keys(CycleCount::REASON_RULES);

        // Live doubts on a store position, the most valuable per position first.
        $candidates = Anomaly::where('tenant_id', $tenantId)->active()
            ->whereIn('rule_type', $rules)->whereNotNull('store_id')->whereNotNull('sku')
            ->get(['id', 'rule_type', 'sku', 'store_id', 'product_id', 'investigation_id', 'context'])
            ->map(fn (Anomaly $a) => ['a' => $a, 'value' => abs(ValueModel::amount((array) $a->context))])
            ->sortByDesc('value')
            ->unique(fn ($c) => $c['a']->store_id . '|' . $c['a']->sku);

        $recent = CycleCount::where('tenant_id', $tenantId)->where('status', CycleCount::STATUS_COUNTED)
            ->where('counted_at', '>=', now()->subDays(self::RECOUNT_DAYS))
            ->get(['store_id', 'sku'])->mapWithKeys(fn ($c) => [$c->store_id . '|' . $c->sku => true]);

        $stock = [];
        foreach (DB::table('inventory_current')->where('tenant_id', $tenantId)
            ->whereIn('sku', $candidates->map(fn ($c) => $c['a']->sku)->unique()->values()->all() ?: ['__none__'])
            ->get(['store_id', 'sku', 'on_hand_qty', 'unit_cost']) as $r) {
            $k = $r->store_id . '|' . $r->sku;
            $stock[$k] = ['qty' => (float) ($stock[$k]['qty'] ?? 0) + (float) $r->on_hand_qty, 'cost' => $r->unit_cost ?? ($stock[$k]['cost'] ?? null)];
        }
        $costs = DB::table('products')->where('tenant_id', $tenantId)->pluck('unit_cost', 'sku');

        $wanted = [];
        foreach ($candidates->groupBy(fn ($c) => $c['a']->store_id) as $storeId => $list) {
            foreach ($list->reject(fn ($c) => isset($recent[$storeId . '|' . $c['a']->sku]))->take($perStore)->values() as $i => $c) {
                $wanted[$storeId . '|' . $c['a']->sku] = $c + ['rank' => $i + 1];
            }
        }

        $open = CycleCount::where('tenant_id', $tenantId)->where('status', CycleCount::STATUS_OPEN)->get()
            ->keyBy(fn ($c) => $c->store_id . '|' . $c->sku);

        $added = $kept = $cancelled = 0;
        DB::transaction(function () use ($tenantId, $wanted, $open, $stock, $costs, &$added, &$kept, &$cancelled) {
            foreach ($open as $k => $count) {
                if (! isset($wanted[$k])) {
                    $count->update(['status' => CycleCount::STATUS_CANCELLED, 'notes' => trim(($count->notes ?? '') . ' Taken off the list: the doubt cleared.')]);
                    $cancelled++;
                }
            }
            foreach ($wanted as $k => $c) {
                $a = $c['a'];
                $attrs = [
                    'anomaly_id'       => $a->id,
                    'investigation_id' => $a->investigation_id,
                    'reason'           => $a->rule_type,
                    'product_id'       => $a->product_id,
                    'system_qty'       => $stock[$k]['qty'] ?? null,
                    'unit_cost'        => $stock[$k]['cost'] ?? ($costs[$a->sku] ?? null),
                    'value_at_risk'    => round($c['value'], 2),
                    'rank'             => $c['rank'],
                ];
                if (isset($open[$k])) {
                    $open[$k]->update($attrs);
                    $kept++;
                } else {
                    CycleCount::create($attrs + ['tenant_id' => $tenantId, 'store_id' => $a->store_id, 'sku' => $a->sku, 'status' => CycleCount::STATUS_OPEN]);
                    $added++;
                }
            }
        });

        return compact('added', 'kept', 'cancelled');
    }

    /**
     * Record what a count found. The variance is against the stock figure at
     * the time of the count (the latest snapshot), not the one when listed.
     */
    public function record(CycleCount $count, float $countedQty, ?User $by = null, string $source = 'app', ?string $notes = null): CycleCount
    {
        if ($count->status !== CycleCount::STATUS_OPEN) {
            throw new \RuntimeException('This count is no longer open.');
        }
        if ($countedQty < 0) {
            throw new \InvalidArgumentException('A counted quantity cannot be negative.');
        }
        $pos = DB::table('inventory_current')->where('tenant_id', $count->tenant_id)
            ->where('store_id', $count->store_id)->where('sku', $count->sku);
        $system = (clone $pos)->exists() ? (float) $pos->sum('on_hand_qty') : (float) ($count->system_qty ?? 0);
        $variance = round($countedQty - $system, 4);
        $cost = (float) ($count->unit_cost ?? 0);

        DB::transaction(function () use ($count, $countedQty, $system, $variance, $cost, $by, $source, $notes) {
            $count->update([
                'status'         => CycleCount::STATUS_COUNTED,
                'system_qty'     => $system,
                'counted_qty'    => $countedQty,
                'variance_qty'   => $variance,
                'variance_value' => round($variance * $cost, 2),
                'count_source'   => $source,
                'counted_by'     => $by?->id,
                'counted_at'     => now(),
                'notes'          => $notes ?: $count->notes,
            ]);

            // A count on a live investigation is the action taken: measurement follows it.
            if ($count->investigation_id) {
                $store = Store::find($count->store_id)?->name ?? "store #{$count->store_id}";
                Action::create([
                    'investigation_id' => $count->investigation_id,
                    'anomaly_id'       => $count->anomaly_id,
                    'action_type'      => Action::TYPE_CYCLE_COUNT,
                    'title'            => "Cycle count: SKU {$count->sku} at {$store}",
                    'description'      => "Counted {$countedQty} against {$system} on the system (variance " . ($variance > 0 ? '+' : '') . "{$variance})."
                        . ' Correct the stock figure in the ERP if it differs.',
                    'status'           => Action::STATUS_COMPLETED,
                    'priority'         => Action::PRIORITY_MEDIUM,
                    'created_by'       => $by?->id,
                    'assigned_to'      => $by?->id,
                    'completed_at'     => now(),
                    'completion_notes' => $notes,
                ]);
            }

            AuditLog::create([
                'tenant_id'   => $count->tenant_id,
                'anomaly_id'  => $count->anomaly_id,
                'user_id'     => $by?->id,
                'event_type'  => 'cycle_count_recorded',
                'description' => "Cycle count SKU {$count->sku} (store {$count->store_id}): counted {$countedQty}, system {$system}, variance {$variance} via {$source}",
                'new_value'   => ['counted' => $countedQty, 'system' => $system, 'variance' => $variance],
            ]);
        });

        return $count->fresh();
    }

    /**
     * Counts from a CSV (the downloaded list, filled in): columns sku, store
     * (code or name, optional when a store is given) and counted_qty. Only
     * open counts are updated.
     *
     * @return array{recorded:int, skipped:array<int,string>}
     */
    public function importCsv(int $tenantId, string $path, ?int $storeId = null, ?User $by = null): array
    {
        $fh = fopen($path, 'r');
        if (! $fh) {
            throw new \RuntimeException('The file could not be read.');
        }
        $head = fgetcsv($fh) ?: [];
        $head = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $head);
        $col = fn (array $names) => collect($names)->map(fn ($n) => array_search($n, $head, true))->first(fn ($i) => $i !== false);
        $iSku = $col(['sku']);
        $iQty = $col(['counted_qty', 'counted', 'count', 'qty_counted']);
        $iStore = $col(['store', 'store_code', 'location']);
        if ($iSku === null || $iQty === null) {
            fclose($fh);
            throw new \InvalidArgumentException('The file needs a "sku" and a "counted_qty" column.');
        }

        $stores = Store::where('tenant_id', $tenantId)->get(['id', 'name', 'code']);
        $findStore = function (?string $v) use ($stores, $storeId) {
            if ($v === null || trim($v) === '') {
                return $storeId;
            }
            $v = mb_strtolower(trim($v));

            return $stores->first(fn ($s) => mb_strtolower((string) $s->code) === $v || mb_strtolower((string) $s->name) === $v)?->id;
        };

        $recorded = 0;
        $skipped = [];
        $line = 1;
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            $sku = trim((string) ($row[$iSku] ?? ''));
            $qty = trim((string) ($row[$iQty] ?? ''));
            if ($sku === '' || $qty === '') {
                continue; // not counted (yet)
            }
            if (! is_numeric(str_replace(',', '', $qty))) {
                $skipped[] = "Line {$line}: \"{$qty}\" is not a number.";
                continue;
            }
            $sid = $findStore($iStore !== null ? ($row[$iStore] ?? null) : null);
            $count = $sid ? CycleCount::where('tenant_id', $tenantId)->where('store_id', $sid)->where('sku', $sku)
                ->where('status', CycleCount::STATUS_OPEN)->first() : null;
            if (! $count) {
                $skipped[] = "Line {$line}: no open count for SKU {$sku}" . ($sid ? '' : ' (store not recognised)') . '.';
                continue;
            }
            $this->record($count, (float) str_replace(',', '', $qty), $by, 'upload');
            $recorded++;
        }
        fclose($fh);

        return compact('recorded', 'skipped');
    }
}
