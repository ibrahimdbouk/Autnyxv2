<x-filament-panels::page>
<div class="ax-stack">
    @if($options === [])
        <x-ui.card><x-ui.empty title="No stores">There are no stores you can see yet.</x-ui.empty></x-ui.card>
    @else
        <div style="max-width:24rem">
            <label class="ax-text-sm ax-muted" for="ax-store-pick">Store</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="ax-store-pick" wire:model.live="store">
                    @foreach($options as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        @if($profile)
            @php $p = $profile; $cov = $p['core'] > 0 ? (int) round($p['coreHas'] / $p['core'] * 100) : null; @endphp
            <div class="ax-grid ax-grid-4">
                <x-ui.stat label="Products carried" :value="number_format($p['carried'])"
                    :foot="$p['peerMedian'] !== null ? 'Similar stores carry ' . number_format($p['peerMedian']) : 'No similar stores to compare with'" />
                <x-ui.stat label="Core range carried" :value="$cov !== null ? $cov . '%' : '—'" :color="$cov === null ? null : ($cov >= 90 ? 'success' : 'warning')"
                    :foot="$p['core'] > 0 ? $p['coreHas'] . ' of the ' . $p['core'] . ' products most similar stores carry' : 'Needs a run first'" />
                <x-ui.stat label="Open decisions" :value="(string) $p['open']->flatten()->count()" :href="$p['listUrl']" foot="Open in Decisions ↗" />
                <x-ui.stat label="Open value a year" :value="\App\Support\Money::displayCompact($p['openValue'], $currency)" foot="Middle of each decision's range" />
            </div>

            <x-ui.card title="Compared with">
                @if($p['group'])
                    <p class="ax-m-0 ax-fw-600">{{ $p['group']['label'] }}</p>
                    <p class="ax-muted ax-text-sm ax-mt-1">{{ implode(' · ', $p['peers']) }}</p>
                @else
                    <p class="ax-m-0 ax-muted">This store has too few similar stores to be compared (fewer than {{ config('assortment.min_peer_group', 5) }} in its cluster and in its format).</p>
                @endif
            </x-ui.card>

            @foreach(\App\Models\AssortmentGap::TYPES as $type => $label)
                @php $list = $p['open'][$type] ?? collect(); @endphp
                @if($list->isNotEmpty())
                    <x-ui.card :title="$label . ' (' . $list->count() . ')'">
                        <div class="ax-scroll-x">
                            <table class="ax-table">
                                <tbody>
                                    @foreach($list->take(10) as $g)
                                        <tr>
                                            <td><a class="ax-fw-600" href="{{ \App\Filament\Pages\AssortmentDecision::getUrl(['decision' => $g->id]) }}" wire:navigate>{{ $g->product?->name ?? $g->sku }}</a>
                                                <div class="ax-faint ax-text-xs">{{ $g->product?->category }}</div></td>
                                            <td class="ax-text-sm">{{ $g->explanation['evidence'][0] ?? '' }}</td>
                                            <td class="ax-num" style="white-space:nowrap">{{ $g->valueRange($currency) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                @endif
            @endforeach
        @endif
    @endif
</div>
</x-filament-panels::page>
