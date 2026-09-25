<?php

namespace App\Filament\Widgets;

use App\Services\OutcomeService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class FinancialSummaryWidget extends Widget
{
    use \App\Filament\Widgets\Shared\CachesPerTenant;   // WP6.4

    protected string $view = 'filament.widgets.financial-summary-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function getViewData(): array
    {
        $tenantId = Filament::getTenant()?->id;

        if (! $tenantId) {
            return [
                'total_at_risk'   => 0,
                'total_recovered' => 0,
                'recovery_rate'   => null,
                'fp_count'        => 0,
            ];
        }

        return $this->cachedForTenant('summary', fn () => app(OutcomeService::class)->tenantSummary($tenantId));
    }
}
