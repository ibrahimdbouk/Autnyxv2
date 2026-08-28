<x-filament-panels::page>
    @php
        $overview = $this->getOverview();
        $tenants  = $this->getTenants();
        $health   = $this->getHealth();
        $growth   = $this->getGrowth();

        $fmtBytes = function ($b) {
            $b = (float) $b; $u = ['B','KB','MB','GB','TB']; $i = 0;
            while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
            return round($b, $i ? 1 : 0) . ' ' . $u[$i];
        };

        // Inline SVG sparkline — no external library. $series is [['date'=>,'value'=>], …].
        $spark = function (array $series, string $stroke) {
            $vals = array_map(fn ($p) => (int) $p['value'], $series);
            $n = count($vals);
            if ($n === 0) { return ''; }
            $max = max($vals); $max = $max > 0 ? $max : 1;
            $w = 220; $h = 40; $step = $n > 1 ? $w / ($n - 1) : 0;
            $pts = [];
            foreach ($vals as $i => $val) {
                $x = round($i * $step, 1);
                $y = round($h - ($val / $max) * ($h - 4) - 2, 1);
                $pts[] = "$x,$y";
            }
            $line = implode(' ', $pts);
            $area = "0,$h " . $line . "," . round(($n - 1) * $step, 1) . ",$h";
            return '<svg viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" class="og-spark">'
                . '<polygon points="'.$area.'" fill="'.$stroke.'" opacity="0.10"></polygon>'
                . '<polyline points="'.$line.'" fill="none" stroke="'.$stroke.'" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"></polyline>'
                . '</svg>';
        };
        $t30 = $growth['totals'];
    @endphp

    <style>
        .ops-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:.9rem; }
        @media(min-width:900px){ .ops-grid{ grid-template-columns:repeat(5,1fr); } }
        .ops-tile { background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.85rem; padding:1rem 1.1rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); }
        .ops-tile .lbl { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-muted,#6b7280); }
        .ops-tile .val { font-size:1.7rem; font-weight:800; color:var(--ax-ink,#111827); margin-top:.2rem; }
        .ops-card { margin-top:1.25rem; background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.9rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); overflow:hidden; }
        .ops-card > h3 { font-size:.9rem; font-weight:700; color:var(--ax-ink,#111827); padding:.85rem 1.15rem; border-bottom:1px solid var(--ax-line-2,#f3f4f6); margin:0; }
        table.ops-tbl { width:100%; border-collapse:collapse; font-size:.82rem; }
        table.ops-tbl th { text-align:left; color:var(--ax-muted,#6b7280); font-size:.68rem; text-transform:uppercase; letter-spacing:.03em; padding:.6rem .8rem; border-bottom:1px solid var(--ax-line,#e5e7eb); white-space:nowrap; }
        table.ops-tbl td { padding:.6rem .8rem; border-bottom:1px solid var(--ax-line-2,#f3f4f6); color:var(--ax-text,#374151); white-space:nowrap; }
        table.ops-tbl tr:last-child td { border-bottom:0; }
        .ops-name { font-weight:700; color:var(--ax-ink,#111827); }
        .ops-badge { display:inline-block; padding:.12rem .5rem; border-radius:9999px; font-size:.68rem; font-weight:700; }
        .ops-badge.active { background:#dcfce7; color:#166534; }
        .ops-badge.suspended { background:#fee2e2; color:#991b1b; }
        .ops-badge.plan { background:#fef3c7; color:#92400e; }
        .ops-actions a { color:var(--ax-accent-600,#b45309); font-weight:600; text-decoration:none; margin-right:.7rem; }
        .ops-scroll { overflow-x:auto; }
        .ops-num { font-variant-numeric:tabular-nums; }

        /* Health strip */
        .oh-strip { display:grid; grid-template-columns:repeat(2,1fr); gap:.75rem; margin-top:1rem; }
        @media(min-width:900px){ .oh-strip{ grid-template-columns:repeat(4,1fr); } }
        .oh-chip { display:flex; align-items:center; gap:.6rem; background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.75rem; padding:.7rem .9rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); }
        .oh-dot { width:.6rem; height:.6rem; border-radius:9999px; flex:none; }
        .oh-ok { background:#22c55e; } .oh-bad { background:#ef4444; }
        .oh-chip .k { font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:var(--ax-muted,#6b7280); }
        .oh-chip .v { font-size:.95rem; font-weight:700; color:var(--ax-ink,#111827); }

        /* Growth cards */
        .og-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:.9rem; margin-top:1.25rem; }
        @media(min-width:1100px){ .og-grid{ grid-template-columns:repeat(4,1fr); } }
        .og-card { background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.85rem; padding:.9rem 1rem .7rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); }
        .og-card .k { font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-muted,#6b7280); }
        .og-card .v { font-size:1.4rem; font-weight:800; color:var(--ax-ink,#111827); margin-top:.1rem; }
        .og-card .sub { font-size:.7rem; color:var(--ax-muted,#6b7280); }
        .og-spark { width:100%; height:40px; display:block; margin-top:.4rem; }
    </style>

    {{-- Platform health strip --}}
    <div class="oh-strip">
        <div class="oh-chip">
            <span class="oh-dot {{ $health['pipeline_ok'] ? 'oh-ok' : 'oh-bad' }}"></span>
            <div><div class="k">Nightly pipeline (24h)</div><div class="v">{{ $health['pipeline_ok'] ? 'Healthy' : 'Failures' }}</div></div>
        </div>
        <div class="oh-chip">
            <span class="oh-dot {{ ($health['stuck_imports'] + $health['failed_imports']) > 0 ? 'oh-bad' : 'oh-ok' }}"></span>
            <div><div class="k">Stuck / failed imports</div><div class="v ops-num">{{ number_format($health['stuck_imports'] + $health['failed_imports']) }}</div></div>
        </div>
        <div class="oh-chip">
            <span class="oh-dot {{ $health['failed_jobs'] > 0 ? 'oh-bad' : 'oh-ok' }}"></span>
            <div><div class="k">Failed queue jobs</div><div class="v ops-num">{{ number_format($health['failed_jobs']) }}</div></div>
        </div>
        <div class="oh-chip">
            <span class="oh-dot oh-ok"></span>
            <div><div class="k">Database size</div><div class="v ops-num">{{ $fmtBytes($health['db_bytes']) }}</div></div>
        </div>
    </div>

    {{-- Headline tiles --}}
    <div class="ops-grid" style="margin-top:1rem;">
        <div class="ops-tile"><div class="lbl">Tenants</div><div class="val ops-num">{{ number_format($overview['tenants']) }}</div></div>
        <div class="ops-tile"><div class="lbl">Active</div><div class="val ops-num" style="color:#16a34a">{{ number_format($overview['active']) }}</div></div>
        <div class="ops-tile"><div class="lbl">Suspended</div><div class="val ops-num" style="color:#dc2626">{{ number_format($overview['suspended']) }}</div></div>
        <div class="ops-tile"><div class="lbl">Users</div><div class="val ops-num">{{ number_format($overview['users']) }}</div></div>
        <div class="ops-tile"><div class="lbl">Active anomalies</div><div class="val ops-num">{{ number_format($overview['anomalies_active']) }}</div></div>
    </div>

    {{-- 30-day growth sparklines --}}
    <div class="og-grid">
        <div class="og-card">
            <div class="k">New tenants</div>
            <div class="v ops-num">{{ number_format($t30['tenants_this_month']) }}<span class="sub"> this month</span></div>
            {!! $spark($growth['tenants'], '#d97706') !!}
            <div class="sub">30-day trend</div>
        </div>
        <div class="og-card">
            <div class="k">New users</div>
            <div class="v ops-num">{{ number_format($t30['users_this_month']) }}<span class="sub"> this month</span></div>
            {!! $spark($growth['users'], '#2563eb') !!}
            <div class="sub">30-day trend</div>
        </div>
        <div class="og-card">
            <div class="k">Anomalies detected</div>
            <div class="v ops-num">{{ number_format($t30['anomalies_30d']) }}<span class="sub"> last 30d</span></div>
            {!! $spark($growth['anomalies'], '#dc2626') !!}
            <div class="sub">all tenants</div>
        </div>
        <div class="og-card">
            <div class="k">Episodes resolved</div>
            <div class="v ops-num">{{ number_format($t30['resolved_30d']) }}<span class="sub"> last 30d</span></div>
            {!! $spark($growth['resolved'], '#0d9488') !!}
            <div class="sub">all tenants</div>
        </div>
    </div>

    {{-- Tenants table --}}
    <div class="ops-card">
        <h3>All tenants</h3>
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
                                <a href="{{ \App\Filament\Ops\Pages\TenantProfile::urlFor($t['id']) }}">View</a>
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
