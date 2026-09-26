<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Filament\Resources\AnomalyResource;
use App\Models\Import;
use App\Services\Fresh\FreshExpiryService;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * W11 — Fresh & Expiry: stock about to expire, what of it will not sell in
 * time, and waste (how much, why, where, which categories). Read-only; the
 * findings themselves (expiry_risk, waste_rate) flow into investigations.
 */
class FreshExpiry extends Page
{
    use GatesPageByScreen;

    const SCREEN_KEY = 'fresh_expiry';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Fresh & Expiry';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'fresh-expiry';

    protected string $view = 'filament.pages.fresh-expiry';

    public function getTitle(): string
    {
        return 'Fresh & Expiry';
    }

    public function data(): array
    {
        $t = (int) Filament::getTenant()?->id;

        return Cache::remember("fresh-expiry:{$t}", 120, fn () => app(FreshExpiryService::class)->summary($t));
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

    public function anomaliesUrl(string $rule): ?string
    {
        return AnomalyResource::canViewAny() ? AnomalyResource::getUrl('index', ['filters' => ['rule_type' => ['value' => $rule]]]) : null;
    }

    public function investigateUrl(int $anomalyId): ?string
    {
        return AnomalyResource::canViewAny() ? AnomalyResource::getUrl('investigate', ['record' => $anomalyId]) : null;
    }

    public function templateUrl(string $type): string
    {
        return route('import-template', ['type' => $type]);
    }

    public function wasteType(): string
    {
        return Import::TYPE_WASTE;
    }
}
