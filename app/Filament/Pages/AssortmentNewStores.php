<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Models\AssortmentNewRange;
use App\Models\Tenant;
use App\Services\Assortment\NewStoreRanges;
use App\Services\Assortment\ShelfSpace;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * v2.3 — starting ranges for new stores and remodels: a native Filament table of
 * the ranges built, and a form to build one (store or format and region, the
 * categories, and the metres per category when known).
 */
class AssortmentNewStores extends Page implements HasTable
{
    use GatesAssortmentScreen;
    use InteractsWithTable;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_new_stores';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'New Stores & Remodels';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'assortment-new-stores';

    protected string $view = 'filament.assortment.new-stores';

    public function getTitle(): string
    {
        return 'New stores & remodels';
    }

    private function tenantId(): int
    {
        return (int) Filament::getTenant()?->id;
    }

    public function table(Table $table): Table
    {
        $currency = Filament::getTenant()?->currencyCode();
        $svc = app(NewStoreRanges::class);

        return $table
            ->query(AssortmentNewRange::query()->with(['store:id,name', 'creator:id,name'])->where('tenant_id', $this->tenantId()))
            ->columns([
                TextColumn::make('name')->label('Range')->weight('semibold')->wrap()
                    ->description(fn (AssortmentNewRange $r) => $r->donors['label'] ?? null),
                TextColumn::make('kind')->label('For')->badge()->formatStateUsing(fn (string $state) => $state === AssortmentNewRange::REMODEL ? 'Remodel' : 'New store')
                    ->color(fn (string $state) => $state === AssortmentNewRange::REMODEL ? 'warning' : 'info'),
                TextColumn::make('products')->label('Products')->alignEnd()
                    ->state(fn (AssortmentNewRange $r) => number_format(collect($r->result)->sum('count')) . ' in ' . count((array) $r->result) . ' categories'),
                TextColumn::make('sales')->label('Sales a year (estimated)')->alignEnd()
                    ->state(function (AssortmentNewRange $r) use ($currency) {
                        $lo = Money::compact((float) collect($r->result)->sum(fn ($c) => $c['sales'][0]), $currency);
                        $hi = Money::compact((float) collect($r->result)->sum(fn ($c) => $c['sales'][2]), $currency);

                        return $lo === $hi ? 'about ' . $lo : $lo . '–' . $hi;
                    }),
                TextColumn::make('own')->label('')->badge()->color('success')
                    ->state(fn (AssortmentNewRange $r) => $svc->ownHistory($r) ? 'Trading 12+ weeks: its own run judges it now' : null),
                TextColumn::make('created_at')->label('Built')->since()->description(fn (AssortmentNewRange $r) => $r->creator?->name),
            ])
            ->recordUrl(fn (AssortmentNewRange $r) => AssortmentNewRangePage::getUrl(['range' => $r->id]))
            ->recordActions([DeleteAction::make()->label('Remove')])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No ranges built yet')
            ->emptyStateDescription('Build a starting range for a store about to open, or for a store whose shelves are changing, from the stores most like it.');
    }

    protected function getHeaderActions(): array
    {
        $tid = $this->tenantId();
        $stores = fn () => DB::table('stores')->where('tenant_id', $tid)->orderBy('name')->pluck('name', 'id')->all();

        return [
            Action::make('build')->label('Build a range')->icon('heroicon-o-sparkles')
                ->modalDescription('Built from the stores most like it: the same format in the same region (or every store of the format when there are too few). Every figure is estimated from those stores. Nothing is changed.')
                ->form([
                    Radio::make('kind')->label('For')->options([AssortmentNewRange::NEW => 'A new store', AssortmentNewRange::REMODEL => 'A remodel of one of our stores'])
                        ->default(AssortmentNewRange::NEW)->required()->live(),
                    Select::make('store_id')->label(fn (Get $get) => $get('kind') === AssortmentNewRange::REMODEL ? 'Store' : 'The store, if it is already in your store list')
                        ->options($stores)->searchable()->live()
                        ->required(fn (Get $get) => $get('kind') === AssortmentNewRange::REMODEL),
                    Select::make('format')->label('Format')->required()->visible(fn (Get $get) => blank($get('store_id')))
                        ->options(fn () => DB::table('stores')->where('tenant_id', $tid)->whereNotNull('format')->distinct()->orderBy('format')->pluck('format', 'format')->all()),
                    Select::make('region')->label('Region')->placeholder('Any region')->visible(fn (Get $get) => blank($get('store_id')))
                        ->options(fn () => DB::table('stores')->where('tenant_id', $tid)->whereNotNull('region')->distinct()->orderBy('region')->pluck('region', 'region')->all()),
                    Select::make('categories')->label('Categories')->multiple()->placeholder('Every category')
                        ->options(fn () => DB::table('products')->where('tenant_id', $tid)->whereNotNull('category')
                            ->selectRaw('DISTINCT TRIM(category) AS c')->orderBy('c')->pluck('c', 'c')->all()),
                    KeyValue::make('metres')->label('Shelf metres per category (optional)')->keyLabel('Category')->valueLabel('Metres')
                        ->helperText('Leave empty to size each category to what the similar stores carry, or to the shelf file where one exists.'),
                    TextInput::make('name')->label('Name')->maxLength(160)->placeholder('Built from the store or format'),
                ])
                ->action(function (array $data) {
                    $r = app(NewStoreRanges::class)->build(Filament::getTenant(), auth()->user(), $data);
                    Notification::make()->title('Range built: ' . $r->name)->success()->send();
                    $this->redirect(AssortmentNewRangePage::getUrl(['range' => $r->id]), navigate: true);
                }),
        ];
    }
}
