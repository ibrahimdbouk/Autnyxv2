<?php

namespace App\Services\Reporting;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\DataHealthSnapshot;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Services\DataHealth\DataHealthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ReportDataService — assembles deterministic, tenant-scoped report payloads for
 * a given date range. Every figure is a database aggregate; no AI, no estimates
 * generated here. The same payload feeds both the PDF (executive summary: KPIs +
 * summary tables) and the Excel export (full row-level detail sheets).
 *
 * Returned payload shape:
 *   [
 *     'type', 'title', 'tenant', 'period', 'generated_at', 'note',
 *     'kpis'             => [ ['label'=>, 'value'=>], ... ],
 *     'summary_sections' => [ ['heading'=>, 'columns'=>[], 'rows'=>[[...]]], ... ],
 *     'detail_sheets'    => [ ['name'=>, 'columns'=>[], 'rows'=>[[...]]], ... ],
 *   ]
 */
class ReportDataService
{
    public const TYPES = ['recovery', 'investigations', 'anomalies', 'data-health'];

    public const TYPE_LABELS = [
        'recovery'       => 'Recovery & Financial',
        'investigations' => 'Investigations',
        'anomalies'      => 'Anomalies & Detection',
        'data-health'    => 'Data Health & Quality',
    ];

    /**
     * The full span of data available for a tenant: [earliest, latest(now)].
     */
    public function availableRange(int $tenantId): array
    {
        $mins = array_filter([
            DB::table('anomalies')->where('tenant_id', $tenantId)->min('detected_at'),
            DB::table('investigations')->where('tenant_id', $tenantId)->min('opened_at'),
            DB::table('investigation_outcomes')->where('tenant_id', $tenantId)->min('created_at'),
            DB::table('sales_transactions')->where('tenant_id', $tenantId)->min('date'),
        ]);

        $earliest = ! empty($mins) ? Carbon::parse(min($mins)) : now()->subDays(30);

        return [$earliest->copy()->startOfDay(), now()->endOfDay()];
    }

    /**
     * Clamp a requested [from,to] to the available range and sane bounds.
     */
    public function clampRange(int $tenantId, ?string $from, ?string $to): array
    {
        [$availFrom, $availTo] = $this->availableRange($tenantId);

        $f = $from ? Carbon::parse($from)->startOfDay() : $availFrom->copy();
        $t = $to ? Carbon::parse($to)->endOfDay() : $availTo->copy();

        if ($f->lt($availFrom)) {
            $f = $availFrom->copy();
        }
        if ($t->gt($availTo)) {
            $t = $availTo->copy();
        }
        if ($f->gt($t)) {
            $f = $availFrom->copy();
            $t = $availTo->copy();
        }

        return [$f, $t];
    }

    public function build(string $type, int $tenantId, Carbon $from, Carbon $to): array
    {
        $base = [
            'type'         => $type,
            'title'        => self::TYPE_LABELS[$type] ?? ucfirst($type),
            'tenant'       => optional(\App\Models\Tenant::find($tenantId))->name ?? ('Tenant #' . $tenantId),
            'period'       => $from->format('M j, Y') . ' – ' . $to->format('M j, Y'),
            'generated_at' => now()->format('M j, Y H:i'),
            'note'         => null,
        ];

        return array_merge($base, match ($type) {
            'investigations' => $this->investigations($tenantId, $from, $to),
            'anomalies'      => $this->anomalies($tenantId, $from, $to),
            'data-health'    => $this->dataHealth($tenantId, $from, $to),
            default          => $this->recovery($tenantId, $from, $to),
        });
    }

    // ── Recovery & Financial ────────────────────────────────────────────────

    private function recovery(int $tenantId, Carbon $from, Carbon $to): array
    {
        $outcomes = InvestigationOutcome::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->with(['investigation.anomalies', 'investigation.actions', 'investigation.primaryStore'])
            ->get();

        $atRisk    = round($outcomes->sum(fn ($o) => (float) $o->revenue_at_risk), 2);
        $recovered = round($outcomes->sum(fn ($o) => (float) $o->observed_recovery), 2);
        $cost      = round($outcomes->sum(fn ($o) => (float) $o->cost_to_resolve), 2);
        $net       = round($recovered - $cost, 2);
        $rate      = $atRisk > 0 ? round(($recovered / $atRisk) * 100, 1) : null;
        $fp        = $outcomes->where('was_false_positive', true)->count();

        $resolved = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])
            ->whereBetween('resolved_at', [$from, $to])
            ->count();

        // Breakdowns (attribute each outcome to a single bucket)
        $byCause = $byStore = $byAction = [];
        foreach ($outcomes as $o) {
            $inv = $o->investigation;
            $cause = $inv ? (optional($inv->anomalies->sortByDesc('detected_at')->first())->rule_type ?? 'uncategorised') : 'uncategorised';
            $store = $inv?->primaryStore?->name ?? 'Unassigned';
            $action = $inv ? (optional($inv->actions->where('status', 'completed')->sortByDesc('completed_at')->first())->action_type ?? 'no_action') : 'no_action';
            $this->bump($byCause, $cause, $o);
            $this->bump($byStore, $store, $o);
            $this->bump($byAction, $action, $o);
        }

        $times = $this->averageTimes($tenantId, $from, $to);

        return [
            'kpis' => [
                ['label' => 'Revenue at Risk',     'value' => $this->money($atRisk)],
                ['label' => 'Observed Recovery',   'value' => $this->money($recovered)],
                ['label' => 'Recovery Rate',       'value' => $rate !== null ? $rate . '%' : '—'],
                ['label' => 'Net Impact',          'value' => $this->money($net)],
                ['label' => 'Investigations Resolved', 'value' => number_format($resolved)],
                ['label' => 'False Positives',     'value' => number_format($fp)],
            ],
            'summary_sections' => [
                [
                    'heading' => 'Recovery by cause',
                    'columns' => ['Cause', 'Recovered', 'At risk', 'Outcomes'],
                    'rows'    => $this->bucketRows($byCause, fn ($k) => AnomalySetting::RULES[$k]['label'] ?? ucwords(str_replace('_', ' ', $k))),
                ],
                [
                    'heading' => 'Recovery by store',
                    'columns' => ['Store', 'Recovered', 'At risk', 'Outcomes'],
                    'rows'    => $this->bucketRows($byStore, fn ($k) => $k),
                ],
                [
                    'heading' => 'Recovery by action',
                    'columns' => ['Action', 'Recovered', 'At risk', 'Outcomes'],
                    'rows'    => $this->bucketRows($byAction, fn ($k) => ucwords(str_replace('_', ' ', $k))),
                ],
                [
                    'heading' => 'Response times',
                    'columns' => ['Metric', 'Value'],
                    'rows'    => [
                        ['Avg time to action',     $times['action'] !== null ? $times['action'] . ' h' : '—'],
                        ['Avg time to resolution', $times['resolution'] !== null ? $times['resolution'] . ' h' : '—'],
                    ],
                ],
            ],
            'detail_sheets' => [
                [
                    'name'    => 'Outcomes',
                    'columns' => ['Investigation', 'Title', 'SKU', 'Outcome', 'State', 'Revenue at Risk', 'Observed Recovery', 'Cost to Resolve', 'Recovery Method', 'Recorded'],
                    'rows'    => $outcomes->map(fn ($o) => [
                        $o->investigation_id,
                        $o->investigation?->title ?? '',
                        $o->investigation?->primary_sku ?? '',
                        $o->getOutcomeTypeLabel(),
                        $o->getOutcomeStateLabel(),
                        (float) $o->revenue_at_risk,
                        (float) $o->observed_recovery,
                        (float) $o->cost_to_resolve,
                        $o->getRecoveryMethodLabel(),
                        optional($o->recorded_at ?? $o->created_at)->format('Y-m-d H:i'),
                    ])->all(),
                ],
            ],
        ];
    }

    // ── Investigations ──────────────────────────────────────────────────────

    private function investigations(int $tenantId, Carbon $from, Carbon $to): array
    {
        $invs = Investigation::where('tenant_id', $tenantId)
            ->whereBetween('opened_at', [$from, $to])
            ->with(['assignedTeam', 'primaryStore'])
            ->orderByDesc('opened_at')
            ->get();

        $total    = $invs->count();
        $open     = $invs->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])->count();
        $resolved = $invs->whereIn('status', [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])->count();
        $rate     = $total > 0 ? round(($resolved / $total) * 100, 1) : null;

        $resolutionHours = $invs
            ->filter(fn ($i) => $i->resolved_at && $i->opened_at)
            ->map(fn ($i) => Carbon::parse($i->opened_at)->diffInHours(Carbon::parse($i->resolved_at)))
            ->filter(fn ($h) => $h >= 0);
        $avgResolution = $resolutionHours->isNotEmpty() ? round($resolutionHours->avg(), 1) : null;

        $byStatus   = $this->countBy($invs, 'status');
        $byPriority = $this->countBy($invs, 'priority');

        return [
            'kpis' => [
                ['label' => 'Total opened',      'value' => number_format($total)],
                ['label' => 'Open / in progress','value' => number_format($open)],
                ['label' => 'Resolved',          'value' => number_format($resolved)],
                ['label' => 'Resolution rate',   'value' => $rate !== null ? $rate . '%' : '—'],
                ['label' => 'Avg time to resolution', 'value' => $avgResolution !== null ? $avgResolution . ' h' : '—'],
                ['label' => 'Revenue at risk',   'value' => $this->money(round($invs->sum(fn ($i) => (float) $i->revenue_at_risk), 2))],
            ],
            'summary_sections' => [
                [
                    'heading' => 'By status',
                    'columns' => ['Status', 'Count'],
                    'rows'    => collect($byStatus)->map(fn ($c, $k) => [ucwords(str_replace('_', ' ', $k)), $c])->values()->all(),
                ],
                [
                    'heading' => 'By priority',
                    'columns' => ['Priority', 'Count'],
                    'rows'    => collect($byPriority)->map(fn ($c, $k) => [ucfirst($k), $c])->values()->all(),
                ],
            ],
            'detail_sheets' => [
                [
                    'name'    => 'Investigations',
                    'columns' => ['ID', 'Title', 'SKU', 'Store', 'Status', 'Priority', 'Team', 'Anomalies', 'Revenue at Risk', 'Observed Recovery', 'Opened', 'Resolved'],
                    'rows'    => $invs->map(fn ($i) => [
                        $i->id,
                        $i->title,
                        $i->primary_sku ?? '',
                        $i->primaryStore?->name ?? '',
                        ucwords(str_replace('_', ' ', (string) $i->status)),
                        ucfirst((string) $i->priority),
                        $i->assignedTeam?->name ?? '',
                        (int) $i->anomaly_count,
                        (float) $i->revenue_at_risk,
                        (float) $i->observed_recovery,
                        optional($i->opened_at)->format('Y-m-d H:i'),
                        optional($i->resolved_at)->format('Y-m-d H:i'),
                    ])->all(),
                ],
            ],
        ];
    }

    // ── Anomalies & Detection ───────────────────────────────────────────────

    private function anomalies(int $tenantId, Carbon $from, Carbon $to): array
    {
        $anoms = Anomaly::where('tenant_id', $tenantId)
            ->whereBetween('detected_at', [$from, $to])
            ->with('store')
            ->orderByDesc('detected_at')
            ->get();

        $total     = $anoms->count();
        $high      = $anoms->where('severity', 'high')->count();
        $dismissed = $anoms->whereNotNull('dismissed_at')->count();
        $fp        = $anoms->where('is_false_positive', true)->count();
        $correlated = $anoms->whereNotNull('investigation_id')->count();
        $fpRate    = $total > 0 ? round(($fp / $total) * 100, 1) : null;

        // Per-rule
        $byRule = [];
        foreach ($anoms as $a) {
            $k = $a->rule_type;
            if (! isset($byRule[$k])) {
                $byRule[$k] = ['detections' => 0, 'dismissed' => 0, 'fp' => 0];
            }
            $byRule[$k]['detections']++;
            if ($a->dismissed_at) {
                $byRule[$k]['dismissed']++;
            }
            if ($a->is_false_positive) {
                $byRule[$k]['fp']++;
            }
        }
        arsort($byRule);

        $bySeverity = $this->countBy($anoms, 'severity');

        return [
            'kpis' => [
                ['label' => 'Total detected',  'value' => number_format($total)],
                ['label' => 'High severity',   'value' => number_format($high)],
                ['label' => 'Correlated',      'value' => number_format($correlated)],
                ['label' => 'Dismissed',       'value' => number_format($dismissed)],
                ['label' => 'False positives', 'value' => number_format($fp)],
                ['label' => 'False-positive rate', 'value' => $fpRate !== null ? $fpRate . '%' : '—'],
            ],
            'summary_sections' => [
                [
                    'heading' => 'By rule',
                    'columns' => ['Rule', 'Detections', 'Dismissed', 'False positives'],
                    'rows'    => collect($byRule)->map(fn ($v, $k) => [
                        AnomalySetting::RULES[$k]['label'] ?? ucwords(str_replace('_', ' ', $k)),
                        $v['detections'], $v['dismissed'], $v['fp'],
                    ])->values()->all(),
                ],
                [
                    'heading' => 'By severity',
                    'columns' => ['Severity', 'Count'],
                    'rows'    => collect($bySeverity)->map(fn ($c, $k) => [ucfirst($k), $c])->values()->all(),
                ],
            ],
            'detail_sheets' => [
                [
                    'name'    => 'Anomalies',
                    'columns' => ['ID', 'Rule', 'Severity', 'SKU', 'Store', 'Detected', 'Investigation', 'Dismissed', 'False Positive'],
                    'rows'    => $anoms->map(fn ($a) => [
                        $a->id,
                        AnomalySetting::RULES[$a->rule_type]['label'] ?? $a->rule_type,
                        ucfirst((string) $a->severity),
                        $a->sku ?? '',
                        $a->store?->name ?? '',
                        optional($a->detected_at)->format('Y-m-d H:i'),
                        $a->investigation_id ?? '',
                        $a->dismissed_at ? 'Yes' : 'No',
                        $a->is_false_positive ? 'Yes' : 'No',
                    ])->all(),
                ],
            ],
        ];
    }

    // ── Data Health & Quality ───────────────────────────────────────────────

    private function dataHealth(int $tenantId, Carbon $from, Carbon $to): array
    {
        $snaps    = DataHealthSnapshot::where('tenant_id', $tenantId)->get();
        $overall  = app(DataHealthService::class)->overall($tenantId);

        $anomInRange = Anomaly::where('tenant_id', $tenantId)->whereBetween('detected_at', [$from, $to])->count();
        $invInRange  = Investigation::where('tenant_id', $tenantId)->whereBetween('opened_at', [$from, $to])->count();
        $resolvedInRange = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])
            ->whereBetween('opened_at', [$from, $to])->count();
        $qualityRate = $invInRange > 0 ? round(($resolvedInRange / $invInRange) * 100, 1) : null;

        $order = array_keys(DataHealthSnapshot::DATASET_LABELS);
        $snaps = $snaps->sortBy(fn ($s) => array_search($s->dataset, $order))->values();

        $warnRows = [];
        foreach ($snaps as $s) {
            if (is_array($s->warnings)) {
                foreach ($s->warnings as $w) {
                    $warnRows[] = [$s->getDatasetLabel(), ucfirst($w['level'] ?? 'warning'), $w['message'] ?? ''];
                }
            }
        }

        return [
            'note' => 'Data-health scores are point-in-time (as of generation); the anomaly/investigation figures are scoped to the selected period.',
            'kpis' => [
                ['label' => 'Overall data score', 'value' => $overall['score'] !== null ? number_format($overall['score'], 0) . '/100' : '—'],
                ['label' => 'Datasets scored',    'value' => number_format($overall['datasets'] ?? 0)],
                ['label' => 'Active warnings',    'value' => number_format($overall['warning_count'] ?? 0)],
                ['label' => 'Anomalies (period)', 'value' => number_format($anomInRange)],
                ['label' => 'Investigations (period)', 'value' => number_format($invInRange)],
                ['label' => 'Resolution rate (period)', 'value' => $qualityRate !== null ? $qualityRate . '%' : '—'],
            ],
            'summary_sections' => [
                [
                    'heading' => 'Dataset health (current)',
                    'columns' => ['Dataset', 'Status', 'Score', 'Freshness', 'Completeness', 'Validity', 'Rec / Acc / Rej'],
                    'rows'    => $snaps->map(fn ($s) => [
                        $s->getDatasetLabel(),
                        ucfirst(str_replace('_', ' ', (string) $s->status)),
                        $s->score !== null ? number_format($s->score, 0) : '—',
                        $s->freshness_hours !== null ? $s->freshness_hours . ' h' : '—',
                        $s->completeness_pct !== null ? $s->completeness_pct . '%' : '—',
                        $s->validity_pct !== null ? $s->validity_pct . '%' : '—',
                        number_format($s->records_received) . ' / ' . number_format($s->records_accepted) . ' / ' . number_format($s->records_rejected),
                    ])->all(),
                ],
                [
                    'heading' => 'Active warnings',
                    'columns' => ['Dataset', 'Level', 'Message'],
                    'rows'    => $warnRows ?: [['—', '—', 'No active warnings']],
                ],
            ],
            'detail_sheets' => [
                [
                    'name'    => 'Dataset Health',
                    'columns' => ['Dataset', 'Status', 'Score', 'Freshness (h)', 'Completeness %', 'Validity %', 'Received', 'Accepted', 'Rejected', 'Last Record'],
                    'rows'    => $snaps->map(fn ($s) => [
                        $s->getDatasetLabel(),
                        $s->status,
                        $s->score,
                        $s->freshness_hours,
                        $s->completeness_pct,
                        $s->validity_pct,
                        $s->records_received,
                        $s->records_accepted,
                        $s->records_rejected,
                        optional($s->last_record_at)->format('Y-m-d H:i'),
                    ])->all(),
                ],
                [
                    'name'    => 'Warnings',
                    'columns' => ['Dataset', 'Level', 'Message'],
                    'rows'    => $warnRows,
                ],
            ],
        ];
    }

    // ── Internal helpers ────────────────────────────────────────────────────

    private function averageTimes(int $tenantId, Carbon $from, Carbon $to): array
    {
        $resolved = Investigation::where('tenant_id', $tenantId)
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$from, $to])
            ->get(['opened_at', 'resolved_at', 'id']);

        $resHours = $resolved
            ->filter(fn ($i) => $i->opened_at)
            ->map(fn ($i) => Carbon::parse($i->opened_at)->diffInHours(Carbon::parse($i->resolved_at)))
            ->filter(fn ($h) => $h >= 0);

        $firstActions = DB::table('actions')
            ->join('investigations', 'investigations.id', '=', 'actions.investigation_id')
            ->where('investigations.tenant_id', $tenantId)
            ->whereNotNull('investigations.opened_at')
            ->whereBetween('actions.created_at', [$from, $to])
            ->selectRaw('investigations.id AS inv_id, investigations.opened_at AS opened_at, MIN(actions.created_at) AS first_action_at')
            ->groupBy('investigations.id', 'investigations.opened_at')
            ->get();

        $actHours = $firstActions
            ->map(fn ($r) => Carbon::parse($r->opened_at)->diffInHours(Carbon::parse($r->first_action_at)))
            ->filter(fn ($h) => $h >= 0);

        return [
            'action'     => $actHours->isNotEmpty() ? round($actHours->avg(), 1) : null,
            'resolution' => $resHours->isNotEmpty() ? round($resHours->avg(), 1) : null,
        ];
    }

    private function bump(array &$buckets, string $key, InvestigationOutcome $o): void
    {
        if (! isset($buckets[$key])) {
            $buckets[$key] = ['recovered' => 0.0, 'at_risk' => 0.0, 'count' => 0];
        }
        $buckets[$key]['recovered'] += (float) $o->observed_recovery;
        $buckets[$key]['at_risk']   += (float) $o->revenue_at_risk;
        $buckets[$key]['count']++;
    }

    private function bucketRows(array $buckets, callable $labeller): array
    {
        $rows = [];
        foreach ($buckets as $key => $b) {
            $rows[] = [$labeller($key), $this->money(round($b['recovered'], 2)), $this->money(round($b['at_risk'], 2)), $b['count']];
        }
        usort($rows, fn ($a, $b) => $b[3] <=> $a[3]);
        return $rows ?: [['—', $this->money(0), $this->money(0), 0]];
    }

    private function countBy($collection, string $field): array
    {
        $out = [];
        foreach ($collection as $item) {
            $k = (string) ($item->{$field} ?? 'unknown');
            $out[$k] = ($out[$k] ?? 0) + 1;
        }
        arsort($out);
        return $out;
    }

    private function money(float $v): string
    {
        return '$' . number_format($v, 2);
    }
}
