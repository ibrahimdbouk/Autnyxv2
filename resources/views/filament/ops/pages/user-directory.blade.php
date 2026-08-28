<x-filament-panels::page>
    @php
        $rows = $this->getRows();
        $role = fn ($r) => $r->is_super_admin ? 'Super Admin' : ($r->is_tenant_admin ? 'Tenant Admin' : 'User');
        $roleColor = fn ($r) => $r->is_super_admin ? '#dc2626' : ($r->is_tenant_admin ? '#b45309' : '#6b7280');
    @endphp

    <style>
        .ud-search { width:100%; max-width:360px; padding:.6rem .85rem; border-radius:10px; border:1px solid var(--ax-line,#d1d5db); font-size:.9rem; background:var(--ax-bg,#fff); color:var(--ax-ink,#111827); margin-bottom:1rem; }
        .ud-card { background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.9rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); overflow:hidden; }
        table.ud-tbl { width:100%; border-collapse:collapse; font-size:.82rem; }
        table.ud-tbl th { text-align:left; color:var(--ax-muted,#6b7280); font-size:.68rem; text-transform:uppercase; padding:.55rem .9rem; border-bottom:1px solid var(--ax-line,#e5e7eb); }
        table.ud-tbl td { padding:.55rem .9rem; border-bottom:1px solid var(--ax-line-2,#f3f4f6); color:var(--ax-text,#374151); }
        .ud-role { display:inline-block; padding:.1rem .5rem; border-radius:6px; font-size:.68rem; font-weight:700; color:#fff; }
        .ud-muted { color:var(--ax-muted,#6b7280); }
        .ud-scroll { overflow-x:auto; }
    </style>

    <input type="text" class="ud-search" placeholder="Search name, email, or tenant…" wire:model.live.debounce.400ms="search">

    <div class="ud-card">
        <div class="ud-scroll">
            <table class="ud-tbl">
                <thead><tr><th>Name</th><th>Email</th><th>Tenant</th><th>Role</th><th>Last login</th></tr></thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr>
                            <td><strong>{{ $r->name }}</strong></td>
                            <td class="ud-muted">{{ $r->email }}</td>
                            <td>{{ $r->tenant_name ?? '—' }}</td>
                            <td><span class="ud-role" style="background:{{ $roleColor($r) }}">{{ $role($r) }}</span></td>
                            <td class="ud-muted">{{ $r->last_login_at ? \Illuminate\Support\Carbon::parse($r->last_login_at)->diffForHumans() : 'never' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ud-muted" style="text-align:center;padding:2rem;">No users match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
