<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Shared\BaseTableWidget;
use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Services\Anomaly\BaselineCalculatorService;
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
                    ->visible(fn (Anomaly $record) => !$record->isDismissed())
                    ->action(function (Anomaly $record) {
                        $dismissedAt = now();

                        // False-positive feedback: dismiss within 10 min of detection
                        if ($record->detected_at && $dismissedAt->diffInMinutes($record->detected_at) < 10) {
                            app(BaselineCalculatorService::class)
                                ->recordFalsePositive($record->tenant_id, $record->rule_type, $record->sku);
                        }

                        $record->update([
                            'dismissed_at' => $dismissedAt,
                            'dismissed_by' => auth()->id(),
                        ]);
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
