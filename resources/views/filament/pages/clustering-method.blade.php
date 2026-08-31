<x-filament-panels::page>
<style>
    .clm-wrap { display:flex; flex-direction:column; gap:1.25rem; }
    .clm-card {
        background:var(--ax-bg, #fff); border:1px solid var(--ax-line, #e5e7eb);
        border-radius:var(--ax-radius-lg, .9rem); box-shadow:var(--ax-shadow, 0 1px 2px rgba(0,0,0,.05));
        padding:1.25rem 1.5rem;
    }
    .clm-active { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
    .clm-badge {
        display:inline-flex; align-items:center; gap:.35rem; font-size:.75rem; font-weight:700;
        letter-spacing:.02em; color:var(--ax-accent-fg, #fff); background:var(--ax-accent, #7c3aed);
        padding:.28rem .8rem; border-radius:var(--ax-radius-pill, 9999px);
    }
    .clm-badge--muted { background:var(--ax-neutral-soft, #f3f4f6); color:var(--ax-neutral-fg, #374151); }
    .clm-note { color:var(--ax-muted, #6b7280); margin:.5rem 0 0; font-size:.95rem; }
    .clm-warn {
        background:var(--ax-info-soft, #fef3e2); border:1px solid var(--ax-line, #f0d9b5);
        color:var(--ax-info-fg, #92400e); border-radius:var(--ax-radius, .6rem);
        padding:.85rem 1.1rem; font-size:.9rem;
    }
    .clm-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
    @media (max-width: 820px){ .clm-grid { grid-template-columns:1fr; } }
    .clm-h { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:var(--ax-faint, #9ca3af); font-weight:700; margin:0 0 .2rem; }
    .clm-sub { color:var(--ax-muted, #6b7280); font-size:.85rem; margin:0 0 .9rem; }
    .clm-list { display:flex; flex-direction:column; gap:.5rem; }
    .clm-row {
        display:flex; align-items:center; justify-content:space-between; gap:.75rem;
        padding:.6rem .8rem; border:1px solid var(--ax-line, #eee); border-radius:var(--ax-radius, .6rem);
    }
    .clm-row-name { font-weight:600; color:var(--ax-ink, #111827); font-size:.92rem; }
    .clm-row-meta { color:var(--ax-muted, #6b7280); font-size:.78rem; margin-top:.1rem; }
    .clm-count { font-variant-numeric:tabular-nums; font-weight:700; color:var(--ax-ink, #111827); white-space:nowrap; }
    .clm-count small { font-weight:500; color:var(--ax-faint, #9ca3af); }
    .clm-table { width:100%; border-collapse:collapse; }
    .clm-table th { text-align:left; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em;
        color:var(--ax-faint, #9ca3af); font-weight:700; padding:.5rem .6rem; border-bottom:1px solid var(--ax-line, #e5e7eb); }
    .clm-table td { padding:.6rem .6rem; border-bottom:1px solid var(--ax-line, #f1f1f4); font-size:.9rem; color:var(--ax-ink, #111827); vertical-align:top; }
    .clm-table tr:last-child td { border-bottom:0; }
    .clm-split { display:inline-flex; align-items:center; padding:.12rem .55rem; border-radius:var(--ax-radius-pill, 9999px);
        font-size:.7rem; font-weight:700; }
    .clm-split--yes { background:var(--ax-accent-soft, #ede9fe); color:var(--ax-accent-strong, #6d28d9); }
    .clm-split--no  { background:var(--ax-neutral-soft, #f3f4f6); color:var(--ax-neutral-fg, #6b7280); }
    .clm-breakdown { color:var(--ax-muted, #6b7280); font-size:.82rem; }
    .clm-scroll { overflow-x:auto; }
    .clm-muted { color:var(--ax-muted, #6b7280); }
</style>

@php
    $cmp = $this->comparison();
    $active = $cmp['active'];
    $isDemand = $active === 'demand';
    $splitCount = collect($cmp['crosstab'])->where('is_split', true)->count();
@endphp

<div class="clm-wrap">

    {{-- Active method --}}
    <div class="clm-card">
        <div class="clm-active">
            <span class="clm-muted">Currently using</span>
            <span class="clm-badge">{{ $isDemand ? 'Behavioural clustering' : 'Structural clustering' }}</span>
        </div>
        <p class="clm-note">
            @if ($isDemand)
                Stores are grouped by how they actually trade — velocity, basket, price, assortment. Switch back to structural any time.
            @else
                Stores are grouped by format and region. Behavioural clustering groups them by how they actually trade — often a truer peer set. Preview it below.
            @endif
        </p>
    </div>

    @unless ($cmp['demand_available'])
        <div class="clm-warn">
            Behavioural clustering needs store profiles, which are computed overnight. Once profiling has run for your stores, you’ll be able to preview and switch here.
        </div>
    @endunless

    {{-- Side-by-side --}}
    <div class="clm-grid">
        <div class="clm-card">
            <p class="clm-h">Structural</p>
            <p class="clm-sub">{{ count($cmp['attribute']) }} group(s) — by format + region</p>
            <div class="clm-list">
                @forelse ($cmp['attribute'] as $g)
                    <div class="clm-row">
                        <div><div class="clm-row-name">{{ $g['label'] }}</div></div>
                        <div class="clm-count">{{ count($g['store_ids']) }} <small>stores</small></div>
                    </div>
                @empty
                    <p class="clm-muted">No stores to cluster yet.</p>
                @endforelse
            </div>
        </div>

        <div class="clm-card">
            <p class="clm-h">Behavioural</p>
            <p class="clm-sub">{{ count($cmp['demand']) }} group(s) — by how stores trade</p>
            <div class="clm-list">
                @forelse ($cmp['demand'] as $g)
                    @php $p = is_array($g['params'] ?? null) ? $g['params'] : []; @endphp
                    <div class="clm-row">
                        <div>
                            <div class="clm-row-name">{{ $g['label'] }}</div>
                            @if (isset($p['avg_basket_value']))
                                <div class="clm-row-meta">avg basket {{ $this->money($p['avg_basket_value']) }}@if (! empty($p['dominant_segment'])) · {{ $p['dominant_segment'] }} demand @endif</div>
                            @endif
                        </div>
                        <div class="clm-count">{{ count($g['store_ids']) }} <small>stores</small></div>
                    </div>
                @empty
                    <p class="clm-muted">Not available yet — store profiles compute overnight.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Regrouping --}}
    @if (! empty($cmp['demand']))
        <div class="clm-card">
            <p class="clm-h">What behavioural clustering changes</p>
            <p class="clm-sub">
                {{ $splitCount }} of {{ count($cmp['crosstab']) }} structural group(s) split into different behavioural peers — stores that look identical on paper but trade differently.
            </p>
            <div class="clm-scroll">
                <table class="clm-table">
                    <thead>
                        <tr><th>Structural group</th><th>Stores</th><th>Behaviourally</th><th>Splits into</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($cmp['crosstab'] as $row)
                            <tr>
                                <td style="font-weight:600;">{{ $row['label'] }}</td>
                                <td class="clm-count">{{ $row['count'] }}</td>
                                <td>
                                    <span class="clm-split {{ $row['is_split'] ? 'clm-split--yes' : 'clm-split--no' }}">
                                        {{ $row['is_split'] ? 'SPLIT' : 'intact' }}
                                    </span>
                                </td>
                                <td class="clm-breakdown">
                                    @foreach ($row['split'] as $label => $n)
                                        {{ $n }}× {{ $label }}@if (! $loop->last); @endif
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
</x-filament-panels::page>
