<x-filament-panels::page>
@php
    $leads = static fn (array $g) => collect($g['profile']['leads'] ?? [])->map(fn ($l) => $l['category'] . ' ' . (int) round($l['index'] * 100) . '%')->implode(' · ');
    $price = static fn (array $g) => isset($g['profile']['price_index']) ? 'prices ' . (int) round($g['profile']['price_index'] * 100) . '% of the chain' : null;
@endphp
<div class="ax-stack">
    <x-ui.card title="In force">
            @if($active)
                <p class="ax-m-0"><strong>{{ $active->label() }}</strong>: {{ count($active->groups) }} groups, approved {{ $active->decided_at?->format('j M Y') }}{{ $active->decider ? ' by ' . $active->decider->name : '' }}.</p>
                <ul class="ax-list ax-text-sm ax-mt-2">
                    @foreach($active->groups as $g)
                        <li><span class="ax-fw-600">{{ $g['label'] }}</span> · {{ count($g['members']) }} stores <span class="ax-faint">{{ collect([$leads($g), $price($g)])->filter()->implode(' · ') }}</span></li>
                    @endforeach
                </ul>
            @else
                <p class="ax-m-0 ax-text-sm">No store groups of its own: stores are compared within the platform's store clusters, or with every store of their format.</p>
            @endif
    </x-ui.card>
    <x-ui.card :title="$proposal ? 'Proposal ' . $proposal->label() : 'Proposal'">
            @if($proposal)
                @php $c = $proposal->comparison; @endphp
                <div class="ax-grid ax-grid-3">
                    <x-ui.stat label="Stores with new peers" :value="$c['moved'] . ' of ' . $c['total']" foot="Fewer than half their peers the same" />
                    <x-ui.stat label="Groups" :value="$c['groups_now'] . ' → ' . $c['groups_proposed']" :foot="'sizes ' . (min($c['sizes_proposed'] ?: [0])) . '–' . (max($c['sizes_proposed'] ?: [0]))" />
                    <x-ui.stat label="Not judged" :value="$c['unjudged_now'] . ' → ' . $c['unjudged_proposed']" foot="Stores with too few similar stores" />
                </div>
                <ul class="ax-list ax-text-sm ax-mt-3">
                    @foreach($proposal->groups as $g)
                        <li><span class="ax-fw-600">{{ $g['label'] }}</span> · {{ count($g['members']) }} stores <span class="ax-faint">{{ collect([$leads($g), $price($g)])->filter()->implode(' · ') }}</span></li>
                    @endforeach
                </ul>
                <p class="ax-faint ax-text-xs ax-mt-2">Worked out {{ $proposal->created_at?->format('j M Y') }} from the last 90 days of sales. A label names the categories the group sells more of than the chain (as a share of its sales) and its price level.</p>
            @else
                <p class="ax-m-0 ax-text-sm ax-muted">None waiting. Work out a proposal to compare it with the groups in force.</p>
            @endif
    </x-ui.card>

    @if($viz)
        @php
            $vizTitle = ($shown->status === \App\Models\AssortmentGrouping::PROPOSED ? 'Proposal ' : 'In force ') . $shown->label();
            $pctFmt = fn ($v) => (int) round($v * 100) . '%';
        @endphp
        <div class="ax-viz-split ax-viz-split--wide">
            <x-ui.card :title="'Stores per group · ' . $vizTitle">
                <x-ui.viz.donut :segments="$viz['donut']" :center="(string) $viz['stores']" sub="stores" label="Stores per group" />
            </x-ui.card>
            <x-ui.card title="Every store: price level against its mix">
                @if($viz['scatter'] !== [])
                    <x-ui.viz.scatter :points="$viz['scatter']" :series="$viz['series']" x-label="Price level against the chain" y-label="Sales in a different mix"
                        :x-fmt="$pctFmt" :y-fmt="$pctFmt" :ref-x="['at' => 1.0, 'label' => 'chain']" :y-from="0" label="Stores by price level and mix" height="300" />
                @else
                    <x-ui.empty title="No sales in the last 90 days">Stores are placed once they have sales.</x-ui.empty>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card :title="'What each group sells against the chain · ' . $vizTitle">
            <x-ui.viz.heatmap :rows="$viz['heat']['rows']" :cols="$viz['heat']['cols']" :cells="$viz['heat']['cells']" mode="diverging" :spread="0.5"
                :legend="['low' => 'Sells less of it than the chain', 'mid' => 'About the chain (100)', 'high' => 'Sells more of it']" label="Category index by group" />
            <p class="ax-faint ax-text-xs ax-mt-2">Each cell is the category's share of the group's sales against its share of the chain's (100 = the same). A group's label names its categories at 115 or more. The scatter places each store by its average price per unit against the chain's median store, and by the share of its sales that sits in a different mix of categories than the chain's (0% = exactly the chain's mix). Last 90 days{{ $viz['as_of'] ? ' to ' . \Illuminate\Support\Carbon::parse($viz['as_of'])->format('j M Y') : '' }}.</p>
        </x-ui.card>

        @if($viz['map'] !== [])
            <x-ui.card title="Where the stores are">
                <x-ui.viz.scatter :points="$viz['map']" :series="$viz['series']" :geo="true" label="Stores on a schematic map, coloured by group" height="380" :width="760" />
                <p class="ax-faint ax-text-xs ax-mt-2">Schematic: each store at its coordinates from the store file, to scale but without a base map. Groups are formed by what stores sell, not where they are.</p>
            </x-ui.card>
        @endif
    @endif

    {{ $this->table }}

    @if($history->isNotEmpty())
        <x-ui.card title="History">
            <div class="ax-scroll-x">
                <table class="ax-table">
                    <thead><tr><th>Version</th><th>Status</th><th class="ax-num">Groups</th><th>Decided</th><th>Note</th></tr></thead>
                    <tbody>
                        @foreach($history as $h)
                            <tr>
                                <td class="ax-mono">{{ $h->label() }}</td>
                                <td>{{ \App\Models\AssortmentGrouping::STATUSES[$h->status] ?? $h->status }}</td>
                                <td class="ax-num">{{ count($h->groups) }}</td>
                                <td class="ax-text-sm">{{ $h->decided_at?->format('j M Y') ?? '—' }}{{ $h->decider ? ' · ' . $h->decider->name : '' }}</td>
                                <td class="ax-text-sm ax-muted">{{ $h->decision_note }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
</x-filament-panels::page>
