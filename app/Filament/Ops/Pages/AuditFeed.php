<?php

namespace App\Filament\Ops\Pages;

use Illuminate\Support\Facades\DB;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Ops — cross-tenant security & audit oversight: a live feed of logins, SSO,
 * impersonation, exports, and access/role changes, filterable by event type.
 */
class AuditFeed extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Audit Feed';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.ops.pages.audit-feed';

    #[Url]
    public ?string $event = null;

    public function getTitle(): string
    {
        return 'Audit Feed';
    }

    /** Quick-filter chips for the security-relevant events. */
    public function getEventOptions(): array
    {
        return [
            ''                       => 'All events',
            'sso_login'              => 'SSO logins',
            'login_failed'           => 'Failed logins',
            'impersonation_started'  => 'Impersonation',
            'data_exported'          => 'Exports',
            'screen_access_changed'  => 'Access changes',
            'tenant_created'         => 'Tenant created',
        ];
    }

    /** @return array<int,object> most recent audit rows (with tenant + user names) */
    public function getRows(): array
    {
        $q = DB::table('audit_logs')
            ->leftJoin('tenants', 'tenants.id', '=', 'audit_logs.tenant_id')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select([
                'audit_logs.event_type',
                'audit_logs.description',
                'audit_logs.created_at',
                'tenants.name as tenant_name',
                'users.email as user_email',
            ])
            ->orderByDesc('audit_logs.created_at')
            ->limit(300);

        if (! empty($this->event)) {
            $q->where('audit_logs.event_type', $this->event);
        }

        return $q->get()->all();
    }

    public function setEvent(?string $event): void
    {
        $this->event = $event ?: null;
    }
}
