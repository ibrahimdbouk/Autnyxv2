<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Models\AssortmentNewRange;
use App\Models\Tenant;
use App\Services\Assortment\NewStoreRanges;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * v2.3 — one starting range: per category, the products in the order they were
 * chosen and why (core, expected, kept), the metres they fill, and the sales
 * the similar stores suggest, as a range. Downloadable as a CSV for the team
 * that sets up the store.
 */
class AssortmentNewRangePage extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_new_stores';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $slug = 'assortment-new-range';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.assortment.new-range';

    #[Url(as: 'range')]
    public ?int $range = null;

    #[Url(as: 'category')]
    public ?string $category = null;

    public function record(): ?AssortmentNewRange
    {
        return $this->range ? AssortmentNewRange::with(['store:id,name', 'creator:id,name'])->where('tenant_id', Filament::getTenant()?->id)->find($this->range) : null;
    }

    public function mount(): void
    {
        abort_if($this->range && $this->record() === null, 404);
    }

    public function getTitle(): string
    {
        return $this->record()?->name ?? 'Starting range';
    }

    protected function getHeaderActions(): array
    {
        if ($this->record() === null) {
            return [];
        }

        return [
            Action::make('csv')->label('Download CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(function () {
                    $r = $this->record();

                    return response()->streamDownload(function () use ($r) {
                        $out = fopen('php://output', 'w');
                        fputcsv($out, ['category', 'sku', 'name', 'subcategory', 'action', 'band', 'similar stores carrying', 'sales a year low', 'sales a year typical', 'sales a year high', 'metres']);
                        foreach ((array) $r->result as $cat => $c) {
                            foreach (array_merge($c['products'], $c['remove'] ?? []) as $p) {
                                fputcsv($out, [$cat, $p['sku'], $p['name'], $p['sub'], $p['state'], $p['band'], $p['carrying'], $p['low'], $p['mid'], $p['high'], $p['metres'] ?? '']);
                            }
                        }
                        fclose($out);
                    }, 'range-' . $r->id . '.csv', ['Content-Type' => 'text/csv']);
                }),
        ];
    }

    protected function getViewData(): array
    {
        $r = $this->record();
        if ($r === null) {
            return ['r' => null, 'backUrl' => AssortmentNewStores::getUrl()];
        }
        $cats = array_keys((array) $r->result);
        $this->category ??= $cats[0] ?? null;

        return [
            'r'        => $r,
            'cats'     => $cats,
            'c'        => $this->category ? ($r->result[$this->category] ?? null) : null,
            'currency' => Filament::getTenant()?->currencyCode(),
            'own'      => app(NewStoreRanges::class)->ownHistory($r),
            'backUrl'  => AssortmentNewStores::getUrl(),
        ];
    }
}
