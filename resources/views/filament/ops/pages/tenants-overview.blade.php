<x-filament-panels::page>
    @php
        $overview = $this->getOverview();
        $tenants  = $this->getTenants();
    @endphp

    <style>
        .ops-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:.9rem; }
        @media(min-width:900px){ .ops-grid{ grid-template-columns:repeat(5,1fr); } }
        .ops-tile { background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.85rem; padding:1rem 1.1rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); }
        .ops-tile .lbl { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-muted,#6b7280); }
        .ops-tile .val { font-size:1.7rem; font-weight:800; color:var(--ax-ink,#111827); margin-top:.2rem; }
        .ops-card { margin-top:1.25rem; background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.9rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); overflow:hidden; }
        table.ops-tbl { width:100%; border-collapse:collapse; font-size:.82rem; }
        table.ops-tbl th { text-align:left; color:var(--ax-muted,#6b7280); font-size:.68rem; text-transform:uppercase; letter-spacing:.03em; padding:.6rem .8rem; border-bottom:1px solid var(--ax-line,#e5e7eb); white-space:nowrap; }
        table.ops-tbl td { padding:.6rem .8rem; border-bottom:1px solid var(--ax-line-2,#f3f4f6); color:var(--ax-text,#374151); white-space:nowrap; }
        table.ops-tbl tr:last-child td { border-bottom:0; }
        .ops-name { font-weight:700; color:var(--ax-ink,#111827); }
        .ops-badge { display:inline-block; padding:.12rem .5rem; border-radius:9999px; font-size:.68rem; font-weight:700; }
        .ops-badge.active { background:#dcfce7; color:#166534; }
        .ops-badge.suspended { background:#fee2e2; color:#991b1b; }
        .ops-badge.plan { background:#fef3c7; color:#92400e; }
        .ops-actions a { color:var(--ax-accent-600,#b45309); font-weight:600; text-decoration:none; margin-right:.75rem; }
        .ops-scroll { overflow-x:auto; }
        .ops-num { font-variant-numeric:tabular-nums; }
    </style>

    <div class="ops-grid">
        <div class="ops-tile"><div class="lbl">Tenants</div><div class="val ops-num">{{ number_format($overview['tenants']) }}</div></div>
        <div class="ops-tile"><div class="lbl">Active</div><div class="val ops-num" style="color:#16a34a">{{ number_format($overview['active']) }}</div></div>
        <div class="ops-tile"><div class="lbl">Suspended</div><div class="val ops-num" style="color:#dc2626">{{ number_format($overview['suspended']) }}</div></div>
        <div class="ops-tile"><div class="lbl">Users</div><div class="val ops-num">{{ number_format($overview['users']) }}</div></div>
        <div class="ops-tile"><div class="lbl">Active anomalies</div><div class="val ops-num">{{ number_format($overview['anomalies_active']) }}</div></div>
    </div>

    <div class="ops-card">
        <div class="ops-scroll">
            <table class="ops-tbl">
                <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Plan</th>
                        <th>Status</th>
                        <th>Users</th>
                        <th>Products</th>
                        <th>Sales rows</th>
                        <th>Inventory</th>
                        <th>Active anomalies</th>
                        <th>Observed recovery</th>
                        <th>Last ingestion</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tenants as $t)
                        <tr>
                            <td><span class="ops-name">{{ $t['name'] }}</span><br><span style="color:var(--ax-muted,#6b7280);font-size:.72rem;">{{ $t['slug'] }}</span></td>
                            <td><span class="ops-badge plan">{{ $t['plan'] }}</span></td>
                            <td><span class="ops-badge {{ $t['status'] }}">{{ ucfirst($t['status']) }}</span></td>
                            <td class="ops-num">{{ number_format($t['users']) }}</td>
                            <td class="ops-num">{{ number_format($t['products']) }}</td>
                            <td class="ops-num">{{ number_format($t['sales_rows']) }}</td>
                            <td class="ops-num">{{ number_format($t['inventory_rows']) }}</td>
                            <td class="ops-num">{{ number_format($t['anomalies_active']) }}</td>
                            <td class="ops-num">{{ \App\Support\Money::compact((float) $t['observed_recovery'], $t['currency']) }}</td>
                            <td>{{ $t['last_ingestion_at'] ? \Illuminate\Support\Carbon::parse($t['last_ingestion_at'])->diffForHumans() : '—' }}</td>
                            <td class="ops-actions">
                                <a href="{{ \App\Filament\Ops\Resources\TenantResource::getUrl('edit', ['record' => $t['id']]) }}">Manage</a>
                                <a href="{{ route('ops.impersonate', ['tenant' => $t['id']]) }}"
                                   onclick="return confirm('Enter {{ $t['name'] }} as its admin? Your actions will be recorded.');">Enter</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" style="text-align:center;color:var(--ax-muted,#6b7280);padding:2rem;">No tenants yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
