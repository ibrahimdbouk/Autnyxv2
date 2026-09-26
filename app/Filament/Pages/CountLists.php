<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Models\CycleCount;
use App\Models\Product;
use App\Models\Store;
use App\Services\Counts\CycleCountService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * W11 — cycle-count lists. For each store, the positions whose stock figure
 * the system doubts (phantom, shrink, negative), ranked by the money behind
 * the doubt. Count on screen, or download the list and upload it filled in.
 * A count on a live finding becomes a completed action, so the measurement
 * follows it.
 */
class CountLists extends Page
{
    use GatesPageByScreen;
    use \App\Filament\Concerns\SanitizesUrlState;

    const SCREEN_KEY = 'count_lists';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static \UnitEnum|string|null $navigationGroup = 'Root Cause';

    protected static ?string $navigationLabel = 'Count Lists';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'count-lists';

    protected string $view = 'filament.pages.count-lists';

    #[Url]
    public $store = '';

    /** @var array<int|string,mixed> count id => counted quantity typed on screen */
    public array $counted = [];

    protected function urlRules(): array
    {
        return ['store' => 'int'];
    }

    public function getTitle(): string
    {
        return 'Count Lists';
    }

    public static function getNavigationBadge(): ?string
    {
        $t = Filament::getTenant()?->id;
        $q = CycleCount::where('tenant_id', $t)->where('status', CycleCount::STATUS_OPEN);
        $scope = auth()->user()?->storeScope();
        $n = $t ? ($scope === null ? $q : $q->whereIn('store_id', $scope))->count() : 0;

        return $n > 0 ? (string) $n : null;
    }

    private function tenantId(): int
    {
        return (int) Filament::getTenant()?->id;
    }

    /** W12: a store manager sees and counts their own store(s) only; admins every store. */
    private function scoped($query)
    {
        $scope = auth()->user()?->storeScope();

        return $scope === null ? $query : $query->whereIn('store_id', $scope);
    }

    /** @return array<int,array{id:int,name:string,open:int,value:float}> stores with open counts */
    public function stores(): array
    {
        $rows = $this->scoped(CycleCount::where('tenant_id', $this->tenantId())->where('status', CycleCount::STATUS_OPEN))
            ->selectRaw('store_id, COUNT(*) AS n, SUM(value_at_risk) AS v')->groupBy('store_id')->get()->keyBy('store_id');
        $names = Store::where('tenant_id', $this->tenantId())->whereIn('id', $rows->keys())->pluck('name', 'id');

        return $rows->map(fn ($r, $id) => ['id' => (int) $id, 'name' => (string) ($names[$id] ?? "Store #{$id}"), 'open' => (int) $r->n, 'value' => (float) $r->v])
            ->sortByDesc('value')->values()->all();
    }

    public function currentStoreId(): ?int
    {
        $stores = $this->stores();
        $ids = array_column($stores, 'id');
        $scope = auth()->user()?->storeScope();
        if ($this->store !== '' && $this->store !== null && ($scope === null || in_array((int) $this->store, $scope, true))
            && Store::where('tenant_id', $this->tenantId())->whereKey((int) $this->store)->exists()) {
            return (int) $this->store;
        }

        return $ids[0] ?? null;
    }

    /** @return \Illuminate\Support\Collection<int,CycleCount> */
    public function openCounts()
    {
        $sid = $this->currentStoreId();
        if (! $sid) {
            return collect();
        }

        return CycleCount::where('tenant_id', $this->tenantId())->where('store_id', $sid)
            ->where('status', CycleCount::STATUS_OPEN)->orderBy('rank')->orderByDesc('value_at_risk')->get();
    }

    /** @return \Illuminate\Support\Collection<int,CycleCount> */
    public function recentCounts()
    {
        return $this->scoped(CycleCount::where('tenant_id', $this->tenantId())->where('status', CycleCount::STATUS_COUNTED))
            ->when($this->currentStoreId(), fn ($q, $sid) => $q->where('store_id', $sid))
            ->where('counted_at', '>=', now()->subDays(30))->orderByDesc('counted_at')->limit(50)->get();
    }

    /** @return array<string,string> sku => name */
    public function productNames(iterable $counts): array
    {
        $skus = collect($counts)->pluck('sku')->unique()->values()->all();

        return $skus ? Product::where('tenant_id', $this->tenantId())->whereIn('sku', $skus)->pluck('name', 'sku')->all() : [];
    }

    /** Cards and headings: the currency sign (e.g. ⃃). */
    public function money(float $v): string
    {
        return Money::displayCompact($v, Money::normalize(Filament::getTenant()?->currency));
    }

    /** Table cells: the ISO code (e.g. AED). */
    public function tableMoney(float $v): string
    {
        return Money::compact($v, Money::normalize(Filament::getTenant()?->currency));
    }

    /** Save the quantities typed on screen (any row with a value). */
    public function saveCounts(): void
    {
        $svc = app(CycleCountService::class);
        $saved = 0;
        $errors = [];
        foreach ($this->counted as $id => $qty) {
            if ($qty === null || $qty === '' || ! is_scalar($qty)) {
                continue;
            }
            $count = $this->scoped(CycleCount::where('tenant_id', $this->tenantId())->where('status', CycleCount::STATUS_OPEN))->find((int) $id);
            if (! $count) {
                continue;
            }
            $q = str_replace(',', '', trim((string) $qty));
            if (! is_numeric($q) || (float) $q < 0) {
                $errors[] = "SKU {$count->sku}: \"{$qty}\" is not a count.";
                continue;
            }
            $svc->record($count, (float) $q, auth()->user(), 'app');
            unset($this->counted[$id]);
            $saved++;
        }
        $n = Notification::make()->title($saved . ' count' . ($saved === 1 ? '' : 's') . ' saved');
        ($errors ? $n->body(implode("\n", $errors))->warning() : $n->success())->send();
    }

    public function download(): StreamedResponse
    {
        $sid = $this->currentStoreId();
        $store = $sid ? Store::find($sid) : null;
        $counts = $this->openCounts();
        $names = $this->productNames($counts);
        \App\Support\ExportAudit::log($this->tenantId(), 'count list', 'csv');

        return response()->streamDownload(function () use ($counts, $names, $store) {
            $out = fopen('php://output', 'w');
            \App\Support\ExportAudit::putcsv($out, ['store', 'sku', 'product', 'reason', 'system_qty', 'counted_qty']);
            foreach ($counts as $c) {
                \App\Support\ExportAudit::putcsv($out, [
                    $store?->code ?: $store?->name, $c->sku, $names[$c->sku] ?? '', $c->reasonLabel(),
                    $c->system_qty !== null ? rtrim(rtrim(number_format($c->system_qty, 2, '.', ''), '0'), '.') : '', '',
                ]);
            }
            fclose($out);
        }, 'count-list-' . \Illuminate\Support\Str::slug($store?->name ?? 'store') . '-' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Upload counts (CSV)')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalDescription('The downloaded list with the counted_qty column filled in. Rows left blank are skipped.')
                ->form([
                    FileUpload::make('file')->label('CSV file')->disk('local')->directory('count-uploads')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])->maxSize(5120)->required(),
                ])
                ->action(function (array $data) {
                    $path = Storage::disk('local')->path((string) $data['file']);
                    try {
                        $r = app(CycleCountService::class)->importCsv($this->tenantId(), $path, $this->currentStoreId(), auth()->user(), auth()->user()?->storeScope());
                        $n = Notification::make()->title($r['recorded'] . ' count(s) recorded')
                            ->body($r['skipped'] ? implode("\n", array_slice($r['skipped'], 0, 10)) : null);
                        ($r['skipped'] ? $n->warning() : $n->success())->send();
                    } catch (\InvalidArgumentException|\RuntimeException $e) {
                        Notification::make()->title('Could not read the file')->body($e->getMessage())->danger()->send();
                    } finally {
                        Storage::disk('local')->delete((string) $data['file']);
                    }
                }),
            Action::make('refresh')
                ->label('Refresh lists')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => auth()->user()?->canDismissAnomalies() ?? false)
                ->action(function () {
                    $r = app(CycleCountService::class)->generate($this->tenantId());
                    Notification::make()->title("Lists refreshed: {$r['added']} added, {$r['cancelled']} cleared")->success()->send();
                }),
        ];
    }
}
