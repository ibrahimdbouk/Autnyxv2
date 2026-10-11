{{-- The Assortment app's dashboard. Before the validation gate passes it shows
     only data readiness (plus review progress for admins); once live, the range
     opportunity, the decisions worth making now, range health and results. --}}
@php $pct = static fn ($v) => $v === null ? '—' : (int) round($v * 100) . '%'; @endphp

@if($live)
    <div class="ax-grid ax-grid-3">
        @foreach($kpis as $k)
            <x-ui.stat :label="$k['label']" :value="$k['value']" :foot="$k['foot']" :href="$k['url']" :color="$k['color']" />
        @endforeach
    </div>

    <x-ui.card title="Worth deciding now">
        <x-slot:actions><a href="{{ $decisionsUrl }}" class="ax-text-sm" wire:navigate>All decisions →</a></x-slot:actions>
        @if($queue->isEmpty())
            <x-ui.empty title="Nothing to decide">New decisions appear after the nightly run.</x-ui.empty>
        @else
            <div class="ax-scroll-x">
                <table class="ax-table">
                    <tbody>
                        @foreach($queue as $g)
                            <tr>
                                <td class="ax-nowrap"><x-ui.badge :color="match($g->type) { 'add' => 'success', 'delist' => 'warning', default => 'danger' }">{{ \App\Models\AssortmentGap::TYPES[$g->type] }}</x-ui.badge></td>
                                <td><a class="ax-fw-600" href="{{ \App\Filament\Pages\AssortmentDecision::getUrl(['decision' => $g->id]) }}" wire:navigate>{{ $g->product?->name ?? $g->sku }}</a>
                                    <div class="ax-faint ax-text-xs">{{ $g->store?->name }} · {{ $g->product?->category }}</div></td>
                                <td class="ax-text-sm ax-muted">{{ $g->explanation['evidence'][0] ?? '' }}</td>
                                <td class="ax-nowrap ax-num">{{ $g->valueRange($currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($healthMap)
        <x-ui.card title="Range health by category and peer group">
            <x-ui.viz.heatmap :rows="$healthMap['rows']" :cols="$healthMap['cols']" :cells="$healthMap['cells']" mode="good" :domain="$healthMap['domain']"
                :legend="$healthMap['domain'] ? ['low' => 'Weakest here (' . $pct($healthMap['domain'][0]) . ')', 'mid' => 'Middle', 'high' => 'Strongest here (' . $pct($healthMap['domain'][1]) . ')'] : ['low' => 'Little of the core range carried', 'mid' => 'About half', 'high' => 'All of it']" label="Core range carried, by category and peer group" />
            @if($health !== [])
                <p class="ax-text-sm ax-mt-3 ax-m-0"><span class="ax-fw-600 ax-ink">Weakest:</span>
                    @foreach($health as $h){{ $h['category'] }} in {{ $h['group'] }} {{ $pct($h['coverage']) }}@if(! $loop->last) · @endif @endforeach</p>
            @endif
            <p class="ax-faint ax-text-xs ax-mt-2">Each cell is the share of the core range (products at least half the similar stores carry) that the group's stores carry; hover for slow sellers (carried but selling under 0.3× similar stores) and products that keep running out. Weakest categories first.@if($healthMap['domain']) Shades run from the weakest to the strongest figure here, so small gaps show; the numbers are the real shares.@endif</p>
        </x-ui.card>
    @endif
@else
    <x-ui.section
        kicker="Assortment"
        title="Range data readiness"
        :sub="$asOf ? 'Based on data up to ' . \Illuminate\Support\Carbon::parse($asOf)->format('j M Y') : null" />

    @if($checks !== [])
        <div class="ax-grid ax-grid-5">
            @foreach($checks as $c)
                <x-ui.stat :label="$c['label']" :value="$c['value']" :color="$c['color']" :foot="$c['foot']" />
            @endforeach
        </div>
    @endif

    <x-ui.card>
        @if($run === null)
            <x-ui.empty title="Assortment is being set up">
                The first run happens overnight. It works out which products each store carries and which stores are similar enough to compare.
            </x-ui.empty>
        @elseif($reason)
            <x-ui.empty title="Waiting for data">
                {{ ucfirst($reason) }}.
            </x-ui.empty>
        @else
            <x-ui.empty title="Range decisions are being checked">
                Recommendations to add products, drop slow sellers and fix products that keep running out are prepared every night. They appear here once they have been reviewed with your team.
            </x-ui.empty>
            @if($gate && $validationUrl)
                <p class="ax-center ax-text-sm ax-mt-3">
                    Review so far:
                    @foreach($gate['types'] as $typeKey => $t)
                        {{ \App\Models\AssortmentGap::TYPES[$typeKey] }} {{ $t['needed'] === 0 ? 'none' : $t['reviewed'] . '/' . $t['needed'] }}@if(! $loop->last) · @endif
                    @endforeach
                    — <a href="{{ $validationUrl }}" wire:navigate>open Validation →</a>
                </p>
            @endif
        @endif
    </x-ui.card>
@endif
