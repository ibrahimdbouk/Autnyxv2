<x-filament-panels::page>
<style>
    .scv-wrap { display:flex; flex-direction:column; gap:1.25rem; }
    .scv-card {
        background:var(--ax-bg, #fff); border:1px solid var(--ax-line, #e5e7eb);
        border-radius:var(--ax-radius-lg, .9rem); box-shadow:var(--ax-shadow, 0 1px 2px rgba(0,0,0,.05));
        padding:1.25rem 1.5rem;
    }
    .scv-why { color:var(--ax-muted, #6b7280); margin:.35rem 0 0; font-size:.95rem; }
    .scv-def {
        display:inline-flex; align-items:center; gap:.4rem; font-size:.72rem; font-weight:600;
        letter-spacing:.02em; color:var(--ax-accent-fg, #fff); background:var(--ax-accent, #7c3aed);
        padding:.25rem .7rem; border-radius:var(--ax-radius-pill, 9999px);
    }
    .scv-stats { display:grid; grid-template-columns:repeat(5, 1fr); gap:1px; background:var(--ax-line, #e5e7eb);
        border:1px solid var(--ax-line, #e5e7eb); border-radius:var(--ax-radius-lg, .9rem); overflow:hidden; }
    @media (max-width: 720px){ .scv-stats { grid-template-columns:repeat(2, 1fr); } }
    .scv-stat { background:var(--ax-bg, #fff); padding:1rem 1.15rem; }
    .scv-stat-k { font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-faint, #9ca3af); }
    .scv-stat-v { font-size:1.4rem; font-weight:700; color:var(--ax-ink, #111827); margin-top:.15rem; font-variant-numeric:tabular-nums; }
    .scv-h { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:var(--ax-faint, #9ca3af); font-weight:700; margin:0 0 .75rem; }
    .scv-props { display:flex; flex-wrap:wrap; gap:1.5rem; }
    .scv-prop-k { font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; color:var(--ax-faint, #9ca3af); }
    .scv-prop-v { font-size:1.05rem; font-weight:600; color:var(--ax-ink, #111827); margin-top:.1rem; }
    .scv-pill { display:inline-flex; align-items:center; padding:.15rem .6rem; border-radius:var(--ax-radius-pill, 9999px);
        font-size:.72rem; font-weight:600; background:var(--ax-neutral-soft, #f3f4f6); color:var(--ax-neutral-fg, #374151); }
    .scv-pill--premium { background:var(--ax-accent-soft, #ede9fe); color:var(--ax-accent-strong, #6d28d9); }
    .scv-pill--value   { background:var(--ax-info-soft, #e0f2fe); color:var(--ax-info-fg, #075985); }
    .scv-table { width:100%; border-collapse:collapse; }
    .scv-table th { text-align:left; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em;
        color:var(--ax-faint, #9ca3af); font-weight:700; padding:.5rem .6rem; border-bottom:1px solid var(--ax-line, #e5e7eb); }
    .scv-table td { padding:.65rem .6rem; border-bottom:1px solid var(--ax-line, #f1f1f4); font-size:.9rem; color:var(--ax-ink, #111827); }
    .scv-table tr:last-child td { border-bottom:0; }
    .scv-num { text-align:right; font-variant-numeric:tabular-nums; }
    .scv-store-name { font-weight:600; }
    .scv-store-sub { color:var(--ax-muted, #6b7280); font-size:.8rem; }
    .scv-muted { color:var(--ax-muted, #6b7280); }
    .scv-scroll { overflow-x:auto; }
</style>

@php
    $record  = $this->record;
    $params  = is_array($record->params) ? $record->params : [];
    $metrics = $this->metrics();
    $isDemand = $record->method === 'demand';
    $isCustom = ! empty($params['custom']);
    $pillClass = fn ($tier) => match ($tier) {
        'premium' => 'scv-pill scv-pill--premium',
        'value'   => 'scv-pill scv-pill--value',
        default   => 'scv-pill',
    };
@endphp

<div class="scv-wrap">

    {{-- Header --}}
    <div class="scv-card">
        <span class="scv-def">
            @if ($isCustom) Custom group
            @elseif ($isDemand) Behavioural
            @elseif (isset($params['format']) || isset($params['region'])) {{ ($params['format'] ?? '—') }} · {{ ($params['region'] ?? '—') }}
            @else Cluster
            @endif
        </span>
        <p class="scv-why">{{ $record->rationale() }}</p>
    </div>

    {{-- Numbers --}}
    <div class="scv-stats">
        <div class="scv-stat"><div class="scv-stat-k">Stores</div><div class="scv-stat-v">{{ number_format($metrics['stores']) }}</div></div>
        <div class="scv-stat"><div class="scv-stat-k">90-day revenue</div><div class="scv-stat-v">{{ $this->money($metrics['revenue']) }}</div></div>
        <div class="scv-stat"><div class="scv-stat-k">Avg / store</div><div class="scv-stat-v">{{ $this->money($metrics['avg_revenue']) }}</div></div>
        <div class="scv-stat"><div class="scv-stat-k">90-day units</div><div class="scv-stat-v">{{ number_format($metrics['units']) }}</div></div>
        <div class="scv-stat"><div class="scv-stat-k">SKUs</div><div class="scv-stat-v">{{ number_format($metrics['skus']) }}</div></div>
    </div>

    {{-- Why this is a cluster --}}
    <div class="scv-card">
        <p class="scv-h">Why these stores are grouped</p>
        @if ($isDemand)
            <p class="scv-muted" style="margin:0 0 1rem;">Grouped by how they trade — the behavioural profile below is what these stores share.</p>
            <div class="scv-props">
                @if (! empty($params['size_tier']))<div><div class="scv-prop-k">Size</div><div class="scv-prop-v">{{ ucfirst($params['size_tier']) }}</div></div>@endif
                @if (! empty($params['price_tier']))<div><div class="scv-prop-k">Price</div><div class="scv-prop-v">{{ ucfirst($params['price_tier']) }}</div></div>@endif
                @if (! empty($params['basket_tier']))<div><div class="scv-prop-k">Basket</div><div class="scv-prop-v">{{ ucfirst($params['basket_tier']) }}</div></div>@endif
                @if (! empty($params['dominant_segment']))<div><div class="scv-prop-k">Demand</div><div class="scv-prop-v">{{ ucfirst($params['dominant_segment']) }}</div></div>@endif
                @if (isset($params['avg_basket_value']))<div><div class="scv-prop-k">Avg basket</div><div class="scv-prop-v">{{ $this->money($params['avg_basket_value']) }}</div></div>@endif
                @if (isset($params['avg_selling_price']))<div><div class="scv-prop-k">Avg price</div><div class="scv-prop-v">{{ $this->money($params['avg_selling_price']) }}</div></div>@endif
            </div>
        @elseif ($isCustom)
            <p class="scv-muted" style="margin:0;">A group you created. Its members are pinned, so the nightly rebuild keeps them here.</p>
        @else
            <div class="scv-props">
                <div><div class="scv-prop-k">Format</div><div class="scv-prop-v">{{ $params['format'] ?? '—' }}</div></div>
                <div><div class="scv-prop-k">Region</div><div class="scv-prop-v">{{ $params['region'] ?? '—' }}</div></div>
            </div>
            <p class="scv-muted" style="margin:1rem 0 0;">Stores sharing these master-data attributes — a like-for-like peer group.</p>
        @endif
    </div>

    {{-- Member stores with profiles --}}
    <div class="scv-card">
        <p class="scv-h">Member stores ({{ $record->stores->count() }})</p>
        <div class="scv-scroll">
            <table class="scv-table">
                <thead>
                    <tr>
                        <th>Store</th>
                        <th>Profile</th>
                        <th>Price</th>
                        <th>Demand</th>
                        <th class="scv-num">90-day revenue</th>
                        <th class="scv-num">Avg basket</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($record->stores as $store)
                        @php $f = $store->feature; @endphp
                        <tr>
                            <td>
                                <div class="scv-store-name">{{ $store->name }}</div>
                                @if ($store->city)<div class="scv-store-sub">{{ $store->city }}</div>@endif
                            </td>
                            <td>@if ($f && $f->descriptor)<span class="scv-muted">{{ $f->descriptor }}</span>@else<span class="scv-muted">Not yet profiled</span>@endif</td>
                            <td>@if ($f && $f->price_tier)<span class="{{ $pillClass($f->price_tier) }}">{{ ucfirst($f->price_tier) }}</span>@else—@endif</td>
                            <td>@if ($f && $f->dominant_segment)<span class="scv-pill">{{ ucfirst($f->dominant_segment) }}</span>@else—@endif</td>
                            <td class="scv-num">{{ $f ? $this->money($f->revenue) : '—' }}</td>
                            <td class="scv-num">{{ $f ? $this->money($f->avg_basket_value) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>
</x-filament-panels::page>
