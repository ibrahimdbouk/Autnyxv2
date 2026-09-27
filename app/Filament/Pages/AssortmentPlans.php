<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Models\AssortmentPlan;
use App\Models\Tenant;
use App\Services\Assortment\TenantAssortment;
use App\Services\Org\OrgDirectory;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * v1.5 Phase 2 — range plans: one per store × category, the engine's decisions
 * chosen together (current range → proposed range, with the changes and what
 * they are expected to do). Proposed plans are shown once the plan review has
 * passed; admins see them in review before that.
 */
class AssortmentPlans extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_plans';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Range Plans';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'assortment-plans';

    protected string $view = 'filament.pages.assortment-plans';

    public const TABS = [
        'open'     => 'To decide',
        'accepted' => 'Accepted',
        'measured' => 'Done & measured',
        'rejected' => 'Rejected',
    ];

    #[Url(as: 'tab')]
    public string $tab = 'open';

    public function getTitle(): string
    {
        return 'Range plans';
    }

    /** Plans this person may see: their stores, and drafts only for admins. */
    public static function scoped(): Builder
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        $q = AssortmentPlan::query()->with(['store:id,name', 'assignee:id,name'])->where('tenant_id', $tenant?->id);
        if (! $user?->canManageUsers()) {
            $q->where('status', '<>', AssortmentPlan::STATUS_DRAFT);
        }
        $scope = $user ? app(OrgDirectory::class)->storeScope($user) : null;
        if ($scope !== null) {
            $q->whereIn('store_id', $scope);
        }

        return $q;
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $statuses = match ($this->tab) {
            'accepted' => [AssortmentPlan::STATUS_ACCEPTED],
            'measured' => [AssortmentPlan::STATUS_IN_PROGRESS, AssortmentPlan::STATUS_MEASURED],
            'rejected' => [AssortmentPlan::STATUS_REJECTED],
            default    => [AssortmentPlan::STATUS_PROPOSED, AssortmentPlan::STATUS_DRAFT],
        };
        $rows = static::scoped()->whereIn('status', $statuses)
            ->orderByRaw('feasible DESC')->orderByRaw('value_mid * confidence DESC')->orderBy('id')->limit(300)->get();

        return [
            'rows'     => $rows,
            'live'     => TenantAssortment::plansLive($tenant),
            'currency' => $tenant?->currencyCode(),
            'counts'   => collect(self::TABS)->map(fn ($l, $k) => static::scoped()->whereIn('status', match ($k) {
                'accepted' => [AssortmentPlan::STATUS_ACCEPTED],
                'measured' => [AssortmentPlan::STATUS_IN_PROGRESS, AssortmentPlan::STATUS_MEASURED],
                'rejected' => [AssortmentPlan::STATUS_REJECTED],
                default    => [AssortmentPlan::STATUS_PROPOSED, AssortmentPlan::STATUS_DRAFT],
            })->count())->all(),
        ];
    }
}
