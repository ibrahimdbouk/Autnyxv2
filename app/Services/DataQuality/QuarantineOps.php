<?php

namespace App\Services\DataQuality;

use App\Models\AuditLog;
use App\Models\QuarantinedRow;
use App\Models\User;
use App\Services\Import\ImportProcessorService;

/**
 * W9 (WP9.4) — quarantine operations at the scale of a reason, not a page:
 * re-screen or discard every open row with one reason (optionally one data
 * type), and accept alias suggestions (then re-screen the rows they unblock).
 */
class QuarantineOps
{
    public const MAX_PER_RUN = 5000;

    public function __construct(private ImportProcessorService $processor, private AliasSuggester $aliases)
    {
    }

    /** @return array<string,int> reason → open rows */
    public function openByReason(int $tenantId): array
    {
        return QuarantinedRow::where('tenant_id', $tenantId)->where('status', QuarantinedRow::STATUS_OPEN)
            ->selectRaw('reason_code, COUNT(*) AS n')->groupBy('reason_code')->orderByDesc('n')
            ->pluck('n', 'reason_code')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * @param  'rescreen'|'skip'  $action
     * @return array{done:int, promoted:int, still:int, remaining:int}
     */
    public function applyToReason(int $tenantId, string $reason, string $action, ?string $dataType, User $by): array
    {
        $query = fn () => QuarantinedRow::where('tenant_id', $tenantId)->where('status', QuarantinedRow::STATUS_OPEN)
            ->where('reason_code', $reason)->when($dataType, fn ($q) => $q->where('data_type', $dataType));

        $done = $promoted = $still = 0;
        $ids = $query()->orderBy('id')->limit(self::MAX_PER_RUN)->pluck('id');
        foreach ($ids->chunk(500) as $slice) {
            $rows = QuarantinedRow::whereIn('id', $slice->values())->orderBy('id')->get();
            if ($action === 'skip') {
                QuarantinedRow::whereIn('id', $rows->pluck('id'))->update(['status' => QuarantinedRow::STATUS_SKIPPED, 'updated_at' => now()]);
            } else {
                $r = $this->processor->reprocessQuarantined($rows, false, $by);
                $promoted += $r['promoted'];
                $still += $r['still'];
            }
            $done += $rows->count();
        }

        try {
            AuditLog::create([
                'tenant_id'   => $tenantId,
                'user_id'     => $by->id,
                'event_type'  => 'quarantine_bulk_' . $action,
                'description' => ucfirst($action) . " {$done} open quarantined row(s) with reason " . Reasons::label($reason) . ($dataType ? " ({$dataType})" : '') . '.',
            ]);
        } catch (\Throwable) {
            // best-effort
        }

        return ['done' => $done, 'promoted' => $promoted, 'still' => $still, 'remaining' => $query()->count()];
    }

    /**
     * Accept alias suggestions, then re-screen the open orphan rows they cover.
     *
     * @param  array<int,array{alias:string, canonical:string}>  $accepted
     * @return array{aliases:int, promoted:int}
     */
    public function acceptAliases(int $tenantId, array $accepted, User $by): array
    {
        $skus = [];
        foreach ($accepted as $a) {
            $this->aliases->accept($tenantId, $a['alias'], $a['canonical']);
            $skus[] = $a['alias'];
        }
        $promoted = 0;
        if ($skus !== []) {
            QuarantinedRow::where('tenant_id', $tenantId)->where('status', QuarantinedRow::STATUS_OPEN)
                ->where('reason_code', Reasons::ORPHAN_REFERENCE)
                ->whereIn(\Illuminate\Support\Facades\DB::raw("cleansed_data::jsonb->>'sku'"), $skus)
                ->orderBy('id')->chunkById(500, function ($rows) use ($by, &$promoted) {
                    $promoted += $this->processor->reprocessQuarantined($rows, false, $by)['promoted'];
                });
        }

        return ['aliases' => count($skus), 'promoted' => $promoted];
    }
}
