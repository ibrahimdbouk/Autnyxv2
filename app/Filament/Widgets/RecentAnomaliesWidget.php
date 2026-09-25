<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Shared\BaseTableWidget;
use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Services\Anomaly\AnomalyDismissal;
use Filament\Facades\Filament;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RecentAnomaliesWidget extends BaseTableWidget
{
    protected static ?string $heading = 'Recent Anomalies';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $tenantId = Filament::getTenant()?->id;

        return $table
            ->query(
                Anomaly::query()
                    ->where('tenant_id', $tenantId)
                    ->active()
                    ->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
                    ->orderByDesc('detected_at')
            )
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'high'   => 'danger',
                        'medium' => 'warning',
                        'low'    => 'info',
                        default  => 'gray',
                    }),

                TextColumn::make('rule_type')
                    ->label('Rule')
                    ->formatStateUsing(fn (string $state): string => AnomalySetting::RULES[$state]['label'] ?? $state)
                    ->badge()
                    ->color('gray'),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->placeholder('—'),

                TextColumn::make('description')
                    ->wrap()
                    ->limit(100),

                TextColumn::make('detected_at')
                    ->label('Detected')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->form([
                        \Filament\Forms\Components\Select::make('reason')
                            ->label('Why dismiss it?')
                            ->options(AnomalyDismissal::REASONS)
                            ->helperText('Only "False positive" teaches the detector to be less sensitive for this item.')
                            ->required(),
                    ])
                    ->visible(fn (Anomaly $record) => !$record->isDismissed() && auth()->user()?->canDismissAnomalies())
                    ->action(function (Anomaly $record, array $data) {
                        // WP4.3 (audit H14): the person says why; only a false
                        // positive feeds back into the baselines.
                        app(AnomalyDismissal::class)->dismiss($record, (string) $data['reason'], auth()->user());
                    }),
            ])
            ->headerActions([
                Action::make('view_all')
                    ->label('View all anomalies →')
                    ->url(fn (): string => route('filament.admin.resources.anomalies.index', [
                        'tenant' => Filament::getTenant()?->slug,
                    ]))
                    ->color('gray')
                    ->size('sm'),
            ])
            ->paginated(false)
            ->striped();
    }
}
