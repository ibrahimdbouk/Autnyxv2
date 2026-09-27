<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Filament\Support\AssortmentDecisionActions;
use App\Models\AssortmentPlan;
use App\Models\Tenant;
use App\Services\Assortment\PlanService;
use App\Services\Assortment\TenantAssortment;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * v1.5 Phase 2 — one range plan: the store × category as it is and as
 * proposed, each change chosen together with the others and why, what was left
 * out and why, the limits it respects, and what it is expected to do (as
 * ranges, labelled estimated). Accept the whole plan — unticking any change —
 * as one reset task; reject it with a reason; mark it done; see it measured.
 */
class AssortmentPlanPage extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_plans';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $slug = 'assortment-plan';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.assortment-plan';

    #[Url(as: 'plan')]
    public ?int $plan = null;

    /** @var array<int,string> change keys unticked before accepting */
    public array $unticked = [];

    public function getTitle(): string
    {
        return 'Range plan';
    }

    public function record(): ?AssortmentPlan
    {
        return $this->plan ? AssortmentPlans::scoped()->with(['decider:id,name', 'creator:id,name', 'scenario:id,name'])->find($this->plan) : null;
    }

    public function mount(): void
    {
        abort_if($this->plan && $this->record() === null, 404);
    }

    public function toggle(string $key): void
    {
        $this->unticked = in_array($key, $this->unticked, true)
            ? array_values(array_diff($this->unticked, [$key]))
            : [...$this->unticked, $key];
    }

    protected function getHeaderActions(): array
    {
        $plan = $this->record();
        if ($plan === null) {
            return [];
        }
        $svc = app(PlanService::class);

        return [
            Action::make('accept')->label('Accept plan')->icon('heroicon-o-check')->color('success')
                ->visible(fn () => $plan->status === AssortmentPlan::STATUS_PROPOSED && $plan->feasible)
                ->modalHeading('Accept this range plan')
                ->modalDescription(fn () => 'It becomes one reset task for the store'
                    . ($this->unticked ? ' (' . count($this->unticked) . ' change(s) unticked are left out)' : '')
                    . '. Make the changes in your own merchandising system; mark the plan done when the shelf is reset, and the category is measured 8 weeks later against similar stores.')
                ->form([
                    Select::make('assignee_id')->label('Who does the reset')->options(fn () => AssortmentDecisionActions::people())->searchable()
                        ->placeholder('The store manager, if one is set'),
                    DatePicker::make('due_at')->label('Due')->default(now()->addDays(14))->minDate(today()),
                    Textarea::make('note')->rows(2)->maxLength(500),
                ])
                ->action(function (array $data) use ($svc, $plan) {
                    $svc->accept($plan, auth()->user(), $data['assignee_id'] ?? null, $data['due_at'] ?? null, $data['note'] ?? null, $this->unticked);
                    $this->unticked = [];
                    Notification::make()->title('Plan accepted — it is now a reset task')->success()->send();
                }),
            Action::make('reject')->label('Reject plan')->icon('heroicon-o-x-mark')->color('gray')
                ->visible(fn () => $plan->status === AssortmentPlan::STATUS_PROPOSED)
                ->form([
                    Select::make('reason')->label('Why')->required()->options([
                        'The changes do not fit our category strategy' => 'The changes do not fit our category strategy',
                        'No space or no fixture for it'                => 'No space or no fixture for it',
                        'Local shoppers differ'                        => 'Local shoppers differ',
                        'Supplier or pack not available'               => 'Supplier or pack not available',
                        'The figures look wrong'                       => 'The figures look wrong',
                        'Other'                                        => 'Other',
                    ]),
                    Textarea::make('note')->rows(2)->maxLength(400),
                ])
                ->action(function (array $data) use ($svc, $plan) {
                    $svc->reject($plan, auth()->user(), trim($data['reason'] . (($data['note'] ?? '') ? ': ' . $data['note'] : '')));
                    Notification::make()->title('Plan rejected — this change set is not proposed again')->success()->send();
                }),
            Action::make('done')->label('Mark reset done')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn () => $plan->status === AssortmentPlan::STATUS_ACCEPTED)
                ->form([DatePicker::make('done_on')->label('Done on')->default(today())->maxDate(today())->required()])
                ->action(function (array $data) use ($svc, $plan) {
                    $svc->markDone($plan, auth()->user(), $data['done_on'] ?? null);
                    Notification::make()->title('Done — the category is measured in 8 weeks')->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $plan = $this->record();
        $tenant = Filament::getTenant();

        return [
            'p'        => $plan,
            'currency' => $tenant?->currencyCode(),
            'live'     => $tenant ? TenantAssortment::plansLive($tenant) : false,
            'backUrl'  => AssortmentPlans::getUrl(),
            'studioUrl' => $plan && AssortmentStudio::canAccess() && AssortmentStudio::opensFor() ? AssortmentStudio::getUrl(['store' => $plan->store_id, 'category' => $plan->category]) : null,
        ];
    }
}
