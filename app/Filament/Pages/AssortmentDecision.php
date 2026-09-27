<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Filament\Resources\AssortmentDecisionResource;
use App\Filament\Support\AssortmentDecisionActions;
use App\Models\AssortmentGap;
use App\Models\Tenant;
use App\Services\Assortment\PeerDetail;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * One range decision: the headline, why (evidence + the peer stores behind
 * it), the value range and confidence, and what happened to it (task, result).
 * Reached from the Decisions list; not in the menu.
 */
class AssortmentDecision extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_decisions';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $slug = 'assortment-decision';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.assortment-decision';

    #[Url(as: 'decision')]
    public ?int $decision = null;

    public function getTitle(): string
    {
        return AssortmentGap::TYPES[$this->gap()?->type] ?? 'Range decision';
    }

    public function gap(): ?AssortmentGap
    {
        if (! $this->decision) {
            return null;
        }
        $gap = AssortmentDecisionResource::getEloquentQuery()->with(['decider:id,name'])->find($this->decision);

        return $gap;
    }

    public function mount(): void
    {
        // No decision chosen: the page says so and links to the list.
        abort_if($this->decision && $this->gap() === null, 404);
    }

    protected function getHeaderActions(): array
    {
        $gap = $this->gap();
        if ($gap === null) {
            return [];
        }

        return array_map(fn ($a) => $a->record($gap)->after(fn () => $this->dispatch('$refresh')), [
            AssortmentDecisionActions::accept(),
            AssortmentDecisionActions::reject(),
            AssortmentDecisionActions::done(),
            AssortmentDecisionActions::cancel(),
        ]);
    }

    protected function getViewData(): array
    {
        $gap = $this->gap();
        if ($gap === null) {
            return ['gap' => null, 'peers' => [], 'currency' => null, 'backUrl' => AssortmentDecisionResource::getUrl('index')];
        }

        return [
            'gap'      => $gap,
            'peers'    => $gap ? app(PeerDetail::class)->for($gap) : [],
            'currency' => Filament::getTenant()?->currencyCode(),
            'backUrl'  => AssortmentDecisionResource::getUrl('index'),
            // Stockout-hidden is a stock problem: what other apps (Root Cause) have open on it, through the platform.
            'links'    => $gap->type === AssortmentGap::TYPE_STOCKOUT_HIDDEN
                ? app(\App\Platform\Linking\SubjectLinks::class)->for((int) $gap->tenant_id, (int) $gap->store_id, $gap->sku, exceptApp: Tenant::APP_ASSORTMENT)
                : [],
            'rootCause' => Filament::getTenant()?->hasApp(Tenant::APP_ROOT_CAUSE) ?? false,
            'lifecycleLabels' => \App\Platform\Intelligence\Lifecycle\ProductLifecycleService::STATES,
        ];
    }
}
