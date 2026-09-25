<?php

namespace App\Services\DataQuality;

use App\Models\DqFinding;
use App\Models\Import;
use App\Models\ImportQuality;
use App\Models\Investigation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W9 (WP9.6) — data lineage for an investigation: which batches (files,
 * feeds) the rows behind it came from, what each batch's quality verdict
 * was, and whether any of them was loaded despite a warning. Canonical rows
 * carry their import_id, so this is a lookup, not a guess.
 */
class DataLineage
{
    private const SOURCES = [
        'sales'           => ['sales_transactions', 'date', 'Sales'],
        'inventory'       => ['inventory_levels', 'as_of_date', 'Stock'],
        'purchase_orders' => ['purchase_orders', 'order_date', 'Purchase orders'],
    ];

    /** @return array{window:array{0:string,1:string}, skus:array<int,string>, batches:array<int,array<string,mixed>>, caveats:array<int,string>} */
    public function forInvestigation(Investigation $inv, int $limit = 12): array
    {
        $anomalies = $inv->anomalies()->get(['sku', 'store_id', 'detected_at']);
        $skus = $anomalies->pluck('sku')->push($inv->primary_sku)->filter()->unique()->values()->take(25)->all();
        $stores = $anomalies->pluck('store_id')->push($inv->primary_store_id)->filter()->unique()->values()->all();
        $to = Carbon::parse($anomalies->max('detected_at') ?? $inv->opened_at ?? now())->endOfDay();
        $from = Carbon::parse($anomalies->min('detected_at') ?? $inv->opened_at ?? now())->subDays(35)->startOfDay();

        $empty = ['window' => [$from->toDateString(), $to->toDateString()], 'skus' => $skus, 'batches' => [], 'caveats' => []];
        if ($skus === []) {
            return $empty;
        }

        $per = [];
        foreach (self::SOURCES as $key => [$table, $dateCol, $label]) {
            $rows = DB::table($table)->where('tenant_id', $inv->tenant_id)->whereIn('sku', $skus)
                ->when($stores !== [], fn ($q) => $q->where(fn ($w) => $w->whereIn('store_id', $stores)->orWhereNull('store_id')))
                ->whereBetween($dateCol, [$from->toDateString(), $to->toDateString()])
                ->whereNotNull('import_id')
                ->groupBy('import_id')
                ->selectRaw("import_id, COUNT(*) AS n, MIN({$dateCol})::text AS first_day, MAX({$dateCol})::text AS last_day")
                ->get();
            foreach ($rows as $r) {
                $per[$r->import_id][$label] = ['rows' => (int) $r->n, 'from' => substr($r->first_day, 0, 10), 'to' => substr($r->last_day, 0, 10)];
            }
        }
        if ($per === []) {
            return $empty;
        }

        $imports = Import::whereIn('id', array_keys($per))->where('tenant_id', $inv->tenant_id)->orderByDesc('created_at')->limit($limit)->get();
        $quality = ImportQuality::whereIn('import_id', $imports->pluck('id'))->get()->keyBy('import_id');

        $batches = [];
        $caveats = [];
        foreach ($imports as $im) {
            $q = $quality[$im->id] ?? null;
            $flags = [];
            if ($q?->overridden_at) {
                $flags[] = 'loaded despite a RED verdict';
            }
            if ($q && $q->rows_quarantined > 0) {
                $flags[] = number_format($q->rows_quarantined) . ' row(s) quarantined';
            }
            foreach (array_keys($q?->reason_counts ?? []) as $code) {
                if (str_starts_with($code, 'feed_')) {
                    $flags[] = strtolower(Reasons::label($code));
                }
            }
            $batches[] = [
                'id'       => $im->id,
                'file'     => $im->original_filename,
                'source'   => $im->source ?: Import::SOURCE_UPLOAD,
                'feed'     => $im->feed_key,
                'loaded'   => $im->created_at,
                'status'   => $im->status,
                'state'    => $q?->state,
                'decision' => $q?->decision,
                'datasets' => $per[$im->id],
                'flags'    => $flags,
            ];
            if ($flags !== []) {
                $caveats[] = "Batch #{$im->id} ({$im->original_filename}): " . implode(', ', $flags) . '.';
            }
        }

        $open = DqFinding::where('tenant_id', $inv->tenant_id)->open()->whereIn('severity', [DqFinding::SEVERITY_CRITICAL, DqFinding::SEVERITY_WARNING])
            ->where(fn ($q) => $q->whereIn('subject_key', array_map(fn ($s) => 'store:' . $s, $stores))->orWhereIn('check', ['sales_day_collapse', 'future_dated']))
            ->get();
        foreach ($open as $f) {
            $caveats[] = $f->message;
        }

        return ['window' => [$from->toDateString(), $to->toDateString()], 'skus' => $skus, 'batches' => $batches, 'caveats' => $caveats];
    }
}
