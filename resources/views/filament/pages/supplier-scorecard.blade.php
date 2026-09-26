<x-filament-panels::page>
    @php
        $rows = $this->rows();
        $detail = $this->detail();
        $graded = collect($rows)->whereNotNull('grade');
    @endphp
    <style>
        .sc-card { border:1px solid var(--ax-line); border-radius:1rem; background:var(--ax-bg); padding:1rem 1.15rem; }
        .sc-muted { color:var(--ax-muted); font-size:.85rem; line-height:1.45; }
        .sc-tbl { width:100%; border-collapse:collapse; font-size:.84rem; }
        .sc-tbl th { text-align:left; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:var(--ax-muted); padding:.4rem .45rem; border-bottom:1px solid var(--ax-line); white-space:nowrap; }
        .sc-tbl td { padding:.5rem .45rem; border-bottom:1px solid var(--ax-line); color:var(--ax-ink); white-space:nowrap; }
        .sc-grade { display:inline-block; min-width:1.6rem; text-align:center; font-weight:800; border-radius:.4rem; padding:.1rem .35rem; }
        .sc-A { background:var(--ax-success-soft); color:var(--ax-success-fg); }
        .sc-B { background:var(--ax-info-soft, #e0f2fe); color:var(--ax-info-fg, #0369a1); }
        .sc-C { background:var(--ax-warning-soft); color:var(--ax-warning-fg); }
        .sc-D { background:var(--ax-danger-soft); color:var(--ax-danger-fg); }
        .sc-scroll { overflow-x:auto; }
        .sc-bar { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
        .sc-chip { border:1px solid var(--ax-line); border-radius:.5rem; padding:.35rem .7rem; font-size:.82rem; color:var(--ax-ink); text-decoration:none; }
        .sc-chip.on { border-color:var(--ax-primary, #6d28d9); font-weight:700; }
        .sc-link { color:var(--ax-primary, #6d28d9); font-weight:600; text-decoration:none; }
        .sc-bad { color:var(--ax-danger-fg); font-weight:700; }
        .sc-btn { border:1px solid var(--ax-line); border-radius:.5rem; padding:.35rem .8rem; font-size:.82rem; font-weight:600; background:var(--ax-bg); color:var(--ax-ink); cursor:pointer; }
    </style>

    <div style="display:flex;flex-direction:column;gap:1rem">
        <div class="sc-card">
            <div class="sc-bar">
                <span class="sc-muted">Orders placed in the last</span>
                @foreach(['30', '90', '180'] as $d)
                    <a class="sc-chip {{ (string) $this->days === $d ? 'on' : '' }}" href="{{ static::getUrl(['days' => $d]) }}" wire:navigate>{{ $d }} days</a>
                @endforeach
                <span style="flex:1"></span>
                @if($rows)<button type="button" class="sc-btn" wire:click="download">Download (CSV)</button>@endif
            </div>
            <div class="sc-muted" style="margin-top:.6rem">Score = 45% fill rate + 45% on-time delivery + 10% cost stability. A ≥ 90, B ≥ 80, C ≥ 65, else D. Suppliers with fewer than {{ \App\Services\Suppliers\SupplierScorecardService::MIN_LINES }} lines due in the window are listed but not graded. Worst first.
                @if($graded->isNotEmpty()) {{ $graded->where('grade', 'D')->count() }} supplier(s) graded D. @endif
            </div>
        </div>

        @if(! empty($detail))
        <div class="sc-card">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:1rem;flex-wrap:wrap">
                <div style="font-weight:800;color:var(--ax-ink)">{{ $detail['name'] }}</div>
                <div class="sc-bar">
                    @if($u = $this->prepUrl($detail['id']))<a class="sc-link" href="{{ $u }}">Negotiation pack</a>@endif
                    <a class="sc-link" href="{{ static::getUrl(['days' => $this->days]) }}" wire:navigate>Close</a>
                </div>
            </div>
            <div class="sc-muted">Contracted lead time: {{ $detail['contracted_lead'] !== null ? $detail['contracted_lead'] . ' days' : 'not set' }}</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(20rem,1fr));gap:1rem;margin-top:.75rem">
                <div class="sc-scroll">
                    <div style="font-weight:700;margin-bottom:.35rem">Worst-filled items</div>
                    <table class="sc-tbl">
                        <thead><tr><th>SKU</th><th>Product</th><th>Ordered</th><th>Received</th><th>Fill</th></tr></thead>
                        <tbody>
                        @forelse($detail['skus'] as $s)
                            <tr><td style="font-family:ui-monospace,monospace">{{ $s['sku'] }}</td><td>{{ \Illuminate\Support\Str::limit((string) $s['name'], 28) }}</td>
                                <td>{{ number_format($s['ordered']) }}</td><td>{{ number_format($s['received']) }}</td>
                                <td class="{{ $s['fill_rate'] < 90 ? 'sc-bad' : '' }}">{{ $s['fill_rate'] }}%</td></tr>
                        @empty
                            <tr><td colspan="5" class="sc-muted">No lines due in the window.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="sc-scroll">
                    <div style="font-weight:700;margin-bottom:.35rem">Overdue, still open</div>
                    <table class="sc-tbl">
                        <thead><tr><th>PO</th><th>SKU</th><th>Expected</th><th>Days late</th><th>Open qty</th></tr></thead>
                        <tbody>
                        @forelse($detail['overdue'] as $o)
                            <tr><td>{{ $o['po'] }}</td><td style="font-family:ui-monospace,monospace">{{ $o['sku'] }}</td><td>{{ $o['expected'] }}</td>
                                <td class="sc-bad">{{ $o['days_late'] }}</td><td>{{ number_format($o['open_qty']) }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="sc-muted">Nothing overdue.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif

        <div class="sc-card sc-scroll">
            @if(empty($rows))
                <div class="sc-muted">No purchase orders in the window. Load purchase orders (Imports → Purchase Orders) to grade your suppliers.</div>
            @else
            <table class="sc-tbl">
                <thead><tr><th>Grade</th><th>Supplier</th><th>Lines</th><th>Ordered</th><th>Fill rate</th><th>On time</th><th>Lead time</th><th>Cost change</th><th>Overdue</th><th>Stock-outs on its items</th></tr></thead>
                <tbody>
                @foreach($rows as $r)
                    <tr>
                        <td>@if($r['grade'])<span class="sc-grade sc-{{ $r['grade'] }}">{{ $r['grade'] }}</span> <span class="sc-muted">{{ $r['score'] }}</span>@else<span class="sc-muted">—</span>@endif</td>
                        <td>@if($r['supplier_id'])<a class="sc-link" href="{{ static::getUrl(['days' => $this->days, 'supplier' => $r['supplier_id']]) }}" wire:navigate>{{ $r['name'] }}</a>@else{{ $r['name'] }}@endif</td>
                        <td>{{ number_format($r['lines']) }}</td>
                        <td>{{ $this->tableMoney($r['ordered_value']) }}</td>
                        <td class="{{ $r['fill_rate'] !== null && $r['fill_rate'] < 90 ? 'sc-bad' : '' }}">{{ $r['fill_rate'] !== null ? $r['fill_rate'] . '%' : '—' }}</td>
                        <td class="{{ $r['on_time'] !== null && $r['on_time'] < 80 ? 'sc-bad' : '' }}">{{ $r['on_time'] !== null ? $r['on_time'] . '%' : '—' }}</td>
                        <td>{{ $r['lead_days'] !== null ? $r['lead_days'] . ' d' : '—' }}@if($r['lead_days'] !== null && $r['prev_lead_days'] !== null && $r['lead_days'] - $r['prev_lead_days'] >= 1)<span class="sc-bad"> ▲{{ round($r['lead_days'] - $r['prev_lead_days'], 1) }}</span>@endif</td>
                        <td class="{{ ($r['cost_change'] ?? 0) >= 3 ? 'sc-bad' : '' }}">{{ $r['cost_change'] !== null ? ($r['cost_change'] > 0 ? '+' : '') . $r['cost_change'] . '%' : '—' }}</td>
                        <td>{{ $r['overdue_lines'] ? $r['overdue_lines'] . ' · ' . $this->tableMoney($r['overdue_value']) : '—' }}</td>
                        <td>{{ $r['stockouts'] ? $r['stockouts'] . ' · ' . $this->tableMoney($r['lost_revenue']) : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>
</x-filament-panels::page>
