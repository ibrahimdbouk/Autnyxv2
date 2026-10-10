<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Filament\Widgets\Assortment\CleanUpProgrammes;
use App\Models\Tenant;
use App\Services\Assortment\TailProgrammes;
use App\Support\Money;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * v2.3 — tail clean-up: products the engine proposes to delist in most of the
 * stores that carry them, as one chain-wide programme. A native Filament table
 * of the candidates (select some → make a programme) and, below, the programmes
 * with their progress store by store.
 */
class AssortmentCleanUp extends Page implements HasTable
{
    use GatesAssortmentScreen;
    use InteractsWithTable;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_cleanup';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-scissors';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Tail Clean-up';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'assortment-cleanup';

    protected string $view = 'filament.assortment.cleanup';

    public function getTitle(): string
    {
        return 'Tail clean-up';
    }

    /** @var array<string,array>|null per request */
    private ?array $candidates = null;

    public function candidates(): array
    {
        return $this->candidates ??= app(TailProgrammes::class)->candidates(Filament::getTenant());
    }

    public function table(Table $table): Table
    {
        $currency = Filament::getTenant()?->currencyCode();

        return $table
            ->heading('Candidates')
            ->description('Delisted by the engine in at least ' . TailProgrammes::MIN_STORES . ' stores and in at least ' . (int) (TailProgrammes::MIN_SHARE * 100) . '% of the stores that carry it. Select products to make one programme.')
            ->records(fn () => $this->candidates())
            ->columns([
                TextColumn::make('name')->label('Product')->weight('semibold')->wrap()->description(fn (array $record) => $record['sku'] . ($record['brand'] ? ' · ' . $record['brand'] : '')),
                TextColumn::make('category')->label('Category'),
                TextColumn::make('stores')->label('Stores proposing')->alignEnd()
                    ->formatStateUsing(fn ($state, array $record) => $record['stores'] . ' of ' . $record['carrying'] . ' carrying (' . (int) round($record['share'] * 100) . '%)'),
                TextColumn::make('mid')->label('Worth a year')->alignEnd()
                    ->formatStateUsing(fn ($state, array $record) => Money::compact($record['low'], $currency) . '–' . Money::compact($record['high'], $currency)),
                TextColumn::make('metres')->label('Shelf freed')->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state === null ? null : number_format((float) $state, 2) . ' m'),
            ])
            ->toolbarActions([
                BulkAction::make('programme')->label('Make a programme')->icon('heroicon-o-scissors')
                    ->form([TextInput::make('name')->label('Name')->maxLength(160)->placeholder('Tail clean-up: N products')])
                    ->action(function (Collection $records, array $data) {
                        $p = app(TailProgrammes::class)->create(Filament::getTenant(), auth()->user(), $records->pluck('sku')->all(), $data['name'] ?? null);
                        $this->candidates = null;
                        Notification::make()->title('Programme proposed: ' . $p->name)->body('Accept it below to accept its delists in every store at once.')->success()->send();
                        $this->dispatch('programmes-changed');
                    }),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No clean-up candidates')
            ->emptyStateDescription('No product is delisted in enough of the stores that carry it. Single-store delists stay in Decisions.');
    }

    protected function getFooterWidgets(): array
    {
        return [CleanUpProgrammes::class];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
