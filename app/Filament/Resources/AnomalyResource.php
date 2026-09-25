<?php

namespace App\Filament\Resources;
use App\Filament\Concerns\GatesResourceByScreen;

use App\Filament\Resources\AnomalyResource\Pages;
use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Services\Anomaly\AnomalyDismissal;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AnomalyResource extends Resource
{
    use GatesResourceByScreen;

    const SCREEN_KEY = 'anomalies';
    protected static ?string $model = Anomaly::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Anomalies';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool    { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool   { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        // Default: only show active anomalies — not dismissed, and not
        // recovery-resolved (resolved episodes are retained for measurement but
        // are no longer a live problem).
        // WP1.4: the active() default now lives in the "Show dismissed" filter's
        // blank state (see table()), so that toggle can actually show them.
        return parent::getEloquentQuery();
    }

    public static function getNavigationBadge(): ?string
    {
        $tenantId = \Filament\Facades\Filament::getTenant()?->id;
        if (!$tenantId) return null;

        $count = Anomaly::where('tenant_id', $tenantId)
            ->active()
            ->where('severity', 'high')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        $ruleOptions = collect(AnomalySetting::RULES)->map(fn ($r) => $r['label'])->toArray();

        return $table
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'high'   => 'danger',
                        'medium' => 'warning',
                        'low'    => 'info',
                        default  => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('rule_type')
                    ->label('Rule')
                    ->formatStateUsing(fn (string $state): string => AnomalySetting::RULES[$state]['label'] ?? $state)
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('description')
                    ->wrap()
                    ->limit(120),

                TextColumn::make('investigation_status')
                    ->label('Investigation')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'investigating'     => 'Investigating',
                        'cause_established' => 'Cause Established',
                        'action_taken'      => 'Action Taken',
                        'resolved'          => 'Resolved',
                        'unresolved'        => 'Unresolved',
                        default             => 'Not Started',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'investigating'     => 'warning',
                        'cause_established' => 'info',
                        'action_taken'      => 'warning',
                        'resolved'          => 'success',
                        'unresolved'        => 'danger',
                        default             => 'gray',
                    }),

                TextColumn::make('detected_at')
                    ->label('Detected')
                    ->since()
                    ->sortable(),
            ])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('No anomalies detected')
            ->emptyStateDescription('Detection runs nightly. Anomalies appear here once your sales, inventory and PO data has been ingested.')
            // WP1.4: severity is a string — alphabetical DESC put High last.
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->orderByDesc('detected_at'))
            ->filters([
                SelectFilter::make('severity')
                    ->options([
                        'high'   => 'High',
                        'medium' => 'Medium',
                        'low'    => 'Low',
                    ]),

                SelectFilter::make('rule_type')
                    ->label('Rule')
                    ->options($ruleOptions),

                SelectFilter::make('investigation_status')
                    ->label('Investigation')
                    ->options([
                        'detected'          => 'Not Started', // stored value is 'detected'
                        'investigating'     => 'Investigating',
                        'cause_established' => 'Cause Established',
                        'action_taken'      => 'Action Taken',
                        'resolved'          => 'Resolved',
                        'unresolved'        => 'Unresolved',
                    ]),

                // WP1.4: was ->withoutGlobalScopes() (a latent cross-tenant leak)
                // on top of an active() base query, so it always showed nothing.
                \Filament\Tables\Filters\TernaryFilter::make('dismissed')
                    ->label('Dismissed')
                    ->placeholder('Active only')
                    ->trueLabel('Dismissed only')
                    ->falseLabel('Active only')
                    ->queries(
                        true:  fn (Builder $query) => $query->whereNotNull('dismissed_at'),
                        false: fn (Builder $query) => $query->active(),
                        blank: fn (Builder $query) => $query->active(),
                    ),

                Filter::make('detected_at')
                    ->label('Detected Date')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'],  fn (Builder $q, $d) => $q->whereDate('detected_at', '>=', $d))
                            ->when($data['until'], fn (Builder $q, $d) => $q->whereDate('detected_at', '<=', $d));
                    }),
            ])
            ->actions([
                Action::make('investigate')
                    ->label('Investigate')
                    ->icon('heroicon-o-cpu-chip')
                    ->color('primary')
                    ->url(fn (Anomaly $record): string => static::getUrl('investigate', ['record' => $record->getKey()]))
                    ->visible(fn (Anomaly $record) => !$record->isDismissed()),

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
            ->bulkActions([
                BulkAction::make('export_csv')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $filename = 'anomalies-export-' . now()->format('Y-m-d') . '.csv';

                        // WP2.4 (audit L1/L3): CSV exports are audited like PDF/XLSX ones.
                        if ($__tid = \Filament\Facades\Filament::getTenant()?->id) {
                            \App\Support\ExportAudit::log($__tid, 'anomalies', 'csv');
                        }
                        return response()->streamDownload(function () use ($records) {
                            $out = fopen('php://output', 'w');

                            \App\Support\ExportAudit::putcsv($out, [
                                'ID',
                                'Severity',
                                'Rule',
                                'SKU',
                                'Description',
                                'Investigation Status',
                                'Action Notes',
                                'Resolution Notes',
                                'Detected At',
                                'Resolved At',
                            ]);

                            foreach ($records as $r) {
                                \App\Support\ExportAudit::putcsv($out, [
                                    $r->id,
                                    $r->severity,
                                    AnomalySetting::RULES[$r->rule_type]['label'] ?? $r->rule_type,
                                    $r->sku ?? '',
                                    $r->description,
                                    $r->investigation_status ?? 'not_started',
                                    $r->action_notes ?? '',
                                    $r->resolution_notes ?? '',
                                    \App\Support\Tenancy\TenantClock::display($r->detected_at)?->format('Y-m-d H:i') ?? '',
                                    \App\Support\Tenancy\TenantClock::display($r->resolved_at)?->format('Y-m-d H:i') ?? '',
                                ]);
                            }

                            fclose($out);
                        }, $filename, [
                            'Content-Type' => 'text/csv',
                        ]);
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'       => Pages\ListAnomalies::route('/'),
            'investigate' => Pages\InvestigateAnomaly::route('/{record}/investigate'),
        ];
    }
}
