<x-filament-panels::page>
@php
    $tier = ['likely' => 'Likely', 'established' => 'Established'][$guardrails['delist_min_tier']] ?? $guardrails['delist_min_tier'];
@endphp
<div class="ax-stack">
    <div class="ax-grid ax-grid-3">
        <x-ui.stat label="Range file"
            :value="($listing->stores ?? 0) > 0 ? $listing->stores . ' store(s)' : 'Not loaded'"
            :foot="($listing->stores ?? 0) > 0
                ? number_format((int) $listing->carried) . ' carried · ' . number_format((int) $listing->not_carried) . ' not carried'
                : 'Without one, the range is worked out from sales and stock (columns: store, sku, carried Y/N)'" />
        <x-ui.stat label="Must-stock list" :value="number_format($mustCount) . ' product(s)'" foot="Never proposed for delisting (columns: sku, store optional, reason)" />
        <x-ui.stat label="Guardrails"
            :value="$tier . ' · ' . $guardrails['carried_window_days'] . ' days'"
            :foot="'Delist confidence · carried window · at most ' . $guardrails['max_adds_per_category'] . ' add(s) and ' . $guardrails['max_delists_per_category'] . ' delist(s) per category per store'" />
    </div>

    <x-ui.card title="Must-stock list">
        @if($mustStock->isEmpty())
            <x-ui.empty title="Nothing on the list">Add strategic, private-label, contract or regulatory products so they are never proposed for delisting.</x-ui.empty>
        @else
            <div class="ax-scroll-x">
                <table class="ax-table">
                    <thead><tr><th>SKU</th><th>Product</th><th>Store</th><th>Reason</th><th></th></tr></thead>
                    <tbody>
                        @foreach($mustStock as $m)
                            <tr wire:key="ms-{{ $m->id }}">
                                <td class="ax-mono">{{ $m->sku }}</td>
                                <td>{{ $names[$m->sku] ?? '—' }}</td>
                                <td>{{ $m->store?->name ?? 'Every store' }}</td>
                                <td class="ax-muted">{{ $m->reason ?? '—' }}</td>
                                <td><x-filament::button size="xs" color="gray" outlined wire:click="removeMustStock({{ $m->id }})" wire:confirm="Remove {{ $m->sku }} from the must-stock list?">Remove</x-filament::button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($mustCount > $mustStock->count())<p class="ax-faint ax-text-xs ax-mt-2">Showing the first {{ $mustStock->count() }} of {{ $mustCount }}.</p>@endif
        @endif
    </x-ui.card>

    <p class="ax-faint ax-text-xs">Everything else — how similar stores are chosen, the thresholds, how value is estimated — is set by Autnyx so results stay comparable, and is calibrated from measured outcomes.</p>
</div>
</x-filament-panels::page>
