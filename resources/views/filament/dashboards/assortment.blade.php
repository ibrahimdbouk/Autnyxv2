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
                                <td style="white-space:nowrap"><x-ui.badge :color="match($g->type) { 'add' => 'success', 'delist' => 'warning', default => 'danger' }">{{ \App\Models\AssortmentGap::TYPES[$g->type] }}</x-ui.badge></td>
                                <td><a class="ax-fw-600" href="{{ \App\Filament\Pages\AssortmentDecision::getUrl(['decision' => $g->id]) }}" wire:navigate>{{ $g->product?->name ?? $g->sku }}</a>
                                    <div class="ax-faint ax-text-xs">{{ $g->store?->name }} · {{ $g->product?->category }}</div></td>
                                <td class="ax-text-sm ax-muted">{{ $g->explanation['evidence'][0] ?? '' }}</td>
                                <td class="ax-num" style="white-space:nowrap">{{ $g->valueRange($currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($health !== [])
        <x-ui.card title="Range health — weakest first">
            <div class="ax-scroll-x">
                <table class="ax-table">
                    <thead><tr><th>Stores</th><th>Category</th><th class="ax-num">Core range carried</th><th class="ax-num">Slow sellers</th><th class="ax-num">Keep running out</th></tr></thead>
                    <tbody>
                        @foreach($health as $h)
                            <tr>
                                <td>{{ $h['group'] }}</td>
                                <td class="ax-fw-600">{{ $h['category'] }}</td>
                                <td class="ax-num">{{ $pct($h['coverage']) }}</td>
                                <td class="ax-num">{{ $pct($h['tail']) }}</td>
                                <td class="ax-num">{{ $pct($h['stockout']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="ax-faint ax-text-xs ax-mt-2">Core range = products at least half the similar stores carry. Slow sellers = carried but selling under 0.3× similar stores.</p>
        </x-ui.card>
    @endif
@else
    <x-ui.section
        kicker="Assortment"
        title="Range data readiness"
        :sub="$asOf ? 'Based on data up to ' . \Illuminate\Support\Carbon::parse($asOf)->format('j M Y') : null" />

    @if($checks !== [])
        <div class="ax-grid ax-grid-4">
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
                <p class="ax-text-sm ax-mt-3" style="text-align:center">
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
