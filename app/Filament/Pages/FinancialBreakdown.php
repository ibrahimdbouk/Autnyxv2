<?php

namespace App\Filament\Pages;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\Store;
use App\Services\OutcomeService;
use App\Services\Outcome\RecoveryReportService;
use App\Services\Recovery\AnomalyRecoveryService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * FinancialBreakdown — deterministic drill-down for the dashboard's aggregate
 * financial KPIs (Revenue at Risk, Recovered MTD, Observed Recovery, Recovery
 * Rate, False Positives).
 *
 * Every figure shown here is derived from the same models/services that produce
 * the headline number on the dashboard — no new or ad-hoc financial math. The
 * page explains HOW each number is built: the formula, the component figures,
 * and the exact contributing investigations/outcomes (each linked to its
 * investigate page). AI never invents figures; all values are DB aggregates.
 *
 * Reached by query string (?metric=…) from the dashboard KPI cards and the
 * financial widgets. Not registered in navigation — it is a drill-down target.
 */
class FinancialBreakdown extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'financial-breakdown';

    protected string $view = 'filament.pages.financial-breakdown';

    /** Which figure to explain. Deep-linked from the dashboard/widgets. */
    #[Url]
    public string $metric = 'revenue_at_risk';

    /** Valid metrics this page can derive. */
    public const METRICS = [
        'value_funnel',      // B6: surfaced → at-risk → recovered, the full ROI story
        'revenue_at_risk',   // open/in-progress investigations' AI-estimated risk (dashboard KPI)
        'recovered_mtd',     // observed recovery recorded this calendar month (dashboard KPI)
        'observed_cleared',  // R3: data-only observed recovery from the anomaly lifecycle
        'outcome_at_risk',   // revenue at risk snapshotted across ALL recorded outcomes (widget)
        'observed_recovery', // analyst-confirmed recovery across ALL outcomes (widget)
        'recovery_rate',     // recovered / at-risk across outcomes (widget)
        'false_positives',   // investigations closed as false positive (widget)
    ];

    /** Keep out of the sidebar — this is a drill-down, reached via links. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getTitle(): string
    {
        return 'Financial Breakdown — ' . $this->metricLabel();
    }

    public function mount(): void
    {
        if (! in_array($this->metric, self::METRICS, true)) {
            $this->metric = 'revenue_at_risk';
        }
    }

    private function metricLabel(): string
    {
        return match ($this->metric) {
            'recovered_mtd'     => 'Recovered This Month',
            'observed_cleared'  => 'Observed Cleared (Data-Only)',
            'outcome_at_risk'   => 'Revenue at Risk (Outcomes)',
            'observed_recovery' => 'Observed Recovery',
            'recovery_rate'     => 'Recovery Rate',
            'false_positives'   => 'False Positives',
            default             => 'Revenue at Risk',
        };
    }

    /**
     * Build the full deterministic breakdown for the selected metric.
     *
     * @return array<string,mixed>
     */
    public function getBreakdown(): array
    {
        $tenantId = Filament::getTenant()?->id;

        if (! $tenantId) {
            return [
                'metric'      => $this->metric,
                'label'       => $this->metricLabel(),
                'value'       => '—',
                'formula'     => '',
                'components'  => [],
                'rows'        => [],
                'rowsHeader'  => [],
                'amountLabel' => '',
                'empty'       => 'No tenant context.',
            ];
        }

        return match ($this->metric) {
            'value_funnel'      => $this->valueFunnel($tenantId),
            'recovered_mtd'     => $this->recoveredMtd($tenantId),
            'observed_cleared'  => $this->observedCleared($tenantId),
            'outcome_at_risk'   => $this->outcomeAtRisk($tenantId),
            'observed_recovery' => $this->observedRecovery($tenantId),
            'recovery_rate'     => $this->recoveryRate($tenantId),
            'false_positives'   => $this->falsePositives($tenantId),
            default             => $this->revenueAtRisk($tenantId),
        };
    }

    /** Links back to the metric tabs, so the page is self-navigable. */
    public function getTabs(): array
    {
        return [
            ['metric' => 'value_funnel',      'label' => 'Value Funnel'],
            ['metric' => 'revenue_at_risk',   'label' => 'Revenue at Risk'],
            ['metric' => 'recovered_mtd',     'label' => 'Recovered MTD'],
            ['metric' => 'observed_cleared',  'label' => 'Observed Cleared'],
            ['metric' => 'observed_recovery', 'label' => 'Observed Recovery'],
            ['metric' => 'recovery_rate',     'label' => 'Recovery Rate'],
            ['metric' => 'false_positives',   'label' => 'False Positives'],
        ];
    }

    public function setMetric(string $metric): void
    {
        if (in_array($metric, self::METRICS, true)) {
            $this->metric = $metric;
        }
    }

    // ── Metric derivations ──────────────────────────────────────────────────

    /**
     * B6 — the value funnel: what the engine SURFACED → what was ASSESSED at
     * risk → what was RECOVERED → the recovery rate. Ties the top of the funnel
     * (B1's estimated value at risk across open anomalies) to the recovery the
     * outcome loop already measures, so the ROI story reads end to end. Every
     * figure is an existing DB aggregate — nothing new is invented here.
     */
    private function valueFunnel(int $tenantId): array
    {
        $surfaced  = Anomaly::estimatedValueAtRiskForTenant($tenantId);
        $summary   = app(OutcomeService::class)->tenantSummary($tenantId);
        $atRisk    = (float) ($summary['total_at_risk'] ?? 0);
        $recovered = (float) ($summary['total_recovered'] ?? 0);
        $rate      = $atRisk > 0 ? round($recovered / $atRisk * 100, 1) : null;

        // R3 — observed (data-only) recovery straight from the anomaly lifecycle,
        // kept strictly SEPARATE from the attributed figure above. This is value
        // that stopped being at risk because the condition cleared and stayed
        // clear across evaluated runs — no cause claimed, and never summed with
        // attributed recovery (they measure different things).
        $obs = app(AnomalyRecoveryService::class)->summary($tenantId);

        return [
            'metric'      => 'value_funnel',
            'label'       => 'Value Funnel',
            'value'       => $this->money($surfaced),
            'formula'     => 'Surfaced (est. value at risk across open anomalies) → Observed cleared (lifecycle, data-only) → Attributed recovery (recorded outcomes) → Recovery rate. Observed and attributed are separate lenses, never added together. All deterministic DB aggregates.',
            'components'  => [
                ['label' => '1 · Surfaced — est. value at risk (open anomalies)',        'value' => $this->money($surfaced)],
                ['label' => '2 · Observed cleared — lifecycle, no cause claimed',        'value' => $this->money((float) $obs['observed_total'])],
                ['label' => '   ↳ still at risk (active episodes)',                      'value' => $this->money((float) $obs['active_at_risk'])],
                ['label' => '   ↳ observed clear rate',                                  'value' => $obs['clear_rate'] !== null ? $obs['clear_rate'] . '%' : '—'],
                ['label' => '3 · Attributed at risk — recorded outcomes',               'value' => $this->money($atRisk)],
                ['label' => '4 · Attributed recovery — analyst/action confirmed',       'value' => $this->money($recovered)],
                ['label' => '5 · Attributed recovery rate',                             'value' => $rate !== null ? $rate . '%' : '—'],
            ],
            'rows'        => [],
            'rowsHeader'  => [],
            'amountLabel' => '',
            'empty'       => $surfaced <= 0 && $atRisk <= 0 && (float) $obs['observed_total'] <= 0
                ? 'No value surfaced, cleared, or assessed yet — run detection and let the lifecycle observe some recoveries.'
                : null,
        ];
    }

    /**
     * Dashboard "Revenue at Risk" card: SUM(revenue_at_risk) over open /
     * in-progress investigations. Same query the dashboard blade runs.
     */
    private function revenueAtRisk(int $tenantId): array
    {
        $base = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS]);

        $total = (float) ((clone $base)->sum('revenue_at_risk') ?? 0);
        $count = (clone $base)->count();
        $withRisk = (clone $base)->whereNotNull('revenue_at_risk')->where('revenue_at_risk', '>', 0)->count();

        $rows = (clone $base)
            ->whereNotNull('revenue_at_risk')
            ->where('revenue_at_risk', '>', 0)
            ->orderByDesc('revenue_at_risk')
            ->limit(200)
            ->get(['id', 'title', 'primary_sku', 'status', 'priority', 'revenue_at_risk']);

        return [
            'metric'      => $this->metric,
            'label'       => 'Revenue at Risk',
            'value'       => $this->money($total),
            'formula'     => 'Σ revenue_at_risk for every investigation whose status is Open or In Progress. Revenue at risk is the AI narrator\'s estimate captured per investigation.',
            'components'  => [
                ['label' => 'Open / in-progress investigations', 'value' => number_format($count)],
                ['label' => 'With a revenue-at-risk estimate',   'value' => number_format($withRisk)],
                ['label' => 'Total revenue at risk',             'value' => $this->money($total)],
            ],
            'amountLabel' => 'Revenue at Risk',
            'rowsHeader'  => ['Investigation', 'SKU', 'Status', 'Revenue at Risk'],
            'rows'        => $this->investigationRows($rows, 'revenue_at_risk'),
            'empty'       => 'No open investigations carry a revenue-at-risk estimate yet.',
        ];
    }

    /**
     * Dashboard "Recovered MTD" card: SUM(observed_recovery) over outcomes
     * recorded this calendar month. Same query the dashboard blade runs.
     */
    private function recoveredMtd(int $tenantId): array
    {
        $monthStart = now()->startOfMonth();

        $base = InvestigationOutcome::where('tenant_id', $tenantId)
            ->where('created_at', '>=', $monthStart)
            ->whereNotNull('observed_recovery')
            ->where('observed_recovery', '>', 0);

        $total = (float) ((clone $base)->sum('observed_recovery') ?? 0);
        $count = (clone $base)->count();

        $outcomes = (clone $base)
            ->with('investigation')
            ->orderByDesc('observed_recovery')
            ->limit(200)
            ->get();

        return [
            'metric'      => $this->metric,
            'label'       => 'Recovered This Month',
            'value'       => $this->money($total),
            'formula'     => 'Σ observed_recovery for every outcome recorded since ' . $monthStart->format('M j, Y') . '. Observed recovery is analyst-confirmed after an investigation is resolved.',
            'components'  => [
                ['label' => 'Outcomes recorded this month', 'value' => number_format($count)],
                ['label' => 'Total observed recovery',      'value' => $this->money($total)],
            ],
            'amountLabel' => 'Observed Recovery',
            'rowsHeader'  => ['Investigation', 'SKU', 'Recovery Method', 'Observed Recovery'],
            'rows'        => $this->outcomeRows($outcomes, 'observed_recovery'),
            'empty'       => 'No recovery has been recorded this month.',
        ];
    }

    /**
     * R3 — "Observed Cleared (Data-Only)": Σ value_at_open over anomaly episodes
     * the lifecycle marked `resolved` this month (excluding backfilled history).
     * This is OBSERVED recovery — the engine watched the condition clear and stay
     * clear across evaluated runs — with NO cause attributed. It is deliberately
     * separate from "Recovered MTD" (attributed, analyst/action-confirmed) and the
     * two are never added together.
     */
    private function observedCleared(int $tenantId): array
    {
        $svc     = app(AnomalyRecoveryService::class);
        $summary = $svc->summary($tenantId);
        $mtd     = $svc->mtd($tenantId);
        $byRule  = $svc->byRuleFamily($tenantId, now()->startOfMonth(), null);
        $rows    = $svc->resolvedRows($tenantId, now()->startOfMonth(), null);

        $components = [
            ['label' => 'Episodes cleared this month',            'value' => number_format((int) $mtd['count'])],
            ['label' => 'Observed cleared this month',            'value' => $this->money((float) $mtd['amount'])],
            ['label' => 'Observed cleared (all time)',            'value' => $this->money((float) $summary['observed_total'])],
            ['label' => 'Still at risk (active episodes)',        'value' => $this->money((float) $summary['active_at_risk'])],
            ['label' => 'Observed clear rate',                    'value' => $summary['clear_rate'] !== null ? $summary['clear_rate'] . '%' : '—'],
        ];
        if ((int) $summary['backfilled_excluded'] > 0) {
            $components[] = ['label' => 'Backfilled episodes excluded (historical)', 'value' => number_format((int) $summary['backfilled_excluded'])];
        }
        foreach (array_slice($byRule, 0, 6) as $b) {
            $components[] = ['label' => '• ' . $b['label'] . ' (' . $b['count'] . ')', 'value' => $this->money($b['recovered'])];
        }

        return [
            'metric'      => $this->metric,
            'label'       => 'Observed Cleared (Data-Only)',
            'value'       => $this->money((float) $mtd['amount']),
            'formula'     => 'Σ value_at_open for every anomaly episode the lifecycle confirmed `resolved` since ' . now()->startOfMonth()->format('M j, Y') . ', excluding backfilled history. Observed recovery: the condition cleared and stayed clear across evaluated runs — no cause is claimed, and this is never added to attributed Recovered MTD.',
            'components'  => $components,
            'amountLabel' => 'Value Cleared',
            'rowsHeader'  => ['Anomaly', 'SKU / Store', 'Cleared', 'Value Cleared'],
            'rows'        => $this->anomalyRows($rows),
            'empty'       => 'No anomalies have been observed clearing this month yet.',
        ];
    }

    /**
     * Widget "Revenue at Risk": SUM(revenue_at_risk) across all recorded
     * outcomes — sourced from OutcomeService::tenantSummary (deterministic).
     */
    private function outcomeAtRisk(int $tenantId): array
    {
        $summary = app(OutcomeService::class)->tenantSummary($tenantId);

        $outcomes = InvestigationOutcome::where('tenant_id', $tenantId)
            ->with('investigation')
            ->whereNotNull('revenue_at_risk')
            ->orderByDesc('revenue_at_risk')
            ->limit(200)
            ->get();

        return [
            'metric'      => $this->metric,
            'label'       => 'Revenue at Risk (Recorded Outcomes)',
            'value'       => $this->money((float) $summary['total_at_risk']),
            'formula'     => 'Σ revenue_at_risk snapshotted across every recorded investigation outcome (OutcomeService::tenantSummary).',
            'components'  => [
                ['label' => 'Recorded outcomes', 'value' => number_format($outcomes->count())],
                ['label' => 'Total revenue at risk', 'value' => $this->money((float) $summary['total_at_risk'])],
            ],
            'amountLabel' => 'Revenue at Risk',
            'rowsHeader'  => ['Investigation', 'SKU', 'Outcome', 'Revenue at Risk'],
            'rows'        => $this->outcomeRows($outcomes, 'revenue_at_risk'),
            'empty'       => 'No outcomes have been recorded yet.',
        ];
    }

    /** Widget "Observed Recovery": SUM(observed_recovery) across all outcomes. */
    private function observedRecovery(int $tenantId): array
    {
        $summary = app(OutcomeService::class)->tenantSummary($tenantId);
        $report  = app(RecoveryReportService::class)->summary($tenantId);

        $outcomes = InvestigationOutcome::where('tenant_id', $tenantId)
            ->with('investigation')
            ->whereNotNull('observed_recovery')
            ->where('observed_recovery', '>', 0)
            ->orderByDesc('observed_recovery')
            ->limit(200)
            ->get();

        return [
            'metric'      => $this->metric,
            'label'       => 'Observed Recovery',
            'value'       => $this->money((float) $summary['total_recovered']),
            'formula'     => 'Σ observed_recovery across every recorded outcome (analyst-confirmed). ' . (int) $report['investigations_resolved'] . ' investigations resolved to date.',
            'components'  => [
                ['label' => 'Total revenue at risk',  'value' => $this->money((float) $summary['total_at_risk'])],
                ['label' => 'Total observed recovery', 'value' => $this->money((float) $summary['total_recovered'])],
                ['label' => 'Investigations resolved', 'value' => number_format((int) $report['investigations_resolved'])],
            ],
            'amountLabel' => 'Observed Recovery',
            'rowsHeader'  => ['Investigation', 'SKU', 'Recovery Method', 'Observed Recovery'],
            'rows'        => $this->outcomeRows($outcomes, 'observed_recovery'),
            'empty'       => 'No recovery has been recorded yet.',
        ];
    }

    /** Widget "Recovery Rate": observed_recovery / revenue_at_risk across outcomes. */
    private function recoveryRate(int $tenantId): array
    {
        $summary = app(OutcomeService::class)->tenantSummary($tenantId);

        $atRisk    = (float) $summary['total_at_risk'];
        $recovered = (float) $summary['total_recovered'];
        $rate      = $summary['recovery_rate'];

        $outcomes = InvestigationOutcome::where('tenant_id', $tenantId)
            ->with('investigation')
            ->whereNotNull('revenue_at_risk')
            ->orderByDesc('observed_recovery')
            ->limit(200)
            ->get();

        return [
            'metric'      => $this->metric,
            'label'       => 'Recovery Rate',
            'value'       => $rate !== null ? $rate . '%' : '—',
            'formula'     => 'Observed Recovery ÷ Revenue at Risk × 100, summed across all recorded outcomes.',
            'components'  => [
                ['label' => 'Total observed recovery', 'value' => $this->money($recovered)],
                ['label' => 'Total revenue at risk',   'value' => $this->money($atRisk)],
                ['label' => 'Recovery rate',           'value' => $rate !== null ? $rate . '%' : '—'],
            ],
            'amountLabel' => 'Observed Recovery',
            'rowsHeader'  => ['Investigation', 'SKU', 'Revenue at Risk', 'Observed Recovery'],
            'rows'        => $this->outcomeRows($outcomes, 'observed_recovery', 'revenue_at_risk'),
            'empty'       => 'No outcomes have been recorded yet.',
        ];
    }

    /** Widget "False Positives": outcomes flagged was_false_positive. */
    private function falsePositives(int $tenantId): array
    {
        $summary = app(OutcomeService::class)->tenantSummary($tenantId);

        $outcomes = InvestigationOutcome::where('tenant_id', $tenantId)
            ->with('investigation')
            ->where('was_false_positive', true)
            ->orderByDesc('recorded_at')
            ->limit(200)
            ->get();

        return [
            'metric'      => $this->metric,
            'label'       => 'False Positives',
            'value'       => number_format((int) $summary['fp_count']),
            'formula'     => 'Count of recorded outcomes flagged was_false_positive. Each also sends a false-positive signal back to the detection engine and marks its linked anomalies.',
            'components'  => [
                ['label' => 'Investigations closed as false positive', 'value' => number_format((int) $summary['fp_count'])],
            ],
            'amountLabel' => 'Revenue at Risk (avoided)',
            'rowsHeader'  => ['Investigation', 'SKU', 'Recorded', 'Revenue at Risk'],
            'rows'        => $this->outcomeRows($outcomes, 'revenue_at_risk'),
            'empty'       => 'No investigations have been closed as false positives.',
        ];
    }

    // ── Row builders ────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Support\Collection<int,Investigation>  $investigations
     * @return array<int,array<string,mixed>>
     */
    private function investigationRows($investigations, string $amountField): array
    {
        return $investigations->map(function (Investigation $inv) use ($amountField) {
            return [
                'id'       => $inv->id,
                'url'      => $this->investigateUrl($inv->id),
                'title'    => $inv->title ?: ('Investigation #' . $inv->id),
                'sku'      => $inv->primary_sku ?: '—',
                'meta'     => ucfirst(str_replace('_', ' ', (string) $inv->status)),
                'amount'   => $this->money((float) ($inv->{$amountField} ?? 0)),
            ];
        })->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int,InvestigationOutcome>  $outcomes
     * @return array<int,array<string,mixed>>
     */
    private function outcomeRows($outcomes, string $amountField, ?string $metaAmountField = null): array
    {
        return $outcomes->map(function (InvestigationOutcome $o) use ($amountField, $metaAmountField) {
            $inv = $o->investigation;
            $invId = $o->investigation_id;

            if ($metaAmountField !== null) {
                $meta = $this->money((float) ($o->{$metaAmountField} ?? 0));
            } else {
                $meta = $o->recovery_method
                    ? ucwords(str_replace('_', ' ', (string) $o->recovery_method))
                    : ($o->recorded_at ? $o->recorded_at->format('M j, Y') : '—');
            }

            return [
                'id'     => $invId,
                'url'    => $invId ? $this->investigateUrl($invId) : null,
                'title'  => $inv?->title ?: ('Investigation #' . $invId),
                'sku'    => $inv?->primary_sku ?: '—',
                'meta'   => $meta,
                'amount' => $this->money((float) ($o->{$amountField} ?? 0)),
            ];
        })->all();
    }

    /**
     * Drill-down rows for observed-cleared anomaly episodes.
     *
     * @param  \Illuminate\Support\Collection<int,Anomaly>  $anomalies
     * @return array<int,array<string,mixed>>
     */
    private function anomalyRows($anomalies): array
    {
        $storeIds = $anomalies->pluck('store_id')->filter()->unique()->all();
        $stores   = $storeIds
            ? Store::whereIn('id', $storeIds)->pluck('name', 'id')->all()
            : [];

        return $anomalies->map(function (Anomaly $a) use ($stores) {
            $label = AnomalySetting::RULES[$a->rule_type]['label'] ?? ucwords(str_replace('_', ' ', (string) $a->rule_type));
            $scope = $a->sku ?: ($a->store_id ? ($stores[$a->store_id] ?? ('Store #' . $a->store_id)) : '—');

            return [
                'id'     => $a->id,
                'url'    => $this->anomalyUrl($a->id),
                'title'  => $label . ' #' . $a->id,
                'sku'    => $scope,
                'meta'   => $a->resolved_at ? $a->resolved_at->format('M j, Y') : '—',
                'amount' => $this->money((float) ($a->value_at_open ?? 0)),
            ];
        })->all();
    }

    private function investigateUrl(int $investigationId): string
    {
        return \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $investigationId]);
    }

    private function anomalyUrl(int $anomalyId): string
    {
        return \App\Filament\Resources\AnomalyResource::getUrl('investigate', ['record' => $anomalyId]);
    }

    private function money(float $val): string
    {
        return \App\Support\Money::compact($val, Filament::getTenant()?->currencyCode());
    }
}
