<?php

namespace App\Filament\Widgets\Assortment;

use App\Filament\Pages\AssortmentPlanPage;
use App\Filament\Pages\AssortmentPlans;
use App\Models\AssortmentBriefing;
use App\Models\AssortmentPlan;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** v2.1 — the range plans the rules put first this week. */
class BriefingPlans extends TableWidget
{
    protected static bool $isLazy = false;

    public ?int $briefing = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AssortmentPlans::canAccess();
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();
        $b = $this->briefing ? AssortmentBriefing::where('tenant_id', $tenant?->id)->find($this->briefing) : null;
        $ids = array_map(fn ($t) => (int) $t['id'], (array) ($b?->content['plans'] ?? []));
        $values = collect((array) ($b?->content['plans'] ?? []))->pluck('value', 'id');

        return $table
            ->heading('Range plans to review')
            ->query(AssortmentPlan::query()->with('store:id,name')->where('tenant_id', $tenant?->id)->whereIn('id', $ids === [] ? [0] : $ids)
                ->orderByRaw('value_mid * confidence DESC'))
            ->columns([
                TextColumn::make('headline')->label('Plan')->state(fn (AssortmentPlan $p) => $p->headline())->wrap(),
                TextColumn::make('value')->label('Category sales a year')->state(fn (AssortmentPlan $p) => $values[$p->id] ?? '—')->alignEnd(),
                TextColumn::make('confidence_tier')->label('Confidence')->formatStateUsing(fn (?string $state) => ucfirst((string) $state)),
                TextColumn::make('status')->label('Now')->badge()->color('gray')->formatStateUsing(fn (string $state) => AssortmentPlan::STATUSES[$state] ?? $state),
            ])
            ->recordUrl(fn (AssortmentPlan $p) => AssortmentPlanPage::getUrl(['plan' => $p->id]))
            ->paginated(false)
            ->emptyStateHeading('No plan waiting')
            ->emptyStateDescription('Range plans appear here once they are open to users.');
    }
}
