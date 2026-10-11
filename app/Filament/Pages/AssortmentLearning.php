<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Models\AssortmentCalibration;
use App\Models\Tenant;
use App\Services\Assortment\CalibrationService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * v1.5 Phase 5 — learning. What the measured results say about each rule
 * (expected against actual), the calibration in force, and the proposals
 * waiting for an admin. A calibration changes the rules only once approved,
 * from the next run; every decision and plan keeps the version it was made
 * under; retiring it puts the rules back as built.
 */
class AssortmentLearning extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const ADMIN_ONLY = true;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Learning';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'assortment-learning';

    protected string $view = 'filament.pages.assortment-learning';

    public function getTitle(): string
    {
        return 'Learning from results';
    }

    private function tenant(): Tenant
    {
        return Filament::getTenant();
    }

    private function find(array $arguments): AssortmentCalibration
    {
        return AssortmentCalibration::where('tenant_id', $this->tenant()->id)->findOrFail((int) ($arguments['id'] ?? 0));
    }

    public function approveAction(): Action
    {
        return Action::make('approve')->label('Approve')->color('success')->size('sm')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Approve ' . $this->find($arguments)->label())
            ->modalDescription('From the next run the engine applies it. Decisions and plans already made keep their figures; new ones carry this version. You can go back to the rules as built at any time.')
            ->form([Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(500)])
            ->action(function (array $data, array $arguments) {
                abort_unless(static::canAccess(), 403);
                $cal = $this->find($arguments);
                app(CalibrationService::class)->approve($cal, auth()->user(), $data['note'] ?? null);
                Notification::make()->title($cal->label() . ' approved — it applies from the next run')->success()->send();
            });
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')->label('Reject')->color('gray')->size('sm')
            ->modalHeading(fn (array $arguments) => 'Reject ' . $this->find($arguments)->label())
            ->form([Textarea::make('note')->label('Why')->required()->rows(2)->maxLength(500)])
            ->action(function (array $data, array $arguments) {
                abort_unless(static::canAccess(), 403);
                $cal = $this->find($arguments);
                app(CalibrationService::class)->reject($cal, auth()->user(), (string) $data['note']);
                Notification::make()->title($cal->label() . ' rejected — the rules stay as they are')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        $svc = app(CalibrationService::class);

        return [
            Action::make('check')->label('Check the results now')->icon('heroicon-o-arrow-path')->color('gray')
                ->action(function () use ($svc) {
                    $cal = $svc->propose($this->tenant());
                    $cal
                        ? Notification::make()->title('Proposal ' . $cal->label() . ' is waiting below')->success()->send()
                        : Notification::make()->title('Nothing to change')->body('Not enough measured results yet, or the rules are already in line with them.')->send();
                }),
            Action::make('retire')->label('Back to the rules as built')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                ->visible(fn () => CalibrationService::active((int) $this->tenant()->id) !== null)
                ->requiresConfirmation()
                ->modalDescription('The calibration in force stops applying from the next run. It stays in the history.')
                ->action(function () use ($svc) {
                    $cal = $svc->retire($this->tenant(), auth()->user());
                    Notification::make()->title(($cal?->label() ?? 'Calibration') . ' retired — the rules run as built from the next run')->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $tenantId = (int) $this->tenant()->id;

        return [
            'stats'     => app(CalibrationService::class)->stats($tenantId),
            'active'    => AssortmentCalibration::with('decider:id,name')->where('tenant_id', $tenantId)
                ->where('status', AssortmentCalibration::STATUS_APPROVED)->orderByDesc('version')->first(),
            'proposals' => AssortmentCalibration::where('tenant_id', $tenantId)->where('status', AssortmentCalibration::STATUS_PROPOSED)->orderByDesc('version')->get(),
            'history'   => AssortmentCalibration::with('decider:id,name')->where('tenant_id', $tenantId)
                ->whereNotIn('status', [AssortmentCalibration::STATUS_PROPOSED])->orderByDesc('version')->limit(20)->get(),
            'min'       => CalibrationService::MIN_MEASURED,
            // v2.5 — measured against estimated, one point per result.
            'points'    => app(CalibrationService::class)->points($tenantId),
            'currency'  => $this->tenant()->currencyCode(),
        ];
    }
}
