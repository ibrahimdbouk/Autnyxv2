<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Models\AssortmentMustStock;
use App\Models\AssortmentStoreRange;
use App\Models\Tenant;
use App\Services\Assortment\AssortmentUploads;
use App\Services\Assortment\TenantAssortment;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Assortment setup (A6) — the few things a tenant controls:
 *   • the range file (what each store is authorised to carry),
 *   • the must-stock list (never proposed for delisting),
 *   • four guardrails: delist confidence, the "carried" window, and the most
 *     delists and adds per category per store in one run.
 * Everything else is an Autnyx preset, so results stay comparable.
 */
class AssortmentSetup extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const ADMIN_ONLY = true;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Range & Must-Stock';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'assortment-setup';

    protected string $view = 'filament.pages.assortment-setup';

    public function getTitle(): string
    {
        return 'Range & must-stock';
    }

    private function tenantId(): int
    {
        return (int) Filament::getTenant()?->id;
    }

    public function removeMustStock(int $id): void
    {
        AssortmentMustStock::where('tenant_id', $this->tenantId())->whereKey($id)->delete();
        Notification::make()->title('Removed from the must-stock list')->success()->send();
    }

    private function storeOptions(): array
    {
        return DB::table('stores')->where('tenant_id', $this->tenantId())->orderBy('name')->pluck('name', 'id')->all();
    }

    private function upload(string $label, string $what, callable $import): Action
    {
        return Action::make('upload_' . $what)->label($label)->icon('heroicon-o-arrow-up-tray')->color('gray')
            ->form([
                FileUpload::make('file')->label('CSV or Excel file')->disk('local')->directory('assortment-uploads')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->maxSize(20480)->required(),
            ])
            ->action(function (array $data) use ($import) {
                $path = Storage::disk('local')->path((string) $data['file']);
                try {
                    [$title, $skipped] = $import($path);
                    $n = Notification::make()->title($title)->body($skipped ? implode("\n", array_slice($skipped, 0, 8)) . (count($skipped) > 8 ? "\n…and " . (count($skipped) - 8) . ' more' : '') : null);
                    ($skipped ? $n->warning() : $n->success())->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('Could not read the file')->body($e->getMessage())->danger()->send();
                } finally {
                    Storage::disk('local')->delete((string) $data['file']);
                }
            });
    }

    /** v1.5 — one category's strategy: role, objective, room to grow, sales floor, most products. */
    public function strategyAction(): Action
    {
        return Action::make('strategy')->label('Edit')->size('xs')->color('gray')->outlined()
            ->modalHeading(fn (array $arguments) => 'Strategy for ' . ($arguments['category'] ?? 'this category'))
            ->modalDescription('How range plans treat this category. Applies from the next run.')
            ->fillForm(function (array $arguments) {
                $s = \App\Services\Assortment\CategoryStrategy::for(Filament::getTenant(), (string) ($arguments['category'] ?? ''));

                return ['role' => $s['role'], 'objective' => $s['objective'], 'room' => (string) (float) $s['room'],
                    'sales_floor' => $s['sales_floor'] === null ? '' : (string) (float) $s['sales_floor'], 'max_size' => $s['max_size']];
            })
            ->form([
                Select::make('role')->label('Role')->required()->options(\App\Services\Assortment\CategoryStrategy::ROLES),
                Select::make('objective')->label('Objective')->required()
                    ->options(collect(\App\Services\Assortment\CategoryStrategy::OBJECTIVES)->map(fn ($o) => $o['label'])->all()),
                Select::make('room')->label('Room to grow the range')->required()->options(\App\Services\Assortment\CategoryStrategy::ROOM_OPTIONS),
                Select::make('sales_floor')->label('Sales floor')->options(\App\Services\Assortment\CategoryStrategy::SALES_FLOORS)->placeholder('No floor'),
                TextInput::make('max_size')->label('Most products per store (optional)')->numeric()->minValue(1)->maxValue(5000)
                    ->helperText('Leave empty to use today\'s count plus the room above.'),
            ])
            ->action(function (array $data, array $arguments) {
                abort_unless(static::canAccess(), 403);
                \App\Services\Assortment\CategoryStrategy::save(Filament::getTenant(), (string) ($arguments['category'] ?? ''), $data);
                Notification::make()->title('Saved — plans use it from the next run')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        $uploads = app(AssortmentUploads::class);
        $tenant  = Filament::getTenant();

        return [
            Action::make('guardrails')->label('Guardrails')->icon('heroicon-o-shield-exclamation')->color('gray')
                ->fillForm(fn () => TenantAssortment::guardrails($tenant))
                ->form([
                    Select::make('delist_min_tier')->label('Propose a delist only when confidence is at least')->required()
                        ->options(['likely' => 'Likely (default)', 'established' => 'Established — fewer, surer delists']),
                    Select::make('carried_window_days')->label('A product counts as carried if it sold or was in stock in the last')->required()
                        ->options([28 => '28 days', 56 => '56 days (default)', 90 => '90 days']),
                    Select::make('max_delists_per_category')->label('Most delists per category per store in one run')->required()
                        ->options([1 => '1', 2 => '2', 3 => '3 (default)', 5 => '5', 10 => '10']),
                    Select::make('max_adds_per_category')->label('Most adds per category per store in one run')->required()
                        ->options([3 => '3', 5 => '5 (default)', 10 => '10', 20 => '20']),
                ])
                ->action(function (array $data) use ($tenant) {
                    TenantAssortment::update($tenant, ['guardrails' => [
                        'delist_min_tier'          => (string) $data['delist_min_tier'],
                        'carried_window_days'      => (int) $data['carried_window_days'],
                        'max_delists_per_category' => (int) $data['max_delists_per_category'],
                        'max_adds_per_category'    => (int) $data['max_adds_per_category'],
                    ]]);
                    Notification::make()->title('Guardrails saved — they apply from the next run')->success()->send();
                }),
            Action::make('add_must_stock')->label('Add must-stock product')->icon('heroicon-o-plus')
                ->form([
                    TextInput::make('sku')->label('SKU')->required()->maxLength(100)
                        ->rule(fn () => function ($attr, $value, $fail) {
                            if (! DB::table('products')->where('tenant_id', $this->tenantId())->where('sku', trim((string) $value))->exists()) {
                                $fail('No product with that SKU.');
                            }
                        }),
                    Select::make('store_id')->label('Store')->placeholder('Every store')->options(fn () => $this->storeOptions())->searchable(),
                    TextInput::make('reason')->maxLength(160)->placeholder('e.g. supplier contract, private label, regulatory'),
                ])
                ->action(function (array $data) use ($uploads) {
                    $uploads->addMustStock($this->tenantId(), (string) $data['sku'], $data['store_id'] ? (int) $data['store_id'] : null, $data['reason'] ?? null);
                    Notification::make()->title('Added to the must-stock list')->success()->send();
                }),
            $this->upload('Upload must-stock list', 'must_stock', function (string $path) use ($uploads) {
                $r = $uploads->importMustStock($this->tenantId(), $path);

                return [$r['added'] . ' product(s) on the must-stock list', $r['skipped']];
            }),
            $this->upload('Upload range file', 'range', function (string $path) use ($uploads) {
                $r = $uploads->importRange($this->tenantId(), $path);

                return ["Range file loaded: {$r['stores']} store(s), {$r['carried']} carried, {$r['not_carried']} not carried — used from the next run", $r['skipped']];
            }),
            Action::make('clear_range')->label('Clear range file')->icon('heroicon-o-trash')->color('danger')
                ->visible(fn () => AssortmentStoreRange::where('tenant_id', $this->tenantId())->where('source', AssortmentStoreRange::SOURCE_LISTING)->exists())
                ->requiresConfirmation()
                ->modalDescription('The range goes back to what sales and stock show, from the next run.')
                ->action(function () use ($uploads) {
                    $n = $uploads->clearRange($this->tenantId());
                    Notification::make()->title("{$n} range-file row(s) removed")->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $tenantId = $this->tenantId();
        $listing = DB::table('assortment_store_ranges')->where('tenant_id', $tenantId)->where('source', AssortmentStoreRange::SOURCE_LISTING)
            ->selectRaw('COUNT(DISTINCT store_id) AS stores, SUM(CASE WHEN carried THEN 1 ELSE 0 END) AS carried, SUM(CASE WHEN carried THEN 0 ELSE 1 END) AS not_carried, MAX(updated_at) AS at')
            ->first();

        return [
            'guardrails' => TenantAssortment::guardrails(Filament::getTenant()),
            'mustStock'  => AssortmentMustStock::where('tenant_id', $tenantId)->with('store:id,name')->orderBy('sku')->limit(500)->get(),
            'mustCount'  => AssortmentMustStock::where('tenant_id', $tenantId)->count(),
            'names'      => DB::table('products')->where('tenant_id', $tenantId)
                ->whereIn('sku', AssortmentMustStock::where('tenant_id', $tenantId)->limit(500)->pluck('sku'))->pluck('name', 'sku'),
            'listing'    => $listing,
            'categories' => collect(DB::select(
                "SELECT TRIM(p.category) AS cat, COUNT(DISTINCT r.sku) AS skus
                   FROM assortment_store_ranges r JOIN products p ON p.tenant_id = r.tenant_id AND p.sku = r.sku
                  WHERE r.tenant_id = ? AND r.carried AND p.category IS NOT NULL AND TRIM(p.category) <> ''
               GROUP BY 1 ORDER BY 1 LIMIT 300",
                [$tenantId],
            ))->map(fn ($r) => ['category' => (string) $r->cat, 'skus' => (int) $r->skus]
                + \App\Services\Assortment\CategoryStrategy::for(Filament::getTenant(), (string) $r->cat))->all(),
        ];
    }
}
