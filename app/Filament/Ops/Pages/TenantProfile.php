<?php

namespace App\Filament\Ops\Pages;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Ops\TenantUsageService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Ops — per-tenant deep-dive: full profile plus lifecycle actions
 * (suspend / reactivate, change plan, enter).
 */
class TenantProfile extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $slug = 'tenant-profile';

    protected string $view = 'filament.ops.pages.tenant-profile';

    #[Url]
    public ?int $tenant = null;

    public ?Tenant $record = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false; // reached from the tenants list
    }

    public function mount(): void
    {
        abort_unless($this->tenant, 404);
        $this->record = Tenant::findOrFail($this->tenant);
    }

    public function getTitle(): string
    {
        return $this->record?->name ?? 'Tenant';
    }

    /** @return array<string,mixed> */
    public function getProfile(): array
    {
        return app(TenantUsageService::class)->forTenant($this->record);
    }

    public static function urlFor(int $tenantId): string
    {
        return static::getUrl(['tenant' => $tenantId]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enter')
                ->label('Enter')
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->url(fn () => route('ops.impersonate', ['tenant' => $this->record->id]))
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Sign in as an admin of ' . $this->record->name . '? Recorded in the audit log.'),

            Action::make('changePlan')
                ->label('Change plan')
                ->icon('heroicon-o-tag')
                ->form([
                    Select::make('plan')->options(Tenant::PLAN_LABELS)->default(fn () => $this->record->plan)->required(),
                ])
                ->action(function (array $data): void {
                    $old = $this->record->plan;
                    $this->record->update(['plan' => $data['plan']]);
                    $this->audit('Plan: ' . $old . ' → ' . $data['plan']);
                    Notification::make()->title('Plan updated')->success()->send();
                }),

            Action::make('toggleStatus')
                ->label(fn () => $this->record->isActive() ? 'Suspend' : 'Reactivate')
                ->icon(fn () => $this->record->isActive() ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                ->color(fn () => $this->record->isActive() ? 'danger' : 'success')
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->record->isActive()
                    ? 'Suspend ' . $this->record->name . '? Its non-super users will be locked out until reactivated.'
                    : 'Reactivate ' . $this->record->name . '?')
                ->action(function (): void {
                    $new = $this->record->isActive() ? Tenant::STATUS_SUSPENDED : Tenant::STATUS_ACTIVE;
                    $this->record->update(['status' => $new]);
                    $this->audit('Status → ' . $new);
                    Notification::make()->title('Tenant ' . $new)->success()->send();
                }),
        ];
    }

    private function audit(string $what): void
    {
        try {
            AuditLog::create([
                'tenant_id'   => $this->record->id,
                'user_id'     => auth()->id(),
                'event_type'  => AuditLog::EVENT_TENANT_UPDATED,
                'description' => $this->record->name . ' — ' . $what,
            ]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}
