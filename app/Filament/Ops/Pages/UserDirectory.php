<?php

namespace App\Filament\Ops\Pages;

use Illuminate\Support\Facades\DB;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Ops — a cross-tenant directory of who has access where, with role and last
 * login. Answers "who can get into which tenant?" at a glance.
 */
class UserDirectory extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'User Directory';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.ops.pages.user-directory';

    #[Url]
    public ?string $search = null;

    public function getTitle(): string
    {
        return 'User Directory';
    }

    /** @return array<int,object> */
    public function getRows(): array
    {
        $q = DB::table('users')
            ->leftJoin('tenants', 'tenants.id', '=', 'users.tenant_id')
            ->select([
                'users.name', 'users.email', 'users.is_super_admin', 'users.is_tenant_admin',
                'users.last_login_at', 'tenants.name as tenant_name',
            ])
            ->orderByDesc('users.is_super_admin')
            ->orderByDesc('users.is_tenant_admin')
            ->orderBy('tenants.name')
            ->orderBy('users.name')
            ->limit(500);

        if (! empty($this->search)) {
            $term = '%' . strtolower($this->search) . '%';
            $q->where(function ($w) use ($term) {
                $w->whereRaw('LOWER(users.name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(users.email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(tenants.name) LIKE ?', [$term]);
            });
        }

        return $q->get()->all();
    }

    public function updatedSearch(): void
    {
        // reactive via wire:model.live
    }
}
