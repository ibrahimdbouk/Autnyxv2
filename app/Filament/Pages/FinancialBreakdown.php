<?php

namespace App\Filament\Pages;

use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Services\OutcomeService;
use App\Services\Outcome\RecoveryReportService;
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

    protected static ?string $slug = 'financial-breakdown';

    protected string $view = 'filament.pages.financial-breakdown';

    /** Which figure to explain. Deep-linked from the dashboard/widgets. */
    #[Url]
    public string $metric = 'revenue_at_risk';

    /** Valid metrics this page can derive. */
    public const METRICS = [
        'revenue_at_risk',   // open/in-progress investigations' AI-estimated risk (dashboard KPI)
        'recovered_mtd',     // observed recovery recorded this calendar month (dashboard KPI)
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
            'recovered_mtd'     => $this->recoveredMtd($tenantId),
            'outcome_at_risk'   => $this->outcomeAtRisk($tenantId),
            'observed_recovery' => $this->observedRecovery($tenantId),
            'recovery_rate'     => $this->recoveryRate($tenantId),
            'false_positives'   => $this->falsePositives($tenantId),
            default             => $this->revenueAtRisk($tenantId),
        };
    }

    /** Links back to the four metric tabs, so the page is self-navigable. */
    public function getTabs(): array
    {
        return [
            ['metric' => 'revenue_at_risk',   'label' => 'Revenue at Risk'],
            ['metric' => 'recovered_mtd',     'label' => 'Recovered MTD'],
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

    private function investigateUrl(int $investigationId): string
    {
        return \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $investigationId]);
    }

    private function money(float $val): string
    {
        if (abs($val) >= 1_000_000) {
            return '$' . number_format($val / 1_000_000, 2) . 'M';
        }
        if (abs($val) >= 1_000) {
            return '$' . number_format($val / 1_000, 1) . 'K';
        }
        return '$' . number_format($val, 0);
    }
}
