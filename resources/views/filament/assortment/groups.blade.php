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
