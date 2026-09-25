<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AnomalyResource;
use App\Filament\Resources\InvestigationResource;
use App\Models\Action;
use App\Models\Investigation;
use App\Services\Metrics\DashboardMetrics;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The tenant dashboard. Figures come from DashboardMetrics (WP7.1); the view
 * only formats them. Drill-down links carry the filter that reproduces the
 * figure and are left out when the user cannot open the target screen.
 */
class Dashboard extends BaseDashboard
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.pages.dashboard';

    public function getTitle(): string
    {
        return 'Dashboard';
    }

    /** The custom view renders everything itself; no widget grid. */
    public function getWidgets(): array
    {
        return [];
    }

    protected function getViewData(): array
    {
        $tenant   = Filament::getTenant();
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

        return [
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
