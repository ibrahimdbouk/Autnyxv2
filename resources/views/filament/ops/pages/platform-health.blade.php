<x-filament-panels::page>
    @php
        $d = $this->getData();
        $s = $d['summary'];
        $fmtBytes = function ($b) {
            $b = (float) $b; $u = ['B','KB','MB','GB','TB']; $i = 0;
            while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
            return round($b, $i ? 1 : 0) . ' ' . $u[$i];
        };
        $dur = fn ($ms) => $ms === null ? '—' : ($ms >= 1000 ? round($ms/1000, 1).'s' : $ms.'ms');
    @endphp

    <style>
        .ph-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:.9rem; }
        @media(min-width:900px){ .ph-grid{ grid-template-columns:repeat(4,1fr); } }
        .ph-tile { background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.85rem; padding:1rem 1.1rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); }
        .ph-tile .lbl { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-muted,#6b7280); }
        .ph-tile .val { font-size:1.5rem; font-weight:800; color:var(--ax-ink,#111827); margin-top:.2rem; }
        .ph-card { margin-top:1.25rem; background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.9rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); overflow:hidden; }
        .ph-card h3 { font-size:.9rem; font-weight:700; color:var(--ax-ink,#111827); padding:.85rem 1.15rem; border-bottom:1px solid var(--ax-line-2,#f3f4f6); margin:0; }
        table.ph-tbl { width:100%; border-collapse:collapse; font-size:.82rem; }
        table.ph-tbl th { text-align:left; color:var(--ax-muted,#6b7280); font-size:.68rem; text-transform:uppercase; padding:.55rem .9rem; border-bottom:1px solid var(--ax-line,#e5e7eb); }
        table.ph-tbl td { padding:.55rem .9rem; border-bottom:1px solid var(--ax-line-2,#f3f4f6); color:var(--ax-text,#374151); }
        .ph-pill { display:inline-block; padding:.12rem .55rem; border-radius:9999px; font-size:.68rem; font-weight:700; }
        .ph-ok { background:#dcfce7; color:#166534; } .ph-bad { background:#fee2e2; color:#991b1b; }
        .ph-num { font-variant-numeric:tabular-nums; }
        .ph-muted { color:var(--ax-muted,#6b7280); }
    </style>

    <div class="ph-grid">
        <div class="ph-tile"><div class="lbl">Pipeline (24h)</div><div class="val">
            <span class="ph-pill {{ $s['pipeline_ok'] ? 'ph-ok' : 'ph-bad' }}">{{ $s['pipeline_ok'] ? 'Healthy' : 'Failures' }}</span>
        </div></div>
        <div class="ph-tile"><div class="lbl">Stuck / failed imports</div><div class="val ph-num" style="{{ ($s['stuck_imports']+$s['failed_imports'])>0 ? 'color:#dc2626' : '' }}">{{ number_format($s['stuck_imports'] + $s['failed_imports']) }}</div></div>
        <div class="ph-tile"><div class="lbl">Failed queue jobs</div><div class="val ph-num" style="{{ $s['failed_jobs']>0 ? 'color:#dc2626' : '' }}">{{ number_format($s['failed_jobs']) }}</div></div>
        <div class="ph-tile"><div class="lbl">Database size</div><div class="val ph-num">{{ $fmtBytes($s['db_bytes']) }}</div></div>
    </div>

    <div class="ph-card">
        <h3>Nightly pipeline — last run per job</h3>
        <table class="ph-tbl">
            <thead><tr><th>Job</th><th>Status</th><th>Last run</th><th>Duration</th></tr></thead>
            <tbody>
                @forelse($d['pipeline'] as $job)
                    <tr>
                        <td><strong>{{ $job['command'] }}</strong></td>
                        <td><span class="ph-pill {{ $job['status'] === 'success' ? 'ph-ok' : 'ph-bad' }}">{{ ucfirst($job['status']) }}</span></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($job['ran_at'])->diffForHumans() }}</td>
                        <td class="ph-num">{{ $dur($job['duration_ms']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="ph-muted" style="text-align:center;padding:1.5rem;">No scheduled runs recorded yet — jobs record here as they run.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="ph-card">
        <h3>Imports across all tenants</h3>
        <table class="ph-tbl">
            <thead><tr><th>Stuck (&gt;15m)</th><th>Failed</th><th>Completed w/ errors</th><th>Awaiting review</th><th>In progress</th><th>Completed</th></tr></thead>
            <tbody><tr>
                <td class="ph-num" style="{{ $d['imports']['stuck']>0?'color:#dc2626':'' }}">{{ number_format($d['imports']['stuck']) }}</td>
                <td class="ph-num" style="{{ $d['imports']['failed']>0?'color:#dc2626':'' }}">{{ number_format($d['imports']['failed']) }}</td>
                <td class="ph-num">{{ number_format($d['imports']['with_errors']) }}</td>
                <td class="ph-num">{{ number_format($d['imports']['awaiting_review']) }}</td>
                <td class="ph-num">{{ number_format($d['imports']['importing']) }}</td>
                <td class="ph-num">{{ number_format($d['imports']['completed']) }}</td>
            </tr></tbody>
        </table>
    </div>

    <div class="ph-card">
        <h3>Biggest tables
            <span class="ph-muted" style="font-weight:400;font-size:.75rem;">· last purge: {{ $d['lastPurge'] ? \Illuminate\Support\Carbon::parse($d['lastPurge']->ran_at)->diffForHumans().' ('.ucfirst($d['lastPurge']->status).')' : 'never recorded' }}</span>
        </h3>
        <table class="ph-tbl">
            <thead><tr><th>Table</th><th>Size</th><th>Rows (approx)</th></tr></thead>
            <tbody>
                @forelse($d['database']['tables'] as $tbl)
                    <tr><td>{{ $tbl['name'] }}</td><td class="ph-num">{{ $fmtBytes($tbl['bytes']) }}</td><td class="ph-num">{{ number_format($tbl['rows']) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="ph-muted" style="text-align:center;padding:1rem;">No table stats available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(count($d['failures']))
    <div class="ph-card">
        <h3>Recent job failures</h3>
        <table class="ph-tbl">
            <thead><tr><th>Job</th><th>When</th><th>Message</th></tr></thead>
            <tbody>
                @foreach($d['failures'] as $f)
                    <tr><td><strong>{{ $f->command }}</strong></td><td>{{ $f->ran_at?->diffForHumans() }}</td><td class="ph-muted">{{ \Illuminate\Support\Str::limit($f->message, 120) ?: '—' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</x-filament-panels::page>
