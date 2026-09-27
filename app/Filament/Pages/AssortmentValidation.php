<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Jobs\RunAssortmentJob;
use App\Models\AssortmentGap;
use App\Models\AssortmentRun;
use App\Models\Tenant;
use App\Services\Assortment\TenantAssortment;
use App\Services\Assortment\ValidationGate;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * A3.5 — the validation gate, in the app. A tenant admin (with their category
 * manager) goes through the top 50 decisions of each type and marks each one
 * sensible or not. When at least 70% of each type are sensible, "Go live"
 * opens the decisions to everyone. Until then nobody else sees them.
 */
class AssortmentValidation extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const ADMIN_ONLY = true;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Validation';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'assortment-validation';

    protected string $view = 'filament.pages.assortment-validation';

    #[Url(as: 'type')]
    public string $type = AssortmentGap::TYPE_ADD;

    public function getTitle(): string
    {
        return 'Assortment validation';
    }

    private function tenant(): Tenant
    {
        return Filament::getTenant();
    }

    public function review(int $id, string $verdict): void
    {
        $gap = AssortmentGap::where('tenant_id', $this->tenant()->id)->findOrFail($id);
        app(ValidationGate::class)->review($gap, auth()->user(), $verdict);
    }

    /** v1.5 — mark a sampled range plan sensible or not (the plan review). */
    public function reviewPlan(int $id, string $verdict): void
    {
        $plan = \App\Models\AssortmentPlan::where('tenant_id', $this->tenant()->id)->findOrFail($id);
        app(\App\Services\Assortment\PlanService::class)->review($plan, auth()->user(), $verdict);
    }

    protected function getHeaderActions(): array
    {
        $gate = app(ValidationGate::class);
        $plans = app(\App\Services\Assortment\PlanService::class);

        return [
            Action::make('run')->label('Run now')->icon('heroicon-o-arrow-path')->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Rebuilds the range, the comparison with similar stores and the decisions from the latest data. Takes a few minutes; decisions already reviewed keep their verdict.')
                ->action(function () {
                    RunAssortmentJob::dispatch((int) $this->tenant()->id);
                    Notification::make()->title('Run started — refresh this page in a few minutes')->success()->send();
                }),
            Action::make('live')->label('Go live')->icon('heroicon-o-rocket-launch')->color('success')
                ->visible(fn () => ! TenantAssortment::isLive($this->tenant()))
                ->disabled(fn () => ! $gate->status($this->tenant())['passed'])
                ->requiresConfirmation()
                ->modalDescription('Everyone with access to Assortment will see the decisions and can accept or reject them.')
                ->action(function () use ($gate) {
                    $ok = $gate->goLive($this->tenant(), auth()->user());
                    $ok
                        ? Notification::make()->title('Assortment is live')->success()->send()
                        : Notification::make()->title('The review has not passed yet')->warning()->send();
                }),
            Action::make('plans_live')->label('Open range plans')->icon('heroicon-o-rectangle-stack')->color('success')
                ->visible(fn () => TenantAssortment::isLive($this->tenant()) && ! TenantAssortment::plansLive($this->tenant()))
                ->disabled(fn () => ! $plans->gate($this->tenant())['passed'])
                ->requiresConfirmation()
                ->modalDescription('Everyone with access to Assortment will see the range plans and can accept or reject them.')
                ->action(function () use ($plans) {
                    $plans->goLive($this->tenant(), auth()->user())
                        ? Notification::make()->title('Range plans are open')->success()->send()
                        : Notification::make()->title('The plan review has not passed yet')->warning()->send();
                }),
            Action::make('plans_pause')->label('Plans back to review')->icon('heroicon-o-pause')->color('gray')
                ->visible(fn () => TenantAssortment::plansLive($this->tenant()))
                ->requiresConfirmation()
                ->modalDescription('Proposed range plans are hidden from users again. Accepted ones are kept.')
                ->action(function () use ($plans) {
                    $plans->pause($this->tenant(), auth()->user());
                    Notification::make()->title('Range plans hidden again')->success()->send();
                }),
            Action::make('pause')->label('Back to checking')->icon('heroicon-o-pause')->color('gray')
                ->visible(fn () => TenantAssortment::isLive($this->tenant()))
                ->requiresConfirmation()
                ->modalDescription('Open decisions are hidden from users again. Accepted and rejected ones are kept.')
                ->action(function () use ($gate) {
                    $gate->pause($this->tenant(), auth()->user());
                    Notification::make()->title('Decisions hidden again')->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $tenant = $this->tenant();
        $gate   = app(ValidationGate::class);
        if (! array_key_exists($this->type, AssortmentGap::TYPES)) {
            $this->type = AssortmentGap::TYPE_ADD;
        }

        return [
            'status'   => $gate->status($tenant),
            'sample'   => $gate->sample($tenant, $this->type),
            'live'     => TenantAssortment::isLive($tenant),
            'lastRun'  => AssortmentRun::where('tenant_id', $tenant->id)->latest('id')->first(),
            'currency' => $tenant->currencyCode(),
            'pass'     => (float) config('assortment.gate_pass', 0.70),
            'rules'    => app(\App\Services\Assortment\DecisionLearning::class)->performance((int) $tenant->id),
            'planGate' => app(\App\Services\Assortment\PlanService::class)->gate($tenant),
            'planSample' => app(\App\Services\Assortment\PlanService::class)->sample($tenant),
        ];
    }
}
