<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FxRateResource\Pages;
use App\Jobs\Fx\ReapplyExchangeRatesJob;
use App\Models\FxRate;
use App\Services\Fx\FxService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * W13 — exchange rates into the tenant's own currency, for stores, sales
 * lines and purchase orders in another currency.
 */
class FxRateResource extends Resource
{
    protected static ?string $model = FxRate::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Exchange Rates';

    protected static ?string $modelLabel = 'exchange rate';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) ($u && ($u->is_super_admin || $u->is_tenant_admin));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id);
    }

    public static function form(Schema $form): Schema
    {
        $base = fn () => \App\Support\Money::normalize(Filament::getTenant()?->currency);

        return $form->schema([
            TextInput::make('currency')->label('Currency')->required()->length(3)->regex('/^[A-Za-z]{3}$/')
                ->dehydrateStateUsing(fn ($s) => strtoupper((string) $s))->helperText('3-letter code, e.g. KWD.'),
            DatePicker::make('valid_from')->label('From')->required()->default(now()->startOfMonth()),
            TextInput::make('rate')->label(fn () => 'Rate (' . $base() . ' per 1 unit)')->numeric()->required()->minValue(0.00000001),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('currency')->weight('semibold')->searchable(),
                TextColumn::make('valid_from')->label('From')->date('j M Y')->sortable(),
                TextColumn::make('rate')->numeric(6),
                TextColumn::make('source')->badge()->placeholder('manual'),
            ])
            ->defaultSort('valid_from', 'desc')
            ->actions([EditAction::make(), DeleteAction::make()])
            ->headerActions([
                Action::make('pegs')->label('Add GCC dollar pegs')->icon('heroicon-o-sparkles')->color('gray')
                    ->visible(fn () => isset(FxService::USD_PEGS[\App\Support\Money::normalize(Filament::getTenant()?->currency)]))
                    ->action(function () {
                        $n = app(FxService::class)->seedPegs((int) Filament::getTenant()?->id);
                        Notification::make()->title($n ? "{$n} pegged rate(s) added (USD, AED, SAR, QAR, OMR, BHD)" : 'Already there')->success()->send();
                    }),
                Action::make('reapply')->label('Re-apply to sales and POs')->icon('heroicon-o-arrow-path')->color('gray')
                    ->requiresConfirmation()->modalDescription('Rebuilds the daily, weekly and monthly sales and the PO costs at the current rates. Runs in the background.')
                    ->action(function () {
                        ReapplyExchangeRatesJob::dispatch((int) Filament::getTenant()?->id);
                        Notification::make()->title('Re-applying in the background')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No exchange rates')
            ->emptyStateDescription('Only needed when stores, sales or purchase orders are in a currency other than yours.');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListFxRates::route('/'),
            'create' => Pages\CreateFxRate::route('/create'),
            'edit'   => Pages\EditFxRate::route('/{record}/edit'),
        ];
    }
}
