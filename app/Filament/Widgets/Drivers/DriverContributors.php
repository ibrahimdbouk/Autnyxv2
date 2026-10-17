<?php

namespace App\Filament\Widgets\Drivers;

use App\Filament\Resources\InvestigationResource;
use App\Models\Investigation;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** Where it came from: the stores, departments and products that moved it most, each with its largest driver. */
class DriverContributors extends Widget
{
    use InteractsWithPageFilters;
    use UsesDriverAnalysis;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.drivers.contributors';

    protected function getViewData(): array
    {
        $r = $this->result();
        $tenantId = (int) Filament::getTenant()?->id;
        $groups = [];
        foreach (['stores' => 'Stores', 'departments' => 'Departments', 'skus' => 'Products'] as $key => $title) {
            $rows = (array) ($r['contributors'][$key] ?? []);
            if ($rows === []) {
                continue;
            }
            $links = [];
            if ($key === 'skus') {
                $links = Investigation::where('tenant_id', $tenantId)->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
                    ->whereIn('primary_sku', array_column($rows, 'id'))->orderByDesc('revenue_at_risk')->get(['id', 'primary_sku'])
                    ->unique('primary_sku')->mapWithKeys(fn ($i) => [$i->primary_sku => InvestigationResource::getUrl('investigate', ['record' => $i->id])])->all();
            }
            $groups[] = ['title' => $title, 'rows' => array_map(fn ($c) => $c + [
                'change_text' => $this->signed((float) $c['change']),
                'main_text'   => $c['main_label'] . ' ' . $this->signed((float) $c['main_value']),
                'url'         => $links[$c['id']] ?? null,
            ], $rows)];
        }

        return ['groups' => $groups, 'has' => $r !== null];
    }
}
