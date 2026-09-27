<x-filament-panels::page>
@if($gap)
@php
    $e = $gap->evidence ?? [];
    $x = $gap->explanation ?? [];
    $tierColor = match ($gap->confidence_tier) { 'established' => 'success', 'likely' => 'info', default => null };
    $typeColor = match ($gap->type) { 'add' => 'success', 'delist' => 'warning', default => 'danger' };
    $pct = static fn ($v) => $v === null ? '—' : (int) round($v * 100) . '%';
    $m = $gap->measurement;
    $t = $e['transfer'] ?? null;
    $money = static fn ($v) => $v === null ? '—' : \App\Support\Money::compact((float) $v, $currency);
    $moneyRange = static fn ($r) => is_array($r) ? ($money($r[0]) . '–' . $money($r[1])) : '—';
    $share = static fn ($r) => is_array($r) ? ((int) round($r[0] * 100) === (int) round($r[2] * 100) ? (int) round($r[1] * 100) . '%' : (int) round($r[0] * 100) . '–' . (int) round($r[2] * 100) . '%') : '—';
    $life = $e['lifecycle'] ?? null;
@endphp

<div class="ax-stack">
    <a href="{{ $backUrl }}" class="ax-text-sm ax-muted" wire:navigate>← All decisions</a>

    <x-ui.card>
        <div class="ax-row ax-wrap ax-mb-2" style="gap:.5rem">
            <x-ui.badge :color="$typeColor">{{ \App\Models\AssortmentGap::TYPES[$gap->type] ?? $gap->type }}</x-ui.badge>
            <x-ui.badge :color="$tierColor">Confidence: {{ ucfirst($gap->confidence_tier) }}</x-ui.badge>
            <x-ui.badge>{{ ucfirst(str_replace('_', ' ', $gap->status)) }}</x-ui.badge>
            @if($life && $life !== 'established')
                <x-ui.badge :color="$life === 'emerging' ? 'info' : null">{{ $lifecycleLabels[$life] ?? ucfirst($life) }}</x-ui.badge>
            @endif
        </div>
        <h2 class="ax-text-lg ax-fw-700 ax-ink ax-m-0">{{ $gap->headline() }}</h2>
        <p class="ax-muted ax-text-sm ax-mt-1">{{ $gap->product?->name }} · {{ $gap->sku }} · {{ $gap->product?->category ?? 'No category' }} · {{ $gap->store?->name }}</p>
    </x-ui.card>

    <div class="ax-grid ax-grid-3">
        <x-ui.stat label="Value a year" :value="$gap->valueRange($currency)"
            :foot="($e['value_basis'] ?? 'margin') === 'margin' ? 'Gross margin, as a range' : 'Sales (no cost price on file), as a range'" />
        <x-ui.stat label="Compared with" :value="($e['qualifying_peers'] ?? 0) . ' stores'" :foot="$e['peer_group'] ?? ''" />
        <x-ui.stat label="Based on data up to" :value="$gap->as_of_date?->format('j M Y') ?? '—'"
            :foot="'Last ' . ($e['window_days'] ?? 90) . ' days of sales and stock'" />
    </div>

    <x-ui.card title="Why">
        <ul class="ax-list">
            @foreach(($x['evidence'] ?? []) as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
        @if(! empty($x['reasons']))
            <p class="ax-faint ax-text-xs ax-mt-3">{{ implode(' · ', $x['reasons']) }}</p>
        @endif
        @if($gap->type === 'stockout_hidden')
            <p class="ax-text-sm ax-mt-3">This is a stock problem, not a range problem: work it as a stock issue, not as a delist.</p>
            @if(! empty($links))
                <ul class="ax-list ax-mt-2">
                    @foreach($links as $l)
                        <li>Open in Root Cause:
                            @if($l['url'])<a href="{{ $l['url'] }}">{{ $l['label'] }}</a>@else{{ $l['label'] }}@endif
                            <span class="ax-faint">({{ str_replace('_', ' ', (string) $l['status']) }})</span></li>
                    @endforeach
                </ul>
            @elseif($rootCause)
                <p class="ax-faint ax-text-xs ax-mt-2">Root Cause has no open investigation on it at this store. Accepting makes a store task to fix the stock.</p>
            @else
                <p class="ax-faint ax-text-xs ax-mt-2">Accepting makes a store task to fix the stock.</p>
            @endif
        @endif
    </x-ui.card>

    @if($t)
        <x-ui.card :title="$gap->type === 'add' ? 'Where its sales would come from' : 'Where its buyers would go'">
            <div class="ax-grid ax-grid-3">
                @if($gap->type === 'add')
                    <x-ui.stat label="Gross, a year" :value="$money($e['gross_per_year'] ?? null)" foot="Estimated: if every sale were new" />
                    <x-ui.stat label="Taken from the shelf" :value="$moneyRange($e['taken_per_year'] ?? null)" :foot="'Estimated: ' . $share($t['share'] ?? null) . ' of its sales'" />
                    <x-ui.stat label="New to the store" :value="$gap->valueRange($currency)" foot="Estimated, after what it takes" />
                @elseif($gap->type === 'delist')
                    <x-ui.stat label="Its sales now, a year" :value="$money($e['current_per_year'] ?? null)" foot="Observed, as margin or sales" />
                    <x-ui.stat label="Moves to the shelf" :value="$moneyRange($e['moved_per_year'] ?? null)" :foot="'Estimated: ' . $share($t['share'] ?? null) . ' of its buyers'" />
                    <x-ui.stat label="Lost" :value="$moneyRange($e['lost_per_year'] ?? null)" foot="Estimated: buyers who buy nothing instead" />
                @else
                    <x-ui.stat label="Sales missed while out" :value="$money($e['gross_per_year'] ?? null)" foot="Estimated from similar stores" />
                    <x-ui.stat label="Bought as something else" :value="$moneyRange($e['moved_per_year'] ?? null)" :foot="'Estimated: ' . $share($t['share'] ?? null) . ' of its buyers'" />
                    <x-ui.stat label="Lost" :value="$gap->valueRange($currency)" foot="Estimated, a year" />
                @endif
            </div>
            @if(($t['basis'] ?? null) === 'insufficient')
                <p class="ax-text-sm ax-mt-3">Insufficient evidence to estimate substitution. {{ $t['note'] }} The value is shown before substitution, as the top of the range.</p>
            @elseif(! empty($t['pairs']))
                <div class="ax-scroll-x ax-mt-3">
                    <table class="ax-table">
                        <thead><tr><th>Product on the shelf</th><th class="ax-num">Share of its demand</th><th>Based on</th></tr></thead>
                        <tbody>
                            @foreach($t['pairs'] as $p)
                                <tr>
                                    <td>{{ $p['name'] ?? $p['sku'] }} <span class="ax-faint">{{ $p['sku'] }}</span></td>
                                    <td class="ax-num">{{ $share($p['share'] ?? null) }}</td>
                                    <td class="ax-text-sm">
                                        @if(($p['basis'] ?? '') === 'observed')
                                            Observed: {{ collect([
                                                ($p['store_days'] ?? 0) ? number_format($p['store_days']) . ' store-days of stockouts' : null,
                                                ($p['events'] ?? 0) ? number_format($p['events']) . ' range changes' : null,
                                            ])->filter()->implode(' and ') }} across {{ $p['stores'] ?? 0 }} stores
                                        @else
                                            Assumed: same {{ implode(', ', $p['shared'] ?? ['category']) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="ax-faint ax-text-xs ax-mt-2">
                    @if(($t['basis'] ?? '') === 'observed')
                        Estimated from stockouts and range changes at comparable stores (confidence: {{ $t['tier'] }}). It shows where buyers went, not proof of why.
                    @else
                        Assumed from product similarity — not yet observed in stockouts or range changes. It becomes observed as evidence builds up.
                    @endif
                    @if($gap->type === 'add') Assumes buyers here switch the way they do at the stores that carry it. @endif
                </p>
            @elseif(! empty($t['note']))
                <p class="ax-text-sm ax-mt-3">{{ $t['note'] }}</p>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card title="The stores behind it">
        <div class="ax-scroll-x">
            <table class="ax-table">
                <thead>
                    <tr><th>Store</th><th>Carries it</th><th>Since</th><th class="ax-num">In stock</th><th class="ax-num">Sells a day (in stock)</th></tr>
                </thead>
                <tbody>
                    @forelse($peers as $p)
                        <tr @class(['ax-row-hl' => $p['this_store']])>
                            <td>{{ $p['store'] }}@if($p['this_store']) <span class="ax-faint">(this store)</span>@endif</td>
                            <td>{{ $p['carried'] ? 'Yes' : 'No' }}</td>
                            <td>{{ $p['since'] ? \Illuminate\Support\Carbon::parse($p['since'])->format('j M Y') : '—' }}</td>
                            <td class="ax-num">{{ $p['carried'] ? $pct($p['availability']) : '—' }}</td>
                            <td class="ax-num">{{ $p['units_per_day'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="ax-muted">The peer group for this decision has changed since it was made.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="ax-faint ax-text-xs ax-mt-2">Stores count as comparable when they have carried the product for at least 28 days and kept it in stock at least 80% of the time. The store being judged is never part of its own comparison.</p>
    </x-ui.card>

    @if($gap->decided_at)
        <x-ui.card title="What happened">
            <div class="ax-grid ax-grid-3">
                <div>
                    <div class="ax-faint ax-text-xs">Decision</div>
                    <div class="ax-fw-600">{{ ucfirst($gap->status) }} by {{ $gap->decider?->name ?? 'someone' }}</div>
                    <div class="ax-muted ax-text-sm">{{ $gap->decided_at->format('j M Y') }}</div>
                </div>
                @if($gap->status === 'accepted')
                    <div>
                        <div class="ax-faint ax-text-xs">Task</div>
                        <div class="ax-fw-600">{{ match($gap->task_status) { 'done' => 'Done', 'cancelled' => 'Cancelled', default => 'To do' } }}@if($gap->assignee) · {{ $gap->assignee->name }}@endif</div>
                        <div class="ax-muted ax-text-sm">
                            @if($gap->done_at) Done {{ $gap->done_at->format('j M Y') }} @elseif($gap->due_at) Due {{ $gap->due_at->format('j M Y') }} @endif
                        </div>
                    </div>
                    <div>
                        <div class="ax-faint ax-text-xs">Result</div>
                        @if($m)
                            <div class="ax-fw-600">Measured: {{ \App\Support\Money::displayCompact((float) ($m['uplift_per_year'] ?? 0), $currency) }} a year</div>
                            <div class="ax-muted ax-text-sm">
                                {{ $m['metric'] === 'category_sales' ? 'Category sales' : 'Product sales' }} against {{ $m['control_stores'] }} similar stores that did not change{{ ($m['strength'] ?? '') === 'weak' ? ' (few comparison stores — treat as indicative)' : '' }}
                            </div>
                            @if(isset($m['expected_per_year']))
                                <div class="ax-faint ax-text-xs ax-mt-1">Estimated when decided: {{ \App\Support\Money::displayCompact((float) $m['expected_per_year'], $currency) }} a year · {{ match($m['verdict'] ?? '') { 'success' => 'worked', 'partial' => 'worked in part', 'failure' => 'did not work', default => '' } }}</div>
                            @endif
                        @elseif($gap->measure_after)
                            <div class="ax-fw-600">Measured after {{ $gap->measure_after->format('j M Y') }}</div>
                            <div class="ax-muted ax-text-sm">8 weeks after it was done</div>
                        @else
                            <div class="ax-muted ax-text-sm">Measured 8 weeks after it is marked done.</div>
                        @endif
                    </div>
                @endif
            </div>
            @if($gap->decision_note)
                <p class="ax-text-sm ax-mt-3">{{ $gap->decision_note }}</p>
            @endif
        </x-ui.card>
    @endif
</div>
@else
<x-ui.card>
    <x-ui.empty title="Pick a decision">Open one from <a href="{{ $backUrl }}" wire:navigate>the list of range decisions</a>.</x-ui.empty>
</x-ui.card>
@endif
</x-filament-panels::page>
