<?php

namespace App\Services\DataQuality;

use App\Models\ContractViolation;
use App\Models\DataContract;
use App\Models\Import;
use App\Models\ImportQuality;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * W9 (WP9.2) — the feed registry and its service levels.
 *
 * Every batch belongs to a feed (imports.feed_key). Each feed has a
 * DataContract row that learns what the feed normally delivers from its own
 * history: how often (cadence), how many rows (a band around the median) and
 * which columns (a header signature). A new batch is checked against that
 * before it is learned from, so a short, bloated or re-shaped file is flagged
 * the moment it lands, and an automated feed that stops arriving is flagged
 * by the hourly `feeds:check`.
 *
 * Explicit contract values set by a person (required_columns, min_rows,
 * freshness_sla_hours) always apply, learned history or not.
 *
 * Best-effort by contract: a monitoring failure never breaks ingestion.
 */
class FeedMonitor
{
    public const MIN_HISTORY = 3;

    /** Row counts of this many recent batches define the band and cadence. */
    private const HISTORY = 20;

    /** Statuses of batches that count as a delivery. */
    private const DELIVERED = [Import::STATUS_COMPLETED, Import::STATUS_COMPLETED_WITH_ERRORS, Import::STATUS_HELD];

    public function __construct(private ReadinessAlerter $alerter)
    {
    }

    public static function minHistory(): int
    {
        return max(1, (int) config('data_quality.feeds.min_history', self::MIN_HISTORY));
    }

    /** The feed key of an import (older imports without one: an upload of its type). */
    public static function feedKeyOf(Import $import): string
    {
        return $import->feed_key ?: Import::feedKeyFor($import->source ?: Import::SOURCE_UPLOAD, (string) $import->data_type, $import->source_ref);
    }

    /** The source column headers of an import, in file order. @return array<int,string> */
    public static function headersOf(Import $import): array
    {
        return $import->columnMaps()->orderBy('id')->pluck('source_header')->map(fn ($h) => (string) $h)->all();
    }

    public static function signature(array $headers): string
    {
        $norm = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $headers);
        sort($norm);

        return sha1(implode("\x1f", $norm));
    }

    public function contractFor(Import $import): DataContract
    {
        $key = self::feedKeyOf($import);

        return DataContract::firstOrNew(['tenant_id' => $import->tenant_id, 'feed_key' => $key], [
            'data_type'    => $import->data_type,
            'source'       => $import->source ?: Import::SOURCE_UPLOAD,
            'learned'      => true,
            'active'       => true,
            'batches_seen' => 0,
            'status'       => DataContract::STATUS_OK,
        ]);
    }

    /**
     * Check a batch against what its feed normally delivers. Pure: records nothing.
     *
     * @return array<int,array{kind:string, code:string, severity:string, detail:string}>
     */
    public function check(DataContract $c, array $headers, int $rows): array
    {
        $out = [];
        $established = $c->exists && $c->batches_seen >= self::minHistory();

        // Required columns — explicit, always enforced.
        $present = array_map(fn ($h) => mb_strtolower(trim($h)), $headers);
        $missing = array_values(array_filter($c->required_columns ?? [], fn ($r) => ! in_array(mb_strtolower(trim((string) $r)), $present, true)));
        if ($missing !== []) {
            $out[] = ['kind' => ContractViolation::KIND_MISSING_COLUMNS, 'code' => Reasons::FEED_MISSING_COLUMNS, 'severity' => 'critical',
                'detail' => 'Missing required columns: ' . implode(', ', $missing) . '.'];
        }

        // Header shape — learned.
        if ($established && $c->header_signature && $headers !== [] && self::signature($headers) !== $c->header_signature) {
            $before = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $c->header_columns ?? []);
            $added   = array_values(array_diff($present, $before));
            $removed = array_values(array_diff($before, $present));
            $out[] = ['kind' => ContractViolation::KIND_SCHEMA_DRIFT, 'code' => Reasons::FEED_SCHEMA_DRIFT, 'severity' => 'warning',
                'detail' => 'Columns changed since the last batch'
                    . ($removed ? ' — gone: ' . implode(', ', array_slice($removed, 0, 8)) : '')
                    . ($added ? ' — new: ' . implode(', ', array_slice($added, 0, 8)) : '') . '.'];
        }

        // Volume.
        if ($rows === 0) {
            $out[] = ['kind' => ContractViolation::KIND_EMPTY, 'code' => Reasons::FEED_VOLUME_LOW, 'severity' => 'critical', 'detail' => 'The batch has no rows.'];
        } elseif ($c->min_rows !== null && $rows < $c->min_rows) {
            $out[] = ['kind' => ContractViolation::KIND_BELOW_MIN_ROWS, 'code' => Reasons::FEED_VOLUME_LOW, 'severity' => 'warning',
                'detail' => "{$rows} rows, below the contract minimum of {$c->min_rows}."];
        } elseif ($established && $c->rows_median) {
            if ($this->isPartial($c, $rows)) {
                $out[] = ['kind' => ContractViolation::KIND_VOLUME_LOW, 'code' => Reasons::FEED_PARTIAL, 'severity' => 'critical',
                    'detail' => "{$rows} rows — a fraction of the usual " . number_format($c->rows_median) . '. Partial delivery suspected.'];
            } elseif ($c->rows_low !== null && $rows < $c->rows_low) {
                $out[] = ['kind' => ContractViolation::KIND_VOLUME_LOW, 'code' => Reasons::FEED_VOLUME_LOW, 'severity' => 'warning',
                    'detail' => "{$rows} rows; this feed usually sends " . number_format($c->rows_low) . '–' . number_format($c->rows_high) . ' (median ' . number_format($c->rows_median) . ').'];
            } elseif ($c->rows_high !== null && $rows > $c->rows_high) {
                $out[] = ['kind' => ContractViolation::KIND_VOLUME_HIGH, 'code' => Reasons::FEED_VOLUME_HIGH, 'severity' => 'warning',
                    'detail' => "{$rows} rows; this feed usually sends " . number_format($c->rows_low) . '–' . number_format($c->rows_high) . ' (median ' . number_format($c->rows_median) . '). A resend or a wider date range?'];
            }
        }

        return $out;
    }

    /** A sales / inventory batch from an automated feed far below its usual size. */
    public function isPartial(DataContract $c, int $rows): bool
    {
        $ratio = (float) config('data_quality.feeds.partial_hold_ratio', 0.25);

        return $ratio > 0
            && $c->isAutomated()
            && in_array($c->data_type, [Import::TYPE_SALES, Import::TYPE_INVENTORY], true)
            && $c->batches_seen >= self::minHistory()
            && $c->rows_median > 0
            && $rows > 0
            && $rows < $c->rows_median * $ratio;
    }

    /**
     * Before anything is written (end of screening): should this batch be held
     * as a partial delivery? Records the violation and alerts when it is.
     */
    public function holdAsPartial(Import $import): ?string
    {
        try {
            $c = $this->contractFor($import);
            $rows = (int) $import->total_rows;
            if (! $c->exists || ! $this->isPartial($c, $rows)) {
                return null;
            }
            $detail = "{$rows} rows — a fraction of the usual " . number_format($c->rows_median) . '. Partial delivery suspected.';
            $this->violation($c, $import, ContractViolation::KIND_VOLUME_LOW, $detail);
            $this->markOnBatch($import, [Reasons::FEED_PARTIAL]);
            $this->alerter->alert($import->tenant_id, 'Data feed held — ' . $c->label(),
                "Batch #{$import->id} ({$import->original_filename}) was held before loading: {$detail} Nothing was loaded, so detection won't read it as a sales or stock collapse. Promote it from Imports if it is complete.",
                $c->owner_email);

            return 'Held — partial delivery suspected: ' . $detail . ' An admin can promote it anyway.';
        } catch (\Throwable $e) {
            Log::warning('[feeds] partial check failed', ['import' => $import->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * After a batch finished (loaded or held): check it, record and alert any
     * breach, then learn from it. Returns the breaches found.
     *
     * @return array<int,array{kind:string, code:string, severity:string, detail:string}>
     */
    public function recordBatch(Import $import): array
    {
        try {
            $import->refresh();
            $c       = $this->contractFor($import);
            $headers = self::headersOf($import);
            $rows    = (int) $import->total_rows;

            // A partial delivery was already recorded when it was held.
            $issues = array_values(array_filter($this->check($c, $headers, $rows),
                fn ($i) => ! ($i['code'] === Reasons::FEED_PARTIAL && $import->status === Import::STATUS_HELD)));

            if ($c->exists) {
                $this->resolveOpen($c, [ContractViolation::KIND_LATE, ContractViolation::KIND_STALE]);   // it arrived
            }
            $this->learn($c, $import, $headers, $rows);

            foreach ($issues as $i) {
                $this->violation($c, $import, $i['kind'], $i['detail']);
            }
            if ($issues === []) {
                $this->resolveOpen($c, [ContractViolation::KIND_VOLUME_LOW, ContractViolation::KIND_VOLUME_HIGH, ContractViolation::KIND_EMPTY,
                    ContractViolation::KIND_BELOW_MIN_ROWS, ContractViolation::KIND_SCHEMA_DRIFT, ContractViolation::KIND_MISSING_COLUMNS]);
            }
            $this->markOnBatch($import, array_column($issues, 'code'));

            $c->status        = $issues === [] ? DataContract::STATUS_OK : DataContract::STATUS_WARNING;
            $c->status_detail = $issues === [] ? null : \Illuminate\Support\Str::limit(implode(' ', array_column($issues, 'detail')), 250);
            $c->status_at     = now();
            $c->save();

            if ($issues !== []) {
                $this->alerter->alert($import->tenant_id, 'Data feed check — ' . $c->label(),
                    "Batch #{$import->id} ({$import->original_filename}): " . implode(' ', array_column($issues, 'detail')),
                    $c->owner_email, collect($issues)->contains('severity', 'critical') ? 'danger' : 'warning');
            }

            return $issues;
        } catch (\Throwable $e) {
            Log::warning('[feeds] batch record failed', ['import' => $import->id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /** Learn cadence, row band and header shape from this feed's recent batches. */
    private function learn(DataContract $c, Import $import, array $headers, int $rows): void
    {
        $history = Import::query()
            ->where('tenant_id', $import->tenant_id)
            ->where('feed_key', self::feedKeyOf($import))
            ->whereIn('status', self::DELIVERED)
            ->where('id', '<=', $import->id)
            ->orderByDesc('id')
            ->limit(self::HISTORY)
            ->get(['id', 'total_rows', 'created_at', 'status']);
        if (! $history->contains('id', $import->id)) {
            $history->prepend((object) ['id' => $import->id, 'total_rows' => $rows, 'created_at' => $import->created_at ?? now(), 'status' => $import->status]);
        }

        // Held batches (partial, broken) count as deliveries, not as normal volume.
        $counts = $history->where('status', '!=', Import::STATUS_HELD)->pluck('total_rows')->map(fn ($n) => (int) $n)->filter(fn ($n) => $n > 0)->sort()->values();
        if ($counts->isNotEmpty()) {
            $median = (int) round($this->median($counts->all()));
            $c->rows_median = $median;
            $c->rows_low    = (int) floor($median * (float) config('data_quality.feeds.volume_low_ratio', 0.4));
            $c->rows_high   = (int) ceil($median * (float) config('data_quality.feeds.volume_high_ratio', 2.5));
        }

        $times = $history->pluck('created_at')->map(fn ($t) => Carbon::parse($t)->getTimestamp())->sort()->values()->all();
        $gaps = [];
        for ($i = 1; $i < count($times); $i++) {
            $gaps[] = ($times[$i] - $times[$i - 1]) / 3600;
        }
        $gaps = array_values(array_filter($gaps, fn ($g) => $g >= 0.25));   // re-sends minutes apart aren't a cadence
        if ($gaps !== []) {
            $c->expected_every_hours = max(1, (int) ceil($this->median($gaps)));
        }

        if ($headers !== []) {
            $c->header_signature = self::signature($headers);
            $c->header_columns   = array_values($headers);
        }
        $c->data_type    ??= $import->data_type;
        $c->source       ??= $import->source ?: Import::SOURCE_UPLOAD;
        if ((int) $c->last_import_id !== (int) $import->id) {   // a held batch promoted later is still one batch
            $c->batches_seen = (int) $c->batches_seen + 1;
        }
        $c->last_batch_at  = $import->created_at ?? now();
        $c->last_import_id = $import->id;
        $c->last_rows      = $rows;
        $c->save();
    }

    /**
     * Hourly: automated feeds (or any feed with an explicit freshness SLA)
     * whose next batch is overdue. Alerts once per late spell.
     *
     * @return array<int,DataContract> the feeds that became late in this run
     */
    public function checkLate(?int $tenantId = null): array
    {
        $late = [];
        $contracts = DataContract::query()->where('active', true)->whereNotNull('last_batch_at')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->get();

        foreach ($contracts as $c) {
            $after = $c->lateAfterHours();
            if ($after === null) {
                continue;
            }
            $hours = (int) floor($c->last_batch_at->diffInMinutes(now(), true) / 60);
            if ($hours <= $after) {
                continue;
            }
            if (ContractViolation::where('data_contract_id', $c->id)->where('kind', ContractViolation::KIND_LATE)->whereNull('resolved_at')->exists()) {
                continue;   // already reported
            }
            $expected = $c->freshness_sla_hours ? "its {$c->freshness_sla_hours}h service level" : "its usual every ~{$c->expected_every_hours}h";
            $detail = "No batch for {$hours}h (last {$c->last_batch_at->diffForHumans()}), beyond {$expected}.";
            $this->violation($c, null, ContractViolation::KIND_LATE, $detail);
            $c->update(['status' => DataContract::STATUS_LATE, 'status_detail' => $detail, 'status_at' => now()]);
            $this->alerter->alert($c->tenant_id, 'Data feed late — ' . $c->label(),
                $detail . ' Detection keeps running on the data it has; findings for this dataset may be stale.', $c->owner_email);
            $late[] = $c;
        }

        return $late;
    }

    private function violation(DataContract $c, ?Import $import, string $kind, string $detail): void
    {
        ContractViolation::create([
            'tenant_id'        => $c->tenant_id,
            'data_contract_id' => $c->id ?? tap($c)->save()->id,
            'feed_key'         => $c->feed_key,
            'import_id'        => $import?->id,
            'kind'             => $kind,
            'detail'           => $detail,
            'occurred_at'      => now(),
        ]);
    }

    private function resolveOpen(DataContract $c, array $kinds): void
    {
        ContractViolation::where('data_contract_id', $c->id)->whereIn('kind', $kinds)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    /** Surface feed warnings on the batch's own quality record. */
    private function markOnBatch(Import $import, array $codes): void
    {
        if ($codes === []) {
            return;
        }
        $q = ImportQuality::where('import_id', $import->id)->first();
        if (! $q) {
            return;
        }
        $counts = $q->reason_counts ?? [];
        foreach ($codes as $code) {
            $counts[$code] = max(1, (int) ($counts[$code] ?? 0));
        }
        $q->reason_counts = $counts;
        $q->save();
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
