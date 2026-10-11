<x-filament-panels::page>
@if(! $g)
    <x-ui.empty title="No benchmarks yet">Benchmarks are built by each Assortment run, for every group of similar stores big enough to compare.</x-ui.empty>
@else
@php
    $money = static fn ($v) => $v === null ? '—' : \App\Support\Money::compact((float) $v, $currency);
    $pct = static fn ($v) => $v === null ? '—' : (int) round((float) $v * 100) . '%';
    $num = static fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
    $bandLabel = ['core' => 'Core', 'expected' => 'Expected', 'niche' => 'Niche'];
    $bandColor = ['core' => 'success', 'expected' => 'info', 'niche' => null];
    $typeLabel = ['add' => 'add', 'delist' => 'delist', 'stockout_hidden' => 'stockout fix'];
@endphp
<div class="ax-stack">
    <x-ui.card>
        <div class="ax-grid ax-grid-3">
            <label class="ax-gap-1 ax-stack">
                <span class="ax-text-xs ax-faint">Group of similar stores</span>
                <select class="fi-select-input" wire:model.live="group" aria-label="Group of similar stores">
                    @foreach($groups as $key => $gr)
                        <option value="{{ $key }}">{{ $gr['label'] }} · {{ count($gr['members']) }} stores</option>
                    @endforeach
                </select>
            </label>
            <label class="ax-gap-1 ax-stack">
                <span class="ax-text-xs ax-faint">Category</span>
                <select class="fi-select-input" wire:model.live="category" aria-label="Category">
                    @foreach($categories as $cat)
                        <option value="{{ $cat['category'] }}">{{ $cat['category'] }} · {{ number_format($cat['n']) }} products</option>
                    @endforeach
                </select>
            </label>
            <label class="ax-gap-1 ax-stack">
                <span class="ax-text-xs ax-faint">Compare one store (optional)</span>
                <select class="fi-select-input" wire:model.live="store" aria-label="Compare one store">
                    <option value="">No store</option>
                    @foreach($stores as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </x-ui.card>

    <div class="ax-grid ax-grid-4">
        <x-ui.stat label="Core range" :value="number_format($counts['core'])" :foot="'Carried by at least ' . (int) (\App\Filament\Pages\AssortmentBenchmarks::CORE * 100) . '% of the ' . count($g['members']) . ' stores'" />
        <x-ui.stat label="Expected range" :value="number_format($counts['core'] + $counts['expected'])" :foot="'Carried by at least half · core included'" />
        <x-ui.stat label="Niche" :value="number_format($counts['niche'])" foot="Carried by fewer than half" />
        @if($coverage)
            <x-ui.stat :label="'At ' . $storeName" :value="$coverage['carried'] . ' of ' . $coverage['expected']"
                :foot="'of the expected range · core ' . $coverage['core_carried'] . ' of ' . $coverage['core'] . ' · ' . $coverage['extra'] . ' niche product(s) too'" />
        @else
            <x-ui.stat label="Compare a store" value="—" foot="Pick one above to see what it carries of the expected range" />
        @endif
    </div>

    @if($bars !== [])
        {{-- v2.5 — how widely the products are carried, and who carries what. --}}
        <div class="ax-viz-split ax-viz-split--wide">
            <x-ui.card title="How widely the top products are carried">
                <x-ui.viz.bars :items="$bars" :max="1.0" :width="520" label="Share of the group's stores carrying each product"
                    :markers="[['at' => \App\Filament\Pages\AssortmentBenchmarks::EXPECTED, 'label' => 'expected'], ['at' => \App\Filament\Pages\AssortmentBenchmarks::CORE, 'label' => 'core']]" />
                <div class="ax-viz-legend" aria-hidden="true">
                    <span><svg class="ax-viz-key" viewBox="0 0 10 10"><rect class="ax-viz-s3" width="10" height="10" rx="2" /></svg>Core</span>
                    <span><svg class="ax-viz-key" viewBox="0 0 10 10"><rect class="ax-viz-s2" width="10" height="10" rx="2" /></svg>Expected</span>
                    <span><svg class="ax-viz-key" viewBox="0 0 10 10"><rect class="ax-viz-s4" width="10" height="10" rx="2" /></svg>Niche</span>
                </div>
            </x-ui.card>
            <x-ui.card title="Who carries what">
                <x-ui.viz.heatmap :rows="$matrix['rows']" :cols="$matrix['cols']" :cells="$matrix['cells']" mode="good" :cell-w="34" :cell-h="22"
                    :legend="['items' => [['class' => 'ax-viz-heat-good', 'opacity' => 0.9, 'label' => 'Carried'], ['class' => 'ax-viz-heat-bad', 'opacity' => 0.9, 'label' => 'Core, not carried'], ['class' => 'ax-viz-heat-bad', 'opacity' => 0.42, 'label' => 'Expected, not carried']]]" label="Products carried by each store of the group" />
                <p class="ax-faint ax-text-xs ax-mt-2">+ add proposed · − delist proposed · ! keeps running out. A niche product a store does not carry is left blank.</p>
            </x-ui.card>
        </div>
    @endif

    <div class="ax-split">
        <x-ui.card title="The expected range">
            <div class="ax-between ax-wrap ax-mb-2">
                <div class="ax-gap-1 ax-row ax-wrap" role="tablist">
                    @foreach(['expected' => 'Expected', 'core' => 'Core only', 'niche' => 'Niche', 'all' => 'All'] as $k => $l)
                        <button type="button" @class(['ax-btn', 'ax-btn--on' => $show === $k]) role="tab" aria-selected="{{ $show === $k ? 'true' : 'false' }}" wire:click="$set('show', '{{ $k }}')">{{ $l }}</button>
                    @endforeach
                </div>
                <input type="search" class="ax-maxw-16 fi-input" wire:model.live.debounce.300ms="search" placeholder="Find a product" aria-label="Find a product">
            </div>
            <div class="ax-scroll-x">
                <table class="ax-table">
                    <thead><tr>
                        <th class="ax-minw-12">Product</th><th class="ax-num">Carried by</th><th class="ax-num">Sales a year per store</th>
                        <th class="ax-num">Share of category</th>@if($store)<th>{{ $storeName }}</th>@endif
                    </tr></thead>
                    <tbody>
                        @forelse($rows as $r)
                            <tr wire:key="bm-{{ md5($r->sku) }}" @class(['ax-row-hl' => $focus === $r->sku])>
                                <td>
                                    <button type="button" class="ax-ink ax-fw-600" wire:click="focusOn(@js($r->sku))">{{ $r->name ?: $r->sku }}</button>
                                    <div class="ax-faint ax-text-xs"><span class="ax-mono">{{ $r->sku }}</span>{{ $r->subcategory ? ' · ' . $r->subcategory : '' }}</div>
                                </td>
                                <td class="ax-nowrap ax-num"><x-ui.badge :color="$bandColor[$r->band]">{{ $bandLabel[$r->band] }}</x-ui.badge> {{ $r->carrying }} of {{ $r->group_size }}</td>
                                <td class="ax-num">{{ $money($r->sales_a_year) }}</td>
                                <td class="ax-num">{{ $pct($r->share_index_median) }}</td>
                                @if($store)
                                    <td class="ax-text-xs">
                                        @if(isset($here[$r->sku]))<span class="ax-muted">Carried</span>@else<strong>Not carried</strong>@endif
                                        @if(isset($hereDecisions[$r->sku]))
                                            · <a href="{{ $decisionUrl($hereDecisions[$r->sku]->id) }}" wire:navigate>{{ ucfirst($typeLabel[$hereDecisions[$r->sku]->type] ?? $hereDecisions[$r->sku]->type) }} proposed</a>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $store ? 5 : 4 }}" class="ax-muted ax-text-sm">No product here.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($total > $rows->count())
                <div class="ax-mt-2"><button type="button" class="ax-btn" wire:click="more">Show {{ min(100, $total - $rows->count()) }} more (of {{ number_format($total) }})</button></div>
            @endif
            <p class="ax-faint ax-text-xs ax-mt-2">From the group's own sales and stock <x-ui.badge color="observed">Observed</x-ui.badge>, as the last Assortment run measured them: sales and share are the typical (median) store that sells the product well enough to judge, counting only days it was in stock and outside promotions.</p>
        </x-ui.card>

        <div class="ax-stack">
            @if($focusRow)
                @php $f = $focusRow; $d = $byProduct[$f->sku] ?? []; @endphp
                <x-ui.card :title="$f->name ?: $f->sku">
                    <div class="ax-between ax-wrap ax-mb-2">
                        <span class="ax-faint ax-text-xs">{{ collect([$f->subcategory, $f->brand, $f->pack_size])->filter()->implode(' · ') }}{{ $f->selling_price ? ' · ' . $money($f->selling_price) : '' }}</span>
                        <button type="button" class="ax-btn" wire:click="focusOn(null)">Close</button>
                    </div>
                    <div class="ax-stack ax-text-sm">
                        <p class="ax-m-0"><x-ui.badge :color="$bandColor[$f->band]">{{ $bandLabel[$f->band] }}</x-ui.badge>
                            Carried by <strong>{{ $f->carrying }} of {{ $f->group_size }}</strong> stores in {{ $g['label'] }} ({{ $pct($f->carried_share) }}).
                            {{ $f->band === 'core' ? 'Almost every store like these carries it: it belongs in the range.' : ($f->band === 'expected' ? 'Most stores like these carry it: expected in the range.' : 'Fewer than half carry it: a local or optional choice.') }}</p>
                        @if($f->qualifying > 0)
                            <p class="ax-m-0 ax-muted">At the {{ $f->qualifying }} store(s) that sell it well enough to judge, the typical one sells {{ $num($f->units_per_day_median) }} a day when in stock ({{ $money($f->sales_a_year) }} a year), {{ $pct($f->share_index_median) }} of the category's sales{{ $f->share_index_p25 !== null && $pct($f->share_index_p25) !== $pct($f->share_index_p75) ? ' (' . $pct($f->share_index_p25) . '–' . $pct($f->share_index_p75) . ' across them)' : '' }}, and is in stock {{ $pct($f->availability_median) }} of days.</p>
                        @else
                            <p class="ax-m-0 ax-muted">No store sells it long enough, or in stock often enough, to judge how well it sells.</p>
                        @endif
                        <p class="ax-m-0 ax-muted">Lifecycle: {{ $f->lifecycle ? ucfirst(str_replace('_', ' ', $f->lifecycle)) : 'not judged yet' }}.</p>
                        <p class="ax-m-0 ax-muted">
                            @if($d === [])
                                The engine proposes nothing for it in this group.
                            @else
                                The engine proposes: {{ collect($d)->map(fn ($n, $t) => $n . ' ' . ($typeLabel[$t] ?? $t) . ($n > 1 ? 's' : ''))->implode(', ') }} across the group's stores.
                            @endif
                        </p>
                        @if($store)
                            <p class="ax-m-0"><strong>{{ $storeName }}:</strong> {{ isset($here[$f->sku]) ? 'carries it.' : 'does not carry it.' }}
                                @if(isset($hereDecisions[$f->sku]))<a href="{{ $decisionUrl($hereDecisions[$f->sku]->id) }}" wire:navigate>Open the {{ $typeLabel[$hereDecisions[$f->sku]->type] ?? 'decision' }}</a>@endif</p>
                        @endif
                    </div>
                </x-ui.card>
            @endif
            <x-ui.card title="How the expected range is read">
                <ul class="ax-list ax-text-sm">
                    <li><strong>Core</strong>: carried by at least {{ (int) (\App\Filament\Pages\AssortmentBenchmarks::CORE * 100) }}% of the group's stores.</li>
                    <li><strong>Expected</strong>: carried by at least half (core included).</li>
                    <li><strong>Niche</strong>: fewer than half — local or optional.</li>
                    <li>A product being in the expected range is not a decision on its own: an add is proposed only when the store's own category sales say it would sell, net of what it takes from the shelf.</li>
                </ul>
                <p class="ax-faint ax-text-xs ax-mt-2">Groups are the approved store groups (Assortment → Store Groups) when there are some, else the platform's store clusters, or stores of the same format when a cluster is too small. A group needs at least {{ (int) config('assortment.min_peer_group', 5) }} stores to be compared.</p>
            </x-ui.card>
        </div>
    </div>
</div>
@endif
</x-filament-panels::page>
