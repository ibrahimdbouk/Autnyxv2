<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Filament\Resources\InvestigationResource;
use App\Models\Investigation;
use App\Models\Store;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Investigations by Store — the store lens on the investigation backlog.
 *
 * Every store with its investigation count (total, open, urgent, value at risk),
 * ordered by where the open work is, and each store drills down to its own
 * investigations. Read-only; it re-projects governed investigation data grouped
 * by the investigation's primary store. Gated with the Investigations screen so
 * anyone who can see investigations can see this lens too.
 */
class InvestigationsByStore extends Page
{
    use GatesPageByScreen;

    const SCREEN_KEY = 'investigations';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'By Store';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'investigations-by-store';

    protected string $view = 'filament.pages.investigations-by-store';

    public function getTitle(): string
    {
        return 'Investigations by Store';
    }

    /**
     * Per-store investigation groups, most open work first. Accurate aggregates
     * from a grouped query; the drill-down list is capped per store for the page.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getStoreGroups(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return [];
        }

        $currency = Filament::getTenant()?->currencyCode() ?? 'AED';
        $open = [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS];

        // Accurate per-store aggregates.
        $agg = Investigation::where('tenant_id', $tenantId)
            ->selectRaw('primary_store_id, count(*) as total')
            ->selectRaw("sum(case when status in ('open','in_progress') then 1 else 0 end) as open_count")
            ->selectRaw("sum(case when status in ('open','in_progress') and priority in ('critical','high') then 1 else 0 end) as urgent")
            ->selectRaw('coalesce(sum(revenue_at_risk),0) as value')
            ->groupBy('primary_store_id')
            ->get();

        if ($agg->isEmpty()) {
            return [];
        }

        $storeIds = $agg->pluck('primary_store_id')->filter()->all();
        $stores = Store::where('tenant_id', $tenantId)->whereIn('id', $storeIds)->get()->keyBy('id');

        // Investigations to list under each store (bounded load, open first).
        $invs = Investigation::where('tenant_id', $tenantId)
            ->orderByRaw("case when status in ('open','in_progress') then 0 else 1 end")
            ->orderByDesc('opened_at')
            ->limit(1000)
            ->get()
            ->groupBy('primary_store_id');

        $groups = [];
        foreach ($agg->sortByDesc('open_count')->values() as $row) {
            $sid = $row->primary_store_id;
            $store = $sid ? $stores->get($sid) : null;

            $list = ($invs->get($sid) ?? collect())->take(50)->map(fn (Investigation $i) => [
                'id'         => $i->id,
                'title'      => $i->title ?: ('Investigation #' . $i->id),
                'status'     => $i->status,
                'status_label' => ucwords(str_replace('_', ' ', (string) $i->status)),
                'is_open'    => in_array($i->status, $open, true),
                'priority'   => $i->priority,
                'priority_label' => ucfirst((string) $i->priority),
                'value_fmt'  => Money::compact((float) $i->revenue_at_risk, $currency),
                'opened'     => optional($i->opened_at)->format('M j, Y'),
                'url'        => InvestigationResource::getUrl('investigate', ['record' => $i->id]),
            ])->all();

            $groups[] = [
                'store'           => $store?->name ?? 'Unassigned (no store on file)',
                'code'            => $store?->code,
                'city'            => $store?->city,
                'total'           => (int) $row->total,
                'open'            => (int) $row->open_count,
                'urgent'          => (int) $row->urgent,
                'value_fmt'       => Money::compact((float) $row->value, $currency),
                'investigations'  => $list,
                'shown'           => count($list),
            ];
        }

        return $groups;
    }
}
