<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SuppressionResource\Pages;
use App\Models\AnomalySetting;
use App\Models\Store;
use App\Models\Suppression;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * SuppressionResource — Feature 6 (admin view of suppressions)
 *
 * Suppressions prevent known-noisy patterns from surfacing for a scope + period.
 * Restricted to admins because broad suppression is consequential.
 */
class SuppressionResource extends Resource
{
    protected static ?string $model = Suppression::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-bell-slash';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Suppressions';

    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        return auth()->user()?->canChangeAnomalyThresholds() ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->with(['store', 'createdBy']);
    }

    public static function getNavigationBadge(): ?string
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return null;
        }
        $count = Suppression::currentlyActive()->where('tenant_id', $tenantId)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Select::make('scope_type')
                ->label('Scope')
                ->options(Suppression::SCOPE_LABELS)
                ->default(Suppression::SCOPE_RULE_SKU)
                ->required()
                ->helperText('Narrow scopes are safer. Rule-wide suppression silences an entire rule.'),

            Select::make('rule_type')
                ->label('Rule')
                ->options(fn () => collect(AnomalySetting::RULES ?? [])
                    ->mapWithKeys(fn ($r, $k) => [$k => $r['label'] ?? $k])
                    ->toArray())
                ->searchable()
                ->required(),

            TextInput::make('sku')
                ->label('SKU')
                ->maxLength(100)
                ->helperText('Required for SKU-scoped suppressions.'),

            Select::make('store_id')
                ->label('Store')
                ->options(fn () => Store::where('tenant_id', Filament::getTenant()?->id)->orderBy('name')->pluck('name', 'id')->toArray())
                ->searchable()
                ->helperText('Required for store-scoped suppressions.'),

            Select::make('reason')
                ->label('Reason')
                ->options(Suppression::REASON_LABELS)
                ->required(),

            Textarea::make('notes')
                ->label('Notes')
                ->rows(2)
                ->columnSpanFull(),

            DateTimePicker::make('starts_at')
                ->label('Starts')
                ->default(now()),

            DateTimePicker::make('expires_at')
                ->label('Expires')
                ->default(now()->addDays(30))
                ->helperText('Leave blank for indefinite (discouraged). Defaults to 30 days.'),

            Toggle::make('active')
                ->label('Active')
                ->default(true)
                ->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scope_type')
                    ->label('Scope')
                    ->formatStateUsing(fn ($state) => Suppression::SCOPE_LABELS[$state] ?? $state)
                    ->badge()
                    ->color('gray'),

                TextColumn::make('rule_type')
                    ->label('Rule')
                    ->formatStateUsing(fn ($state) => AnomalySetting::RULES[$state]['label'] ?? $state)
                    ->searchable()
                    ->wrap(),

                TextColumn::make('sku')->label('SKU')->placeholder('—')->searchable(),
                TextColumn::make('store.name')->label('Store')->placeholder('—'),

                TextColumn::make('reason')
                    ->formatStateUsing(fn ($state) => Suppression::REASON_LABELS[$state] ?? $state)
                    ->badge()
                    ->color('info'),

                IconColumn::make('active')->boolean()->label('Active')->alignCenter(),

                TextColumn::make('match_count')
                    ->label('Silenced')
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => $state > 50 ? 'warning' : 'gray')
                    ->tooltip('Anomalies silenced by this suppression'),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('M j, Y')
                    ->placeholder('Indefinite')
                    ->color(fn ($record) => $record->expires_at === null ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('createdBy.name')->label('Created by')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('active')
                    ->options([1 => 'Active', 0 => 'Inactive'])
                    ->label('State'),
                SelectFilter::make('reason')->options(Suppression::REASON_LABELS),
            ])
            ->actions([
                EditAction::make(),
                Action::make('end')
                    ->label('End')
                    ->icon('heroicon-o-stop-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Suppression $record) => $record->active)
                    ->action(fn (Suppression $record) => $record->update([
                        'active'   => false,
                        'ended_at' => now(),
                        'ended_by' => auth()->id(),
                    ])),
                DeleteAction::make()->requiresConfirmation(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSuppressions::route('/'),
            'create' => Pages\CreateSuppression::route('/create'),
            'edit'   => Pages\EditSuppression::route('/{record}/edit'),
        ];
    }
}
