<?php

namespace App\Filament\Dashboards;

use App\Filament\Pages\AssortmentOutcomes;
use App\Filament\Pages\AssortmentValidation;
use App\Filament\Resources\AssortmentDecisionResource;
use App\Models\AssortmentGap;
use App\Models\AssortmentRun;
use App\Services\Assortment\TenantAssortment;
use App\Services\Assortment\ValidationGate;
use App\Models\Tenant;

/**
 * The Assortment tab of the Dashboard. Before the tenant passes the
 * validation gate it shows only data readiness (and, to admins, review
 * progress) — never the decisions. Once live: the range opportunity, the
 * decisions worth making now, range health by category, and measured results.
 */
class AssortmentDashboard implements AppDashboard
{
    public function view(): string
    {
        return 'filament.dashboards.assortment';
    }

    public function data(?Tenant $tenant): array
    {
        $run = $tenant
            ? AssortmentRun::query()->where('tenant_id', $tenant->id)
                ->whereIn('status', [AssortmentRun::STATUS_SUCCESS, AssortmentRun::STATUS_SKIPPED])
                ->latest('id')->first()
            : null;
        $s = $run?->stats ?? [];

        $groups      = $s['peer_groups'] ?? [];
        $historyDays = (int) ($s['range']['history_days'] ?? 0);
        $minDelist   = (int) config('assortment.delist_min_history_days', 182);

        $checks = $run && $run->status === AssortmentRun::STATUS_SUCCESS ? [
            [
                'label' => 'Products carried per store',
                'value' => number_format(($s['range']['stores'] ?? 0) > 0 ? (int) round(($s['range']['carried'] ?? 0) / $s['range']['stores']) : 0),
                'foot'  => 'Average across ' . number_format((int) ($s['range']['stores'] ?? 0)) . ' stores, '
                    . (($s['range']['from_listing'] ?? 0) > 0 ? 'from your range file and sales and stock history' : 'worked out from sales and stock history'),
                'color' => 'info',
            ],
            [
                'label' => 'History',
                'value' => intdiv($historyDays, 7) . ' weeks',
                'foot'  => $historyDays >= $minDelist ? 'Enough for add and delist decisions' : 'Delists wait for ' . intdiv($minDelist, 7) . ' weeks',
                'color' => $historyDays >= $minDelist ? 'success' : 'warning',
            ],
            [
                'label' => 'Peer groups',
                'value' => (string) count($groups),
                'foot'  => ($s['stores_unjudged'] ?? 0) > 0
                    ? ($s['stores_unjudged'] . ' stores have too few similar stores to compare')
                    : 'Every store has similar stores to compare with',
                'color' => ($s['stores_unjudged'] ?? 0) > 0 ? 'warning' : 'success',
            ],
            [
                'label' => 'Stock history',
                'value' => number_format((int) ($s['stock_history']['days'] ?? 0)) . ' days',
                'foot'  => 'Used to tell slow sellers from products that keep running out',
                'color' => ((int) ($s['stock_history']['days'] ?? 0)) >= 28 ? 'success' : 'warning',
            ],
        ] : [];

        $live = $tenant && TenantAssortment::isLive($tenant);
        $base = [
            'run'      => $run,
            'asOf'     => $s['as_of'] ?? null,
            'reason'   => $run && $run->status === AssortmentRun::STATUS_SKIPPED ? ($s['reason'] ?? null) : null,
            'checks'   => $checks,
            'live'     => $live,
            'currency' => $tenant?->currencyCode(),
            'gate'     => null,
            'validationUrl' => null,
        ];

        if (! $live) {
            if ($tenant && AssortmentValidation::canAccess()) {
                $base['gate'] = app(ValidationGate::class)->status($tenant);
                $base['validationUrl'] = AssortmentValidation::getUrl();
            }

            return $base;
        }

        $q     = AssortmentDecisionResource::getEloquentQuery();
        $open  = (clone $q)->where('status', AssortmentGap::STATUS_OPEN);
        $sums  = (clone $open)->setEagerLoads([])->selectRaw('type, COUNT(*) AS n, SUM(value_low) AS lo, SUM(value_high) AS hi')->groupBy('type')->get()->keyBy('type');
        $monthStart = now()->startOfMonth();
        $accepted = (clone $q)->where('status', AssortmentGap::STATUS_ACCEPTED);
        $measured = (clone $accepted)->whereNotNull('measured_at')->get(['measurement']);
        $list = fn (?string $type) => $type ? AssortmentDecisionResource::getUrl('index', ['filters' => ['type' => ['values' => [$type]]]]) : AssortmentDecisionResource::getUrl('index');

        return array_merge($base, [
            'kpis' => [
                ['label' => 'Range opportunity a year',
                    'value' => \App\Support\Money::displayCompact((float) $sums->sum('lo'), $tenant->currencyCode()) . '–' . \App\Support\Money::displayCompact((float) $sums->sum('hi'), $tenant->currencyCode()),
                    'foot' => 'Open decisions, as a range', 'url' => $list(null), 'color' => 'info'],
                ['label' => 'Adds to decide', 'value' => (string) (int) ($sums[AssortmentGap::TYPE_ADD]->n ?? 0), 'foot' => 'Products similar stores sell that this store does not', 'url' => $list(AssortmentGap::TYPE_ADD), 'color' => 'success'],
                ['label' => 'Delists to decide', 'value' => (string) (int) ($sums[AssortmentGap::TYPE_DELIST]->n ?? 0), 'foot' => 'Slow sellers that are usually in stock', 'url' => $list(AssortmentGap::TYPE_DELIST), 'color' => 'warning'],
                ['label' => 'Keep running out', 'value' => (string) (int) ($sums[AssortmentGap::TYPE_STOCKOUT_HIDDEN]->n ?? 0), 'foot' => 'Right product, empty shelf', 'url' => $list(AssortmentGap::TYPE_STOCKOUT_HIDDEN), 'color' => 'danger'],
                ['label' => 'Accepted this month', 'value' => (string) (clone $accepted)->where('decided_at', '>=', $monthStart)->count(), 'foot' => (clone $accepted)->where('task_status', AssortmentGap::TASK_TO_DO)->count() . ' task(s) to do', 'url' => AssortmentOutcomes::getUrl(), 'color' => null],
                ['label' => 'Measured result a year', 'value' => $measured->isEmpty() ? '—' : \App\Support\Money::displayCompact((float) $measured->sum(fn ($g) => (float) ($g->measurement['uplift_per_year'] ?? 0)), $tenant->currencyCode()),
                    'foot' => $measured->count() . ' measured against similar stores', 'url' => AssortmentOutcomes::getUrl(), 'color' => null],
            ],
            'queue'  => (clone $open)->orderByRaw('value_mid * confidence DESC')->limit(10)->get(),
            'health' => collect($s['health'] ?? [])->sortBy('coverage')->take(12)->values()->all(),
            'decisionsUrl' => $list(null),
        ]);
    }
}
