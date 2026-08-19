<?php

namespace App\Services\DataHealth;

use App\Models\DataHealthSetting;
use App\Models\DataHealthSnapshot;
use App\Models\IngestionRun;
use App\Models\Investigation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DataHealthService — Feature 4 (Data Health Center)
 *
 * Deterministic, tenant-scoped health scoring for each source dataset. All
 * scores are pure functions of counts, timestamps and thresholds — no AI, no
 * randomness. AI may narrate these facts elsewhere but never computes them.
 *
 * Composite score weights (must sum to 1.0):
 *   freshness 0.35 · completeness 0.25 · validity 0.25 · referential 0.15
 */
class DataHealthService
{
    public const VERSION = 'v1';

    public const WEIGHTS = [
        'freshness'    => 0.35,
        'completeness' => 0.25,
        'validity'     => 0.25,
        'referential'  => 0.15,
    ];

    /** Default thresholds per dataset (tenant-overridable via DataHealthSetting). */
    public const DEFAULTS = [
        DataHealthSnapshot::DATASET_SALES           => ['freshness_max_hours' => 48,   'completeness_min_pct' => 90, 'rejection_max_pct' => 5],
        DataHealthSnapshot::DATASET_INVENTORY       => ['freshness_max_hours' => 48,   'completeness_min_pct' => 90, 'rejection_max_pct' => 5],
        DataHealthSnapshot::DATASET_PURCHASE_ORDERS => ['freshness_max_hours' => 72,   'completeness_min_pct' => 85, 'rejection_max_pct' => 5],
        DataHealthSnapshot::DATASET_PRODUCTS        => ['freshness_max_hours' => 720,  'completeness_min_pct' => 80, 'rejection_max_pct' => 5],
        DataHealthSnapshot::DATASET_STORES          => ['freshness_max_hours' => 8760, 'completeness_min_pct' => 70, 'rejection_max_pct' => 5],
        DataHealthSnapshot::DATASET_SUPPLIERS       => ['freshness_max_hours' => 8760, 'completeness_min_pct' => 70, 'rejection_max_pct' => 5],
    ];

    /** Datasets backed by ingestion_runs (have a data_type). */
    public const INGESTION_TYPES = [
        DataHealthSnapshot::DATASET_SALES           => IngestionRun::TYPE_SALES,
        DataHealthSnapshot::DATASET_INVENTORY       => IngestionRun::TYPE_INVENTORY,
        DataHealthSnapshot::DATASET_PURCHASE_ORDERS => IngestionRun::TYPE_PURCHASE_ORDERS,
        DataHealthSnapshot::DATASET_PRODUCTS        => IngestionRun::TYPE_PRODUCTS,
    ];

    /** Evidence source tables mapped to datasets — used to find affected investigations. */
    public const EVIDENCE_SOURCES = [
        DataHealthSnapshot::DATASET_SALES           => ['sales_transactions', 'sales'],
        DataHealthSnapshot::DATASET_INVENTORY       => ['inventory_levels', 'inventory', 'inventory_snapshots'],
        DataHealthSnapshot::DATASET_PURCHASE_ORDERS => ['purchase_orders', 'po'],
        DataHealthSnapshot::DATASET_PRODUCTS        => ['products'],
        DataHealthSnapshot::DATASET_SUPPLIERS       => ['suppliers', 'supplier'],
        DataHealthSnapshot::DATASET_STORES          => ['stores'],
    ];

    /**
     * Recompute and persist snapshots for every dataset of a tenant.
     *
     * @return Collection<int,DataHealthSnapshot>
     */
    public function computeForTenant(int $tenantId): Collection
    {
        $snapshots = collect();
        foreach (array_keys(self::DEFAULTS) as $dataset) {
            $data = $this->computeDataset($tenantId, $dataset);

            $snapshot = DataHealthSnapshot::updateOrCreate(
                ['tenant_id' => $tenantId, 'dataset' => $dataset],
                $data + ['computed_at' => now()],
            );
            $snapshots->push($snapshot);
        }

        return $snapshots;
    }

    /**
     * Deterministically compute one dataset's health metrics (no persistence).
     */
    public function computeDataset(int $tenantId, string $dataset): array
    {
        $thresholds = $this->getThresholds($tenantId, $dataset);
        $warnings   = [];

        [$table, $dateColumn] = $this->tableFor($dataset);

        $received = DB::table($table)->where('tenant_id', $tenantId)->count();

        if ($received === 0) {
            return [
                'status'           => DataHealthSnapshot::STATUS_NO_DATA,
                'score'            => null,
                'last_ingested_at' => null,
                'last_record_at'   => null,
                'freshness_hours'  => null,
                'completeness_pct' => null,
                'validity_pct'     => null,
                'records_received' => 0,
                'records_accepted' => 0,
                'records_rejected' => 0,
                'warnings'         => [['level' => 'critical', 'message' => 'No ' . $dataset . ' data has been ingested yet.']],
                'metrics'          => ['version' => self::VERSION, 'reason' => 'no_data'],
            ];
        }

        // ── Freshness ────────────────────────────────────────────────────────
        $lastRecordAt = $this->lastRecordAt($table, $dateColumn, $tenantId);
        $lastRun      = $this->latestIngestionRun($tenantId, $dataset);
        $lastIngestedAt = $lastRun?->completed_at ?? $lastRun?->started_at;

        $freshnessRef = $lastRecordAt ?? $lastIngestedAt;
        $freshnessHours = $freshnessRef ? (int) round(now()->diffInMinutes($freshnessRef) / 60) : null;

        $freshnessScore = $this->freshnessScore($freshnessHours, $thresholds['freshness_max_hours']);
        if ($freshnessHours !== null && $freshnessHours > $thresholds['freshness_max_hours']) {
            $warnings[] = [
                'level'   => $freshnessHours > $thresholds['freshness_max_hours'] * 2 ? 'critical' : 'warning',
                'message' => ucfirst($dataset) . ' data is ' . $freshnessHours . ' hours old (threshold '
                             . $thresholds['freshness_max_hours'] . 'h). Conclusions relying on it may be incomplete.',
            ];
        }

        // ── Completeness ─────────────────────────────────────────────────────
        [$completenessPct, $completenessDetail] = $this->completeness($table, $tenantId, $dataset, $received);
        if ($completenessPct < $thresholds['completeness_min_pct']) {
            $warnings[] = [
                'level'   => 'warning',
                'message' => ucfirst($dataset) . ' completeness ' . $completenessPct . '% is below the '
                             . $thresholds['completeness_min_pct'] . '% target.',
            ];
        }

        // ── Validity (from ingestion rejection rate) ─────────────────────────
        [$validityPct, $accepted, $rejected] = $this->validity($tenantId, $dataset, $received);
        $rejectionPct = $received > 0 ? round(($rejected / max($received, 1)) * 100, 1) : 0.0;
        if ($rejectionPct > $thresholds['rejection_max_pct']) {
            $warnings[] = [
                'level'   => 'warning',
                'message' => 'Rejection rate ' . $rejectionPct . '% exceeds the '
                             . $thresholds['rejection_max_pct'] . '% threshold (possible rejection spike).',
            ];
        }

        // ── Referential integrity ────────────────────────────────────────────
        [$referentialScore, $orphanCount] = $this->referentialIntegrity($tenantId, $dataset, $received);
        if ($orphanCount > 0) {
            $warnings[] = [
                'level'   => $referentialScore < 90 ? 'critical' : 'warning',
                'message' => $orphanCount . ' ' . $dataset . ' record(s) reference a SKU with no matching product.',
            ];
        }

        // ── Composite score ──────────────────────────────────────────────────
        $score = round(
            $freshnessScore            * self::WEIGHTS['freshness']
            + $completenessPct         * self::WEIGHTS['completeness']
            + $validityPct             * self::WEIGHTS['validity']
            + $referentialScore        * self::WEIGHTS['referential'],
            1
        );

        $status = $this->statusFromScore($score, $warnings);

        return [
            'status'           => $status,
            'score'            => $score,
            'last_ingested_at' => $lastIngestedAt,
            'last_record_at'   => $lastRecordAt,
            'freshness_hours'  => $freshnessHours,
            'completeness_pct' => $completenessPct,
            'validity_pct'     => $validityPct,
            'records_received' => $received,
            'records_accepted' => $accepted,
            'records_rejected' => $rejected,
            'warnings'         => $warnings,
            'metrics'          => [
                'version'            => self::VERSION,
                'weights'            => self::WEIGHTS,
                'thresholds'         => $thresholds,
                'freshness_score'    => $freshnessScore,
                'completeness'       => $completenessDetail,
                'referential_score'  => $referentialScore,
                'orphan_count'       => $orphanCount,
                'rejection_pct'      => $rejectionPct,
                'last_ingestion_run' => $lastRun?->id,
            ],
        ];
    }

    /**
     * Open investigations that rely on a dataset (via evidence source) — used to
     * surface "affected investigations" when a dataset is stale/critical.
     *
     * @return Collection<int,Investigation>
     */
    public function affectedInvestigations(int $tenantId, string $dataset, int $limit = 25): Collection
    {
        $sources = self::EVIDENCE_SOURCES[$dataset] ?? [];
        if (empty($sources)) {
            return collect();
        }

        return Investigation::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->whereHas('evidence', fn ($q) => $q->whereIn('source', $sources))
            ->orderByDesc('opened_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Additive data-health caveats for an investigation. This is how Data Health
     * "reduces evidence strength" — as a non-destructive annotation, never by
     * mutating the stored evidence strength value.
     *
     * @return array<int,string>
     */
    public function evidenceCaveats(Investigation $investigation): array
    {
        $sources = $investigation->evidence->pluck('source')->filter()->unique();
        if ($sources->isEmpty()) {
            return [];
        }

        $caveats   = [];
        $snapshots = DataHealthSnapshot::where('tenant_id', $investigation->tenant_id)
            ->whereIn('status', [DataHealthSnapshot::STATUS_WARNING, DataHealthSnapshot::STATUS_CRITICAL, DataHealthSnapshot::STATUS_NO_DATA])
            ->get();

        foreach ($snapshots as $snap) {
            $datasetSources = self::EVIDENCE_SOURCES[$snap->dataset] ?? [];
            if ($sources->intersect($datasetSources)->isNotEmpty()) {
                $age = $snap->freshness_hours !== null ? $snap->freshness_hours . 'h old' : 'unavailable';
                $caveats[] = $snap->getDatasetLabel() . ' data is ' . $age
                             . ' — related conclusions may be incomplete.';
            }
        }

        return $caveats;
    }

    /**
     * Overall tenant health summary across all datasets.
     */
    public function overall(int $tenantId): array
    {
        $snaps = DataHealthSnapshot::where('tenant_id', $tenantId)->get();
        $scored = $snaps->whereNotNull('score');

        $status = DataHealthSnapshot::STATUS_HEALTHY;
        if ($snaps->contains(fn ($s) => $s->status === DataHealthSnapshot::STATUS_CRITICAL || $s->status === DataHealthSnapshot::STATUS_NO_DATA)) {
            $status = DataHealthSnapshot::STATUS_CRITICAL;
        } elseif ($snaps->contains(fn ($s) => $s->status === DataHealthSnapshot::STATUS_WARNING)) {
            $status = DataHealthSnapshot::STATUS_WARNING;
        }

        return [
            'status'      => $snaps->isEmpty() ? DataHealthSnapshot::STATUS_NO_DATA : $status,
            'score'       => $scored->isNotEmpty() ? round($scored->avg('score'), 1) : null,
            'datasets'    => $snaps->count(),
            'warning_count' => $snaps->sum(fn ($s) => is_array($s->warnings) ? count($s->warnings) : 0),
            'last_computed' => $snaps->max('computed_at'),
        ];
    }

    /**
     * Dispatch in-app notifications for critical data conditions to tenant admins.
     */
    public function notifyCritical(int $tenantId, Collection $snapshots): void
    {
        $critical = $snapshots->filter(
            fn (DataHealthSnapshot $s) => in_array($s->status, [DataHealthSnapshot::STATUS_CRITICAL, DataHealthSnapshot::STATUS_NO_DATA], true)
        );
        if ($critical->isEmpty()) {
            return;
        }

        $adminIds = User::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('is_tenant_admin', true)->orWhere('is_super_admin', true))
            ->pluck('id')->all();

        if (empty($adminIds)) {
            return;
        }

        $labels = $critical->map(fn ($s) => $s->getDatasetLabel())->implode(', ');
        NotificationDispatcher::toUsers(
            $adminIds,
            'Data Health: critical condition',
            'Critical data health for: ' . $labels . '. Investigations relying on these sources may be limited.',
            null,
            'heroicon-o-exclamation-triangle',
            'danger'
        );
    }

    // ── Threshold resolution ──────────────────────────────────────────────────

    public function getThresholds(int $tenantId, string $dataset): array
    {
        $defaults = self::DEFAULTS[$dataset] ?? self::DEFAULTS[DataHealthSnapshot::DATASET_SALES];
        $setting  = DataHealthSetting::where('tenant_id', $tenantId)->where('dataset', $dataset)->first();

        return [
            'freshness_max_hours'  => $setting?->freshness_max_hours  ?? $defaults['freshness_max_hours'],
            'completeness_min_pct' => $setting?->completeness_min_pct ?? $defaults['completeness_min_pct'],
            'rejection_max_pct'    => $setting?->rejection_max_pct    ?? $defaults['rejection_max_pct'],
        ];
    }

    // ── Internal deterministic helpers ────────────────────────────────────────

    /** @return array{0:string,1:?string} [table, dateColumn] */
    private function tableFor(string $dataset): array
    {
        return match ($dataset) {
            DataHealthSnapshot::DATASET_SALES           => ['sales_transactions', 'date'],
            DataHealthSnapshot::DATASET_INVENTORY       => ['inventory_levels', 'as_of_date'],
            DataHealthSnapshot::DATASET_PURCHASE_ORDERS => ['purchase_orders', 'order_date'],
            DataHealthSnapshot::DATASET_PRODUCTS        => ['products', null],
            DataHealthSnapshot::DATASET_STORES          => ['stores', null],
            DataHealthSnapshot::DATASET_SUPPLIERS       => ['suppliers', null],
            default                                     => ['products', null],
        };
    }

    private function lastRecordAt(string $table, ?string $dateColumn, int $tenantId): ?Carbon
    {
        $col = $dateColumn ?: 'updated_at';
        $val = DB::table($table)->where('tenant_id', $tenantId)->max($col);
        if (! $val) {
            // Fall back to updated_at for date-typed datasets with null dates
            $val = DB::table($table)->where('tenant_id', $tenantId)->max('updated_at');
        }
        return $val ? Carbon::parse($val) : null;
    }

    private function latestIngestionRun(int $tenantId, string $dataset): ?IngestionRun
    {
        $type = self::INGESTION_TYPES[$dataset] ?? null;
        if (! $type) {
            return null;
        }
        return IngestionRun::where('tenant_id', $tenantId)
            ->where('data_type', $type)
            ->orderByDesc('id')
            ->first();
    }

    /** 100 when fresh; linear decay to 0 at 3× the freshness threshold. */
    private function freshnessScore(?int $hours, int $maxHours): float
    {
        if ($hours === null) {
            return 0.0;
        }
        if ($hours <= $maxHours) {
            return 100.0;
        }
        $decayRange = max($maxHours * 2, 1); // from max → 3×max
        $over = $hours - $maxHours;
        return round(max(0, 100 - ($over / $decayRange) * 100), 1);
    }

    /** @return array{0:float,1:array} [pct, detail] */
    private function completeness(string $table, int $tenantId, string $dataset, int $received): array
    {
        $criticalCols = match ($dataset) {
            DataHealthSnapshot::DATASET_SALES           => ['sku', 'quantity', 'total_amount', 'date'],
            DataHealthSnapshot::DATASET_INVENTORY       => ['sku', 'on_hand_qty'],
            DataHealthSnapshot::DATASET_PURCHASE_ORDERS => ['sku', 'qty_ordered', 'order_date'],
            DataHealthSnapshot::DATASET_PRODUCTS        => ['sku', 'name', 'unit_cost', 'selling_price'],
            DataHealthSnapshot::DATASET_STORES          => ['name'],
            DataHealthSnapshot::DATASET_SUPPLIERS       => ['name'],
            default                                     => ['id'],
        };

        $detail = [];
        $ratios = [];
        foreach ($criticalCols as $col) {
            $present = DB::table($table)->where('tenant_id', $tenantId)->whereNotNull($col)->count();
            $ratio = $received > 0 ? $present / $received : 1.0;
            $ratios[] = $ratio;
            $detail[$col] = round($ratio * 100, 1);
        }
        $pct = empty($ratios) ? 100.0 : round((array_sum($ratios) / count($ratios)) * 100, 1);
        return [$pct, $detail];
    }

    /** @return array{0:float,1:int,2:int} [validityPct, accepted, rejected] */
    private function validity(int $tenantId, string $dataset, int $received): array
    {
        $run = $this->latestIngestionRun($tenantId, $dataset);
        if ($run && $run->rows_processed > 0) {
            $accepted = (int) $run->rows_imported;
            $rejected = (int) $run->rows_failed;
            $validity = $run->validation_score !== null
                ? (float) $run->validation_score
                : round(($accepted / max($run->rows_processed, 1)) * 100, 1);
            return [max(0, min(100, $validity)), $accepted, $rejected];
        }
        // Reference datasets without ingestion runs: assume accepted = present rows
        return [100.0, $received, 0];
    }

    /** @return array{0:float,1:int} [score, orphanCount] */
    private function referentialIntegrity(int $tenantId, string $dataset, int $received): array
    {
        // Only SKU-bearing transactional datasets have referential dependency on products.
        if (! in_array($dataset, [
            DataHealthSnapshot::DATASET_SALES,
            DataHealthSnapshot::DATASET_INVENTORY,
            DataHealthSnapshot::DATASET_PURCHASE_ORDERS,
        ], true)) {
            return [100.0, 0];
        }

        $table = $this->tableFor($dataset)[0];

        $orphans = DB::table($table . ' as t')
            ->where('t.tenant_id', $tenantId)
            ->whereNotNull('t.sku')
            ->whereNotExists(function ($q) use ($tenantId) {
                $q->select(DB::raw(1))
                    ->from('products as p')
                    ->whereColumn('p.sku', 't.sku')
                    ->where('p.tenant_id', $tenantId);
            })
            ->count();

        $score = $received > 0 ? round((1 - $orphans / $received) * 100, 1) : 100.0;
        return [max(0, $score), $orphans];
    }

    private function statusFromScore(float $score, array $warnings): string
    {
        $hasCritical = collect($warnings)->contains(fn ($w) => ($w['level'] ?? '') === 'critical');
        if ($hasCritical || $score < 60) {
            return DataHealthSnapshot::STATUS_CRITICAL;
        }
        if ($score < 85 || ! empty($warnings)) {
            return DataHealthSnapshot::STATUS_WARNING;
        }
        return DataHealthSnapshot::STATUS_HEALTHY;
    }
}
