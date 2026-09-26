<x-filament-panels::page>
    @php
        $d = $this->data();
        $w = $d['waste'];
        $stores = \App\Models\Store::where('tenant_id', \Filament\Facades\Filament::getTenant()?->id)->pluck('name', 'id');
    @endphp
    <style>
        .fx-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(12rem, 1fr)); gap:.75rem; }
        .fx-card { border:1px solid var(--ax-line); border-radius:1rem; background:var(--ax-bg); padding:1rem 1.15rem; }
        .fx-label { font-size:.7rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--ax-muted); }
        .fx-value { font-size:1.45rem; font-weight:800; color:var(--ax-ink); margin-top:.2rem; }
        .fx-sub { font-size:.8rem; color:var(--ax-muted); margin-top:.15rem; }
        .fx-muted { color:var(--ax-muted); font-size:.85rem; line-height:1.45; }
        .fx-h { font-weight:800; color:var(--ax-ink); margin-bottom:.5rem; }
        .fx-tbl { width:100%; border-collapse:collapse; font-size:.85rem; }
        .fx-tbl th { text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; color:var(--ax-muted); padding:.35rem .45rem; border-bottom:1px solid var(--ax-line); }
        .fx-tbl td { padding:.45rem; border-bottom:1px solid var(--ax-line); color:var(--ax-ink); vertical-align:top; }
        .fx-two { display:grid; grid-template-columns:repeat(auto-fit, minmax(18rem, 1fr)); gap:.75rem; }
        .fx-link { color:var(--ax-primary, #6d28d9); font-weight:600; font-size:.82rem; }
        .fx-scroll { overflow-x:auto; }
    </style>

    <div style="display:flex;flex-direction:column;gap:1rem">
        <div class="fx-grid">
            <div class="fx-card">
                <div class="fx-label">Won't sell before expiry</div>
                <div class="fx-value">{{ $this->money($d['at_risk_value']) }}</div>
                <div class="fx-sub">{{ $d['at_risk_count'] }} position(s), at cost</div>
            </div>
            <div class="fx-card">
                <div class="fx-label">Expiring in 7 days</div>
                <div class="fx-value">{{ $this->money($d['expiring_7']) }}</div>
                <div class="fx-sub">14 days: {{ $this->money($d['expiring_14']) }}</div>
            </div>
            <div class="fx-card">
                <div class="fx-label">Past its date, still on hand</div>
                <div class="fx-value">{{ $this->money($d['expired_value']) }}</div>
                <div class="fx-sub">{{ $d['as_of'] ? 'Stock as of ' . $d['as_of'] : 'No stock loaded' }}</div>
            </div>
            <div class="fx-card">
                <div class="fx-label">Waste, last 28 days</div>
                <div class="fx-value">{{ $w ? $this->money($w['value']) : '—' }}</div>
                <div class="fx-sub">
                    @if($w)
                        {{ $w['rate'] !== null ? $w['rate'] . '% of units out' : '' }}
                        @if($w['prev'] > 0) · {{ $w['value'] >= $w['prev'] ? '▲' : '▼' }} vs {{ $this->money($w['prev']) }} before @endif
                    @else
                        No waste data yet
                    @endif
                </div>
            </div>
        </div>

        @if(! $d['has_expiry'] || ! $w)
        <div class="fx-card fx-muted">
            @if(! $d['has_expiry'])
                <div><strong>Expiry dates:</strong> add an <em>Expiry Date</em> column to your stock file (one row per batch) and Autnyx works out, batch by batch, what will not sell in time.</div>
            @endif
            @if(! $w)
                <div style="margin-top:.35rem"><strong>Waste:</strong> upload waste and write-offs (date, SKU, store, quantity, reason) — <a class="fx-link" href="{{ $this->templateUrl($this->wasteType()) }}">download the template</a>.</div>
            @endif
        </div>
        @endif

        @if($d['risk_items']->isNotEmpty())
        <div class="fx-card">
            <div style="display:flex;justify-content:space-between;align-items:baseline" class="fx-h">
                <span>Will not sell in time</span>
                @if($u = $this->anomaliesUrl('expiry_risk'))<a class="fx-link" href="{{ $u }}">All findings</a>@endif
            </div>
            <div class="fx-scroll">
            <table class="fx-tbl">
                <thead><tr><th>SKU</th><th>Store</th><th>Units at risk</th><th>First expiry</th><th>Value</th><th></th></tr></thead>
                <tbody>
                @foreach($d['risk_items'] as $a)
                    <tr>
                        <td style="font-family:ui-monospace,monospace">{{ $a->sku }}</td>
                        <td>{{ $stores[$a->store_id] ?? '—' }}</td>
                        <td>{{ round((float) ($a->context['units_at_risk'] ?? 0)) }}</td>
                        <td>{{ $a->context['first_expiry'] ?? '—' }}</td>
                        <td>{{ $this->tableMoney((float) ($a->context['inventory_value'] ?? 0)) }}</td>
                        <td>@if($u = $this->investigateUrl($a->id))<a class="fx-link" href="{{ $u }}">Open</a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
        @endif

        @if($w)
        <div class="fx-two">
            @foreach(['by_reason' => 'Waste by reason', 'by_category' => 'Waste by category', 'by_store' => 'Waste by store'] as $key => $title)
            <div class="fx-card">
                <div class="fx-h">{{ $title }}</div>
                <table class="fx-tbl">
                    <thead><tr><th></th><th>Units</th><th>Value</th></tr></thead>
                    <tbody>
                    @forelse($w[$key] as $row)
                        <tr><td>{{ ucfirst((string) $row['key']) }}</td><td>{{ number_format($row['units']) }}</td><td>{{ $this->tableMoney($row['value']) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="fx-muted">Nothing in the period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @endforeach
        </div>
        <div class="fx-muted">Waste period {{ $w['from'] }} to {{ $w['to'] }}. {{ $w['findings'] }} store × item waste finding(s) open
            @if($u = $this->anomaliesUrl('waste_rate')) — <a class="fx-link" href="{{ $u }}">see them</a>@endif.</div>
        @endif
    </div>
</x-filament-panels::page>
