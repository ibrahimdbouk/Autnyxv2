<?php

namespace App\Filament\Dashboards;

use App\Filament\Pages\ActionCenter;
use App\Filament\Pages\FinancialBreakdown;
use App\Filament\Resources\AnomalyResource;
use App\Filament\Resources\InvestigationResource;
use App\Models\Action;
use App\Models\Investigation;
use App\Models\Tenant;
use App\Services\Metrics\DashboardMetrics;

/**
 * The Root Cause tab of the Dashboard. Figures come from DashboardMetrics
 * (WP7.1); the view only formats them. Drill-down links carry the filter that
 * reproduces the figure and are left out when the user cannot open the target.
 */
class RootCauseDashboard implements AppDashboard
{
    public function view(): string
    {
        return 'filament.dashboards.root-cause';
    }

    public function data(?Tenant $tenant): array
    {
        $tenantId = $tenant?->id;
        $m        = $tenantId ? app(DashboardMetrics::class)->forTenant($tenantId) : app(DashboardMetrics::class)->compute(0);

        $canInv   = InvestigationResource::canViewAny();
        $canAnom  = AnomalyResource::canViewAny();
        $canFin   = FinancialBreakdown::canAccess();
        $canAct   = ActionCenter::canAccess();

        $recentHighPriority = $tenantId
            ? Investigation::where('tenant_id', $tenantId)
                ->whereIn('priority', ['critical', 'high'])
                ->whereIn('status', ['open', 'in_progress'])
                ->with(['assignedTeam', 'anomalies', 'primaryStore'])
                ->orderByDesc('opened_at')
                ->limit(6)
                ->get()
            : collect();

        $pendingActions = $tenantId
            ? Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId)->whereIn('status', ['open', 'in_progress']))
                ->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
                ->with(['investigation'])
                ->orderByRaw('due_at IS NULL, due_at')
                ->orderBy('created_at')
                ->limit(6)
                ->get()
            : collect();

        $anomaliesOfRule = fn (string $rule) => $canAnom
            ? AnomalyResource::getUrl('index', ['filters' => ['rule_type' => ['value' => $rule]]])
            : null;
        $insights = $m['insights'];

        // W10: the tenant's own KPIs (Root Cause → Custom KPIs), a minute's cache.
        $customKpis = $tenantId
            ? \Illuminate\Support\Facades\Cache::remember("dashboard:custom-kpis:{$tenantId}", 60, function () use ($tenantId) {
                try {
                    return app(\App\Platform\Extensibility\CustomRuleEngine::class)->tenantKpis($tenantId);
                } catch (\Throwable $e) {
                    report($e);

                    return [];
                }
            })
            : [];

        return [
            'customKpis'         => $customKpis,
            'customKpisUrl'      => \App\Filament\Resources\CustomMetricResource::canAccess() ? \App\Filament\Resources\CustomMetricResource::getUrl('index') : null,
            'm'                  => $m,
            'currency'           => \App\Support\Money::normalize($tenant?->currency),
            'recentHighPriority' => $recentHighPriority,
            'pendingActions'     => $pendingActions,
            'canInvestigate'     => $canInv,
            'links' => [
                'revenue_at_risk' => $canFin ? FinancialBreakdown::getUrl(['metric' => 'revenue_at_risk']) : null,
                'recovered_mtd'   => $canFin ? FinancialBreakdown::getUrl(['metric' => 'recovered_mtd']) : null,
                'cleared_mtd'     => $canFin ? FinancialBreakdown::getUrl(['metric' => 'observed_cleared']) : null,
                'open'            => $canInv ? InvestigationResource::getUrl('index', ['status' => 'open']) : null,
                'high'            => $canInv ? InvestigationResource::getUrl('index', ['status' => 'open', 'priority' => 'high_critical']) : null,
                'overdue'         => $canAct ? ActionCenter::getUrl(['tab' => 'overdue']) : null,
                'drivers'         => collect($m['drivers'])->mapWithKeys(fn ($d) => [$d['rule_type'] => $anomaliesOfRule($d['rule_type'])])->all(),
                'recurring'       => $insights['recurring'] ? $anomaliesOfRule($insights['recurring']['rule_type']) : null,
                'month_top'       => $insights['month_top'] ? $anomaliesOfRule($insights['month_top']['rule_type']) : null,
                'store'           => $insights['store'] && $canInv
                    ? InvestigationResource::getUrl('index', ['status' => 'open', 'store' => $insights['store']['id']]) : null,
                'open_all'        => $canInv ? InvestigationResource::getUrl('index', ['status' => 'open']) : null,
            ],
        ];
    }
}
