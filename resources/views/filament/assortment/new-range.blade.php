<x-filament-panels::page>
@if(! $r)
    <x-ui.card><x-ui.empty title="Pick a range">Open one from <a href="{{ $backUrl }}" wire:navigate>new stores &amp; remodels</a>.</x-ui.empty></x-ui.card>
@else
@php
    $money = static fn ($v) => \App\Support\Money::compact((float) $v, $currency);
    $pct = static fn ($v) => (int) round((float) $v * 100) . '%';
    $bandLabel = ['core' => 'Core', 'expected' => 'Expected', 'local' => 'This store\'s own', 'niche' => 'Niche'];
    $bandColor = ['core' => 'success', 'expected' => 'info', 'local' => null, 'niche' => null];
    $stateLabel = ['start' => 'In the range', 'keep' => 'Keep', 'add' => 'Add', 'remove' => 'Goes'];
    $stateColor = ['start' => 'info', 'keep' => null, 'add' => 'success', 'remove' => 'warning'];
    $totals = collect($r->result);
    $range = static fn ($lo, $hi) => $money($lo) === $money($hi) ? 'about ' . $money($lo) : $money($lo) . '–' . $money($hi);
@endphp
<div class="ax-stack">
    <a href="{{ $backUrl }}" class="ax-text-sm ax-muted" wire:navigate>← All ranges</a>

    @if($own)
        <x-ui.card>
            <p class="ax-m-0 ax-text-sm"><strong>{{ $r->store?->name }} has traded 12 weeks or more.</strong> Its own Assortment run now judges its range against similar stores; this starting range is kept for the record.</p>
        </x-ui.card>
    @endif

    <div class="ax-grid ax-grid-4">
        <x-ui.stat label="Built from" :value="count($r->donors['stores'] ?? []) . ' stores'" :foot="$r->donors['label'] ?? ''" />
        <x-ui.stat label="Products" :value="number_format($totals->sum('count'))" :foot="number_format($totals->sum('core')) . ' core · ' . count($cats) . ' categories'" />
        <x-ui.stat label="Sales a year" :value="$range($totals->sum(fn ($c) => $c['sales'][0]), $totals->sum(fn ($c) => $c['sales'][2]))"
            foot="Estimated from the similar stores · typical store to the middle half" />
        @if($r->kind === 'remodel')
            <x-ui.stat label="Changes" :value="$totals->sum(fn ($c) => count(array_filter($c['products'], fn ($p) => $p['state'] === 'add'))) . ' in · ' . $totals->sum(fn ($c) => count($c['remove'] ?? [])) . ' out'"
                :foot="'Against what ' . ($r->store?->name ?? 'the store') . ' carries today'" />
        @else
            <x-ui.stat label="Data up to" :value="$r->as_of_date?->format('j M Y') ?? '—'" :foot="'Built ' . $r->created_at?->format('j M Y') . ($r->creator ? ' by ' . $r->creator->name : '')" />
        @endif
    </div>

    <x-ui.card title="By category">
        <div class="ax-row ax-wrap ax-gap-1 ax-mb-3" role="tablist">
            @foreach($cats as $cat)
                <button type="button" @class(['ax-btn', 'ax-btn--on' => $category === $cat]) role="tab" aria-selected="{{ $category === $cat ? 'true' : 'false' }}"
                    wire:click="$set('category', {{ \Illuminate\Support\Js::from($cat) }})">{{ $cat }} · {{ $r->result[$cat]['count'] }}</button>
            @endforeach
        </div>
        @if($c)
            <p class="ax-muted ax-text-sm ax-m-0">
                {{ $c['count'] }} products{{ $c['metres_used'] !== null ? ', ' . number_format($c['metres_used'], 2) . ' m of ' . number_format((float) $c['metres'], 2) . ' m (' . $c['metres_from'] . ')' : ', as many as the similar stores typically carry (' . $c['target'] . ')' }}.
                Sales {{ $range($c['sales'][0], $c['sales'][2]) }} a year, estimated.{{ ($c['deals'] ?? 0) ? ' ' . $c['deals'] . ' product(s) kept by a deal.' : '' }}
                @if($c['note']) {{ $c['note'] }} @endif
            </p>
            <div class="ax-scroll-x ax-mt-3">
                <table class="ax-table">
                    <thead><tr>
                        <th class="ax-minw-12">Product</th><th>Why</th><th class="ax-num">Similar stores carrying</th><th class="ax-num">Sales a year at a typical one</th>
                        @if($c['metres_used'] !== null)<th class="ax-num">Shelf</th>@endif<th></th>
                    </tr></thead>
                    <tbody>
                        @foreach(array_merge($c['products'], $c['remove'] ?? []) as $p)
                            <tr wire:key="nr-{{ md5($p['sku']) }}" @class(['ax-dim' => $p['state'] === 'remove'])>
                                <td><span class="ax-fw-600">{{ $p['name'] }}</span><div class="ax-faint ax-text-xs"><span class="ax-mono">{{ $p['sku'] }}</span>{{ $p['sub'] ? ' · ' . $p['sub'] : '' }}</div></td>
                                <td>
                                    @if(isset($p['deal']))<x-ui.badge color="accent">Deal</x-ui.badge>@endif
                                    <x-ui.badge :color="$bandColor[$p['band']] ?? null">{{ $bandLabel[$p['band']] ?? $p['band'] }}</x-ui.badge>
                                    @if(isset($p['deal']))<div class="ax-faint ax-text-xs ax-mt-1">{{ $p['deal'] }}</div>@endif
                                </td>
                                <td class="ax-num">{{ $p['carrying'] }} ({{ $pct($p['share']) }})</td>
                                <td class="ax-num ax-nowrap">{{ $p['mid'] > 0 ? $money($p['mid']) : '—' }}@if($p['high'] > $p['low'])<div class="ax-faint ax-text-xs">{{ $money($p['low']) }}–{{ $money($p['high']) }}</div>@endif</td>
                                @if($c['metres_used'] !== null)<td class="ax-num">{{ isset($p['metres']) ? number_format($p['metres'], 2) . ' m' . (($p['width_assumed'] ?? false) ? '*' : '') : '—' }}</td>@endif
                                <td><x-ui.badge :color="$stateColor[$p['state']] ?? null">{{ $stateLabel[$p['state']] ?? $p['state'] }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="ax-faint ax-text-xs ax-mt-2">From the similar stores' own sales <x-ui.badge color="observed">Observed</x-ui.badge>; for this store <x-ui.badge>Estimated</x-ui.badge>. Similar stores typically carry {{ $c['target'] }} products here{{ $c['donor_counts'] ? ' (' . min($c['donor_counts']) . '–' . max($c['donor_counts']) . ')' : '' }}.@if($c['metres_used'] !== null) Shelf = width × facings from the shelf files; * a width assumed from the category's average.@endif</p>
        @endif
    </x-ui.card>
</div>
@endif
</x-filament-panels::page>
