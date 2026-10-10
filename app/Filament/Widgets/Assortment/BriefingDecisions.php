<?php

namespace App\Filament\Widgets\Assortment;

use App\Filament\Pages\AssortmentDecision;
use App\Models\AssortmentBriefing;
use App\Models\AssortmentGap;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** v2.1 — the decisions the rules put first this week (as they stand now). */
class BriefingDecisions extends TableWidget
{
    protected static bool $isLazy = false;

    public ?int $briefing = null;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();
        $b = $this->briefing ? AssortmentBriefing::where('tenant_id', $tenant?->id)->find($this->briefing) : null;
        $ids = array_map(fn ($t) => (int) $t['id'], (array) ($b?->content['top'] ?? []));
        $order = $ids === [] ? '0' : 'CASE id ' . implode(' ', array_map(fn ($id, $i) => "WHEN {$id} THEN {$i}", $ids, array_keys($ids))) . ' END';

        return $table
            ->heading('Worth deciding now')
            ->description('This week\'s new decisions first, then the rest, by value and confidence.')
            ->query(AssortmentGap::query()->where('tenant_id', $tenant?->id)->whereIn('id', $ids === [] ? [0] : $ids)->orderByRaw($order))
            ->columns([
                TextColumn::make('type')->label('Decision')->badge()
                    ->formatStateUsing(fn (string $state) => AssortmentGap::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { AssortmentGap::TYPE_ADD => 'success', AssortmentGap::TYPE_DELIST => 'warning', default => 'danger' }),
                TextColumn::make('headline')->label('What')->state(fn (AssortmentGap $g) => $g->headline())->wrap(),
                TextColumn::make('value')->label('Sales a year')->state(fn (AssortmentGap $g) => $g->valueRange($tenant?->currencyCode()))->alignEnd(),
                TextColumn::make('confidence_tier')->label('Confidence')->formatStateUsing(fn (?string $state) => ucfirst((string) $state)),
                TextColumn::make('status')->label('Now')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
            ])
            ->recordUrl(fn (AssortmentGap $g) => AssortmentDecision::getUrl(['decision' => $g->id]))
            ->paginated(false)
            ->emptyStateHeading('Nothing to decide')
            ->emptyStateDescription('No open range decision this week.');
    }
}
