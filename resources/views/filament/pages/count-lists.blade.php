<x-filament-panels::page>
    @php
        $stores = $this->stores();
        $sid = $this->currentStoreId();
        $open = $this->openCounts();
        $recent = $this->recentCounts();
        $names = $this->productNames($open->concat($recent));
    @endphp
    <style>
        .cl-wrap { display:flex; flex-direction:column; gap:1rem; }
        .cl-card { border:1px solid var(--ax-line); border-radius:1rem; background:var(--ax-bg); padding:1rem 1.2rem; }
        .cl-muted { color:var(--ax-muted); font-size:.85rem; line-height:1.45; }
        .cl-stores { display:flex; gap:.5rem; flex-wrap:wrap; }
        .cl-store { border:1px solid var(--ax-line); border-radius:.6rem; padding:.45rem .7rem; font-size:.82rem; color:var(--ax-ink); text-decoration:none; }
        .cl-store.on { border-color:var(--ax-primary, #6d28d9); background:var(--ax-primary-soft, #f5f3ff); font-weight:700; }
        .cl-tbl { width:100%; border-collapse:collapse; font-size:.85rem; }
        .cl-tbl th { text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; color:var(--ax-muted); padding:.4rem .5rem; border-bottom:1px solid var(--ax-line); }
        .cl-tbl td { padding:.5rem; border-bottom:1px solid var(--ax-line); vertical-align:middle; color:var(--ax-ink); }
        .cl-tbl input { width:6.5rem; border:1px solid var(--ax-line); border-radius:.4rem; padding:.3rem .45rem; background:var(--ax-bg); color:var(--ax-ink); }
        .cl-scroll { overflow-x:auto; }
        .cl-var-neg { color:var(--ax-danger-fg); font-weight:700; }
        .cl-var-pos { color:var(--ax-warning-fg); font-weight:700; }
        .cl-btns { display:flex; gap:.6rem; flex-wrap:wrap; margin-top:.75rem; }
        .cl-btn { border:1px solid var(--ax-line); border-radius:.5rem; padding:.45rem .9rem; font-size:.85rem; font-weight:600; background:var(--ax-bg); color:var(--ax-ink); cursor:pointer; }
        .cl-btn.primary { background:var(--ax-primary, #6d28d9); border-color:transparent; color:#fff; }
    </style>

    <div class="cl-wrap">
        <div class="cl-card">
            <div class="cl-muted">Each store's list holds the positions whose stock figure the system doubts — stock on the books that is not selling, stock falling faster than sales explain, negative stock — ranked by the money behind the doubt. Count them, enter what you find, and the difference is recorded and followed up. Lists refresh every night after detection.</div>
            @if(empty($stores))
                <div style="margin-top:.75rem;font-weight:600;color:var(--ax-ink)">Nothing to count right now — no store has an open stock doubt.</div>
            @else
                <div class="cl-stores" style="margin-top:.75rem">
                    @foreach($stores as $s)
                        <a class="cl-store {{ $s['id'] === $sid ? 'on' : '' }}" href="{{ static::getUrl(['store' => $s['id']]) }}" wire:navigate>
                            {{ $s['name'] }} · {{ $s['open'] }} · {{ $this->money($s['value']) }}
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        @if($open->isNotEmpty())
        <div class="cl-card">
            <div style="font-weight:800;color:var(--ax-ink);margin-bottom:.5rem">To count</div>
            <div class="cl-scroll">
            <table class="cl-tbl">
                <thead><tr><th>#</th><th>SKU</th><th>Product</th><th>Why</th><th>System says</th><th>At stake</th><th>Counted</th></tr></thead>
                <tbody>
                @foreach($open as $c)
                    <tr wire:key="cc-{{ $c->id }}">
                        <td>{{ $c->rank }}</td>
                        <td style="font-family:ui-monospace,monospace">{{ $c->sku }}</td>
                        <td>{{ $names[$c->sku] ?? '' }}</td>
                        <td class="cl-muted">{{ $c->reasonLabel() }}</td>
                        <td>{{ $c->system_qty !== null ? rtrim(rtrim(number_format($c->system_qty, 2), '0'), '.') : '—' }}</td>
                        <td>{{ $this->money((float) $c->value_at_risk) }}</td>
                        <td><input type="text" inputmode="decimal" wire:model="counted.{{ $c->id }}" placeholder="qty" aria-label="Counted quantity for {{ $c->sku }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            <div class="cl-btns">
                <button type="button" class="cl-btn primary" wire:click="saveCounts">Save counts</button>
                <button type="button" class="cl-btn" wire:click="download">Download list (CSV)</button>
            </div>
        </div>
        @endif

        @if($recent->isNotEmpty())
        <div class="cl-card">
            <div style="font-weight:800;color:var(--ax-ink);margin-bottom:.5rem">Counted — last 30 days</div>
            <div class="cl-scroll">
            <table class="cl-tbl">
                <thead><tr><th>When</th><th>SKU</th><th>Product</th><th>System</th><th>Counted</th><th>Difference</th><th>Value</th></tr></thead>
                <tbody>
                @foreach($recent as $c)
                    <tr>
                        <td class="cl-muted">{{ $c->counted_at?->diffForHumans() }}</td>
                        <td style="font-family:ui-monospace,monospace">{{ $c->sku }}</td>
                        <td>{{ $names[$c->sku] ?? '' }}</td>
                        <td>{{ rtrim(rtrim(number_format((float) $c->system_qty, 2), '0'), '.') }}</td>
                        <td>{{ rtrim(rtrim(number_format((float) $c->counted_qty, 2), '0'), '.') }}</td>
                        <td class="{{ $c->variance_qty < 0 ? 'cl-var-neg' : ($c->variance_qty > 0 ? 'cl-var-pos' : '') }}">{{ $c->variance_qty > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format((float) $c->variance_qty, 2), '0'), '.') ?: '0' }}</td>
                        <td>{{ $this->money(abs((float) $c->variance_value)) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
        @endif
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
