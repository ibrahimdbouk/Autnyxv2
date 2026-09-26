<x-filament-panels::page>
<div class="ax-stack">
    <div class="ax-grid ax-grid-4">
        <x-ui.stat label="Tasks to do" :value="(string) $toDo" :color="$overdue > 0 ? 'warning' : null" :foot="$overdue > 0 ? $overdue . ' past their due date' : 'None overdue'" />
        <x-ui.stat label="Done" :value="(string) $done" foot="Measured 8 weeks after they are done" />
        <x-ui.stat label="Measured" :value="(string) $measured" foot="Against similar stores that did not change" />
        <x-ui.stat label="Measured result a year" :value="\App\Support\Money::displayCompact($uplift, $currency)" :color="$measured ? ($uplift >= 0 ? 'success' : 'danger') : null"
            :foot="$measured ? 'Expected at decision: ' . \App\Support\Money::displayCompact($expected, $currency) : 'Nothing measured yet'" />
    </div>

    <x-ui.card>
        @if($rows->isEmpty())
            <x-ui.empty title="No accepted decisions yet">Accept a decision in Decisions and it shows here as a task, then as a measured result.</x-ui.empty>
        @else
            <div class="ax-scroll-x">
                <table class="ax-table">
                    <thead><tr><th>Decision</th><th>Store</th><th>Owner</th><th>Task</th><th class="ax-num">Expected a year</th><th class="ax-num">Measured a year</th></tr></thead>
                    <tbody>
                        @foreach($rows as $g)
                            @php $m = $g->measurement; @endphp
                            <tr>
                                <td><a class="ax-fw-600" href="{{ \App\Filament\Pages\AssortmentDecision::getUrl(['decision' => $g->id]) }}" wire:navigate>{{ \App\Models\AssortmentGap::TYPES[$g->type] }} · {{ $g->product?->name ?? $g->sku }}</a></td>
                                <td>{{ $g->store?->name }}</td>
                                <td>{{ $g->assignee?->name ?? '—' }}</td>
                                <td class="ax-text-sm">
                                    @if($g->task_status === 'done') Done {{ $g->done_at?->format('j M') }}
                                    @elseif($g->task_status === 'cancelled') Cancelled
                                    @else To do{{ $g->due_at ? ' · due ' . $g->due_at->format('j M') : '' }}@endif
                                </td>
                                <td class="ax-num">{{ \App\Support\Money::compact((float) $g->value_mid_at_decision, $currency) }}</td>
                                <td class="ax-num">
                                    @if($m)
                                        {{ \App\Support\Money::compact((float) ($m['uplift_per_year'] ?? 0), $currency) }}@if(($m['strength'] ?? '') === 'weak')<span class="ax-faint"> *</span>@endif
                                    @elseif($g->measure_after)
                                        <span class="ax-faint">after {{ $g->measure_after->format('j M') }}</span>
                                    @else — @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="ax-faint ax-text-xs ax-mt-2">Measured = the store's change in sales over 8 weeks after the task, minus the change at similar stores that did not make it. Adds and delists are measured on the whole category, so sales moved from similar products do not count. * fewer than 3 comparison stores.</p>
        @endif
    </x-ui.card>
</div>
</x-filament-panels::page>
