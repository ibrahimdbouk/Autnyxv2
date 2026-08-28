<x-filament-panels::page>
    @php
        $p = $this->getProfile();
        $t = $p['tenant'];
        $ccy = $p['currency'];
        $mny = fn ($v) => \App\Support\Money::compact((float) $v, $ccy);
        $v = $p['volumes'];
    @endphp

    <style>
        .tp-head { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; margin-bottom:1rem; }
        .tp-badge { display:inline-block; padding:.15rem .6rem; border-radius:9999px; font-size:.72rem; font-weight:700; }
        .tp-active { background:#dcfce7; color:#166534; } .tp-suspended { background:#fee2e2; color:#991b1b; }
        .tp-plan { background:#fef3c7; color:#92400e; }
        .tp-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:.9rem; }
        @media(min-width:900px){ .tp-grid{ grid-template-columns:repeat(4,1fr); } }
        .tp-tile { background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.85rem; padding:.9rem 1rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); }
        .tp-tile .lbl { font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-muted,#6b7280); }
        .tp-tile .val { font-size:1.35rem; font-weight:800; color:var(--ax-ink,#111827); margin-top:.15rem; }
        .tp-cols { display:grid; grid-template-columns:1fr; gap:1rem; margin-top:1.25rem; }
        @media(min-width:900px){ .tp-cols{ grid-template-columns:1fr 1fr; } }
        .tp-card { background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.9rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); overflow:hidden; }
        .tp-card h3 { font-size:.85rem; font-weight:700; color:var(--ax-ink,#111827); padding:.75rem 1rem; border-bottom:1px solid var(--ax-line-2,#f3f4f6); margin:0; }
        table.tp-tbl { width:100%; border-collapse:collapse; font-size:.8rem; }
        table.tp-tbl th { text-align:left; color:var(--ax-muted,#6b7280); font-size:.66rem; text-transform:uppercase; padding:.5rem .85rem; border-bottom:1px solid var(--ax-line,#e5e7eb); }
        table.tp-tbl td { padding:.5rem .85rem; border-bottom:1px solid var(--ax-line-2,#f3f4f6); color:var(--ax-text,#374151); }
        .tp-muted { color:var(--ax-muted,#6b7280); }
        .tp-num { font-variant-numeric:tabular-nums; }
    </style>

    <div class="tp-head">
        <span class="tp-badge tp-plan">{{ $t->planLabel() }}</span>
        <span class="tp-badge {{ $t->isActive() ? 'tp-active' : 'tp-suspended' }}">{{ ucfirst($t->status ?? 'active') }}</span>
        <span class="tp-muted">· {{ $ccy }} · created {{ $t->created_at?->format('M j, Y') }} · slug <code>{{ $t->slug }}</code></span>
        @if($p['sso']['enabled'])<span class="tp-badge" style="background:#dbeafe;color:#1e40af;">SSO: {{ $p['sso']['label'] }}</span>@endif
    </div>

    <div class="tp-grid">
        <div class="tp-tile"><div class="lbl">Users ({{ $v['admins'] }} admin)</div><div class="val tp-num">{{ number_format($v['users']) }}</div></div>
        <div class="tp-tile"><div class="lbl">Stores · SKUs</div><div class="val tp-num">{{ number_format($v['stores']) }} · {{ number_format($v['products']) }}</div></div>
        <div class="tp-tile"><div class="lbl">Active anomalies</div><div class="val tp-num">{{ number_format($p['anomalies']['active']) }}</div></div>
        <div class="tp-tile"><div class="lbl">Observed recovery</div><div class="val tp-num" style="color:#0d9488">{{ $mny($p['recovery']['observed_total']) }}</div></div>
    </div>

    <div class="tp-grid" style="margin-top:.9rem;">
        <div class="tp-tile"><div class="lbl">Sales rows</div><div class="val tp-num">{{ number_format($v['sales_rows']) }}</div></div>
        <div class="tp-tile"><div class="lbl">Inventory rows</div><div class="val tp-num">{{ number_format($v['inventory_rows']) }}</div></div>
        <div class="tp-tile"><div class="lbl">POs · suppliers</div><div class="val tp-num">{{ number_format($v['purchase_orders']) }} · {{ number_format($v['suppliers']) }}</div></div>
        <div class="tp-tile"><div class="lbl">Investigations (open)</div><div class="val tp-num">{{ number_format($p['investigations']['total']) }} ({{ number_format($p['investigations']['open']) }})</div></div>
    </div>

    <div class="tp-cols">
        <div class="tp-card">
            <h3>Users</h3>
            <table class="tp-tbl">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Last login</th></tr></thead>
                <tbody>
                    @forelse($p['users'] as $u)
                        <tr>
                            <td><strong>{{ $u->name }}</strong></td>
                            <td class="tp-muted">{{ $u->email }}</td>
                            <td>{{ $u->is_super_admin ? 'Super' : ($u->is_tenant_admin ? 'Admin' : 'User') }}</td>
                            <td class="tp-muted">{{ $u->last_login_at ? $u->last_login_at->diffForHumans() : 'never' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="tp-muted" style="text-align:center;padding:1rem;">No users.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="tp-card">
            <h3>Recent imports</h3>
            <table class="tp-tbl">
                <thead><tr><th>Type</th><th>Status</th><th>Rows</th><th>When</th></tr></thead>
                <tbody>
                    @forelse($p['recent_imports'] as $imp)
                        <tr>
                            <td>{{ $imp->data_type }}</td>
                            <td>{{ str_replace('_',' ', $imp->status) }}</td>
                            <td class="tp-num">{{ number_format($imp->total_rows) }}</td>
                            <td class="tp-muted">{{ \Illuminate\Support\Carbon::parse($imp->created_at)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="tp-muted" style="text-align:center;padding:1rem;">No imports yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="tp-card" style="margin-top:1rem;">
        <h3>Recent activity</h3>
        <table class="tp-tbl">
            <thead><tr><th>When</th><th>Event</th><th>Detail</th></tr></thead>
            <tbody>
                @forelse($p['recent_activity'] as $a)
                    <tr>
                        <td class="tp-muted">{{ \Illuminate\Support\Carbon::parse($a->created_at)->diffForHumans() }}</td>
                        <td>{{ $a->event_type }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($a->description, 80) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="tp-muted" style="text-align:center;padding:1rem;">No recorded activity.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
