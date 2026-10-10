<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Models\AssortmentConstraint;
use App\Models\Tenant;
use App\Services\Assortment\AssortmentUploads;
use App\Services\Assortment\ShelfSpace;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * v2.2 — commercial constraints: the deals the range must keep (a line, a brand
 * or supplier floor), as a native Filament table with what each one cost in the
 * last run. Hard limits for decisions, range plans and the Decision Studio.
 */
class AssortmentDeals extends Page implements HasTable
{
    use GatesAssortmentScreen;
    use InteractsWithTable;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const ADMIN_ONLY = true;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-check';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Deals & Constraints';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'assortment-deals';

    protected string $view = 'filament.assortment.deals';

    public function getTitle(): string
    {
        return 'Deals & constraints';
    }

    private function tenantId(): int
    {
        return (int) Filament::getTenant()?->id;
    }

    public function table(Table $table): Table
    {
        $currency = Filament::getTenant()?->currencyCode();
        $names = fn () => DB::table('products')->where('tenant_id', $this->tenantId())->pluck('name', 'sku');

        return $table
            ->query(AssortmentConstraint::query()->with('store:id,name')->where('tenant_id', $this->tenantId()))
            ->columns([
                TextColumn::make('kind')->label('Deal')->badge()->formatStateUsing(fn (string $state) => AssortmentConstraint::KINDS[$state] ?? $state)
                    ->color(fn (string $state) => $state === AssortmentConstraint::KEEP ? 'info' : 'success'),
                TextColumn::make('what')->label('What it keeps')->wrap()
                    ->state(function (AssortmentConstraint $c) use ($names) {
                        static $n = null;
                        $n ??= $names();

                        return $c->kind === AssortmentConstraint::KEEP ? (($n[$c->sku] ?? null) ? $n[$c->sku] . ' (' . $c->sku . ')' : $c->sku) : $c->what();
                    }),
                TextColumn::make('place')->label('Where')->state(fn (AssortmentConstraint $c) => $c->place()),
                TextColumn::make('until')->label('Until')->date('j M Y')->placeholder('No end date')->sortable(),
                TextColumn::make('cost')->label('What keeping it cost')->wrap()
                    ->state(fn (AssortmentConstraint $c) => $c->cost === null
                        ? 'Worked out at the next run'
                        : ((int) ($c->cost['decisions'] ?? 0) === 0
                            ? 'Nothing held back in the last run'
                            : Money::compact((float) $c->cost['low'], $currency) . '–' . Money::compact((float) $c->cost['high'], $currency) . ' a year · '
                                . $c->cost['decisions'] . ' delist(s) held back'))
                    ->description(fn (AssortmentConstraint $c) => $c->cost['as_of'] ?? null ? 'Run on data to ' . \Carbon\Carbon::parse($c->cost['as_of'])->format('j M Y') : null),
                TextColumn::make('note')->label('Note')->placeholder('—')->wrap()->color('gray'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Deal')->options(AssortmentConstraint::KINDS),
                TernaryFilter::make('in_force')->label('In force')->default(true)
                    ->queries(
                        true: fn ($q) => $q->where(fn ($w) => $w->whereNull('until')->orWhere('until', '>=', today())),
                        false: fn ($q) => $q->whereNotNull('until')->where('until', '<', today()),
                    ),
            ])
            ->recordActions([DeleteAction::make()->label('Remove')->modalHeading('Remove this deal')])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No deals yet')
            ->emptyStateDescription('Add the lines, brands and suppliers your buyers have agreed to keep. The rules will never propose breaking them, and each shows what keeping it costs.');
    }

    protected function getHeaderActions(): array
    {
        $tenantId = $this->tenantId();

        return [
            Action::make('add')->label('Add a deal')->icon('heroicon-o-plus')
                ->form([
                    Select::make('kind')->label('Deal')->options(AssortmentConstraint::KINDS)->required()->live()->default(AssortmentConstraint::KEEP),
                    Select::make('sku')->label('Product')->searchable()->required()
                        ->visible(fn (Get $get) => $get('kind') === AssortmentConstraint::KEEP)
                        ->getSearchResultsUsing(fn (string $search) => DB::table('products')->where('tenant_id', $tenantId)
                            ->where(fn ($q) => $q->where('sku', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
                            ->orderBy('name')->limit(30)->get(['sku', 'name'])->mapWithKeys(fn ($p) => [$p->sku => ($p->name ?: $p->sku) . ' (' . $p->sku . ')'])->all())
                        ->getOptionLabelUsing(fn ($value) => $value),
                    Select::make('brand')->label('Brand')->searchable()->required()
                        ->visible(fn (Get $get) => $get('kind') === AssortmentConstraint::BRAND_MIN)
                        ->options(fn () => DB::table('products')->where('tenant_id', $tenantId)->whereNotNull('brand')->where('brand', '<>', '')
                            ->distinct()->orderBy('brand')->limit(2000)->pluck('brand', 'brand')->all()),
                    Select::make('supplier')->label('Supplier')->searchable()->required()
                        ->visible(fn (Get $get) => $get('kind') === AssortmentConstraint::SUPPLIER_MIN)
                        ->options(fn () => DB::table('products')->where('tenant_id', $tenantId)->whereNotNull('supplier')->where('supplier', '<>', '')
                            ->distinct()->orderBy('supplier')->limit(2000)->pluck('supplier', 'supplier')->all()),
                    TextInput::make('min_count')->label('At least this many products')->numeric()->minValue(1)->maxValue(500)->required()
                        ->visible(fn (Get $get) => $get('kind') !== AssortmentConstraint::KEEP),
                    Select::make('category')->label('In category')->placeholder('Every category it is in')
                        ->visible(fn (Get $get) => $get('kind') !== AssortmentConstraint::KEEP)
                        ->options(fn () => DB::table('products')->where('tenant_id', $tenantId)->whereNotNull('category')
                            ->selectRaw('DISTINCT TRIM(category) AS c')->orderBy('c')->pluck('c', 'c')->all()),
                    Select::make('store_id')->label('Store')->placeholder('Every store')->searchable()
                        ->options(fn () => DB::table('stores')->where('tenant_id', $tenantId)->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('format')->label('Or every store of a format')->placeholder('—')
                        ->visible(fn (Get $get) => blank($get('store_id')))
                        ->options(fn () => DB::table('stores')->where('tenant_id', $tenantId)->whereNotNull('format')->distinct()->pluck('format')
                            ->mapWithKeys(fn ($f) => [ShelfSpace::formatSlug($f) => Str::headline((string) $f)])->all()),
                    DatePicker::make('until')->label('Until')->minDate(today()),
                    TextInput::make('note')->maxLength(240)->placeholder('Contract reference, supplier, buyer'),
                ])
                ->action(function (array $data) use ($tenantId) {
                    AssortmentConstraint::create([
                        'tenant_id' => $tenantId, 'kind' => $data['kind'],
                        'sku' => $data['kind'] === AssortmentConstraint::KEEP ? $data['sku'] : null,
                        'brand' => $data['kind'] === AssortmentConstraint::BRAND_MIN ? $data['brand'] : null,
                        'supplier' => $data['kind'] === AssortmentConstraint::SUPPLIER_MIN ? $data['supplier'] : null,
                        'min_count' => $data['kind'] === AssortmentConstraint::KEEP ? null : (int) $data['min_count'],
                        'category' => $data['category'] ?? null, 'store_id' => $data['store_id'] ?? null,
                        'format' => blank($data['store_id'] ?? null) ? ($data['format'] ?? null) : null,
                        'until' => $data['until'] ?? null, 'note' => $data['note'] ?? null,
                    ]);
                    Notification::make()->title('Deal added — it applies from the next run, and in the Studio now')->success()->send();
                }),
            Action::make('upload')->label('Upload deals')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->modalDescription('One row per deal. Columns: kind (keep, brand or supplier), sku, brand, supplier, category, store or format (blank = every store), min (fewest products, for a floor), until, note.')
                ->form([
                    FileUpload::make('file')->label('CSV or Excel file')->disk('local')->directory('assortment-uploads')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(20480)->required(),
                ])
                ->action(function (array $data) use ($tenantId) {
                    $path = Storage::disk('local')->path((string) $data['file']);
                    try {
                        $r = app(AssortmentUploads::class)->importConstraints($tenantId, $path);
                        $n = Notification::make()->title($r['added'] . ' deal(s) added')
                            ->body($r['skipped'] ? implode("\n", array_slice($r['skipped'], 0, 8)) . (count($r['skipped']) > 8 ? "\n…and " . (count($r['skipped']) - 8) . ' more' : '') : null);
                        ($r['skipped'] ? $n->warning() : $n->success())->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Could not read the file')->body($e->getMessage())->danger()->send();
                    } finally {
                        Storage::disk('local')->delete((string) $data['file']);
                    }
                }),
        ];
    }
}
