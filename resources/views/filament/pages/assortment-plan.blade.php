<x-filament-panels::page>
@if($p)
@php
    $money = static fn ($v) => \App\Support\Money::compact((float) $v, $currency);
    $signed = static fn ($v) => ($v >= 0 ? '+' : '−') . \App\Support\Money::compact(abs((float) $v), $currency);
    $range = static fn ($r) => is_array($r)
        ? ($signed(min($r[0], $r[2])) === $signed(max($r[0], $r[2])) ? $signed($r[1]) : $signed(min($r[0], $r[2])) . ' to ' . $signed(max($r[0], $r[2])))
        : '—';
    $pct = static fn ($v) => $v === null ? null : (($v >= 0 ? '+' : '−') . number_format(abs($v) * 100, 1) . '%');
    $pctRange = static fn ($r) => is_array($r) ? ($pct(min($r[0], $r[2])) . ' to ' . $pct(max($r[0], $r[2]))) : null;
    $i = $p->impact ?? [];
    $c = $p->constraints ?? [];
    $m = $p->measurement;
    $canTick = $p->status === \App\Models\AssortmentPlan::STATUS_PROPOSED && $p->feasible;
    $kindColor = ['add' => 'success', 'delist' => 'warning', 'swap' => 'info', 'recover' => 'danger', 'protect' => null];
    $labels = \App\Models\AssortmentPlan::CHANGE_LABELS;
    $decision = static fn ($id) => $id ? \App\Filament\Pages\AssortmentDecision::getUrl(['decision' => $id]) : null;
    $bound = $c['bound'] ?? [];
    $basis = ($i['basis'] ?? 'estimated') === 'simulated' ? 'Simulated' : 'Estimated';
@endphp

<div class="ax-stack">
    <a href="{{ $backUrl }}" class="ax-text-sm ax-muted" wire:navigate>← All range plans</a>

    <x-ui.card>
        <div class="ax-row ax-wrap ax-mb-2" style="gap:.5rem">
            <x-ui.badge>{{ \App\Models\AssortmentPlan::STATUSES[$p->status] ?? $p->status }}</x-ui.badge>
            <x-ui.badge color="info">{{ \App\Services\Assortment\CategoryStrategy::roleLabel($p->role) }}</x-ui.badge>
            <x-ui.badge>Objective: {{ \App\Services\Assortment\CategoryStrategy::objectiveLabel($p->objective) }}</x-ui.badge>
            <x-ui.badge :color="$p->confidence_tier === 'established' ? 'success' : ($p->confidence_tier === 'likely' ? 'info' : null)">Confidence: {{ ucfirst($p->confidence_tier) }}</x-ui.badge>
        </div>
        <h2 class="ax-text-lg ax-fw-700 ax-ink ax-m-0">{{ $p->headline() }}</h2>
        <p class="ax-muted ax-text-sm ax-mt-1">Built from data up to {{ $p->as_of_date?->format('j M Y') }} · {{ $p->fromStudio()
            ? 'made in the Decision Studio' . ($p->creator ? ' by ' . $p->creator->name : '') . ($p->scenario ? ' (“' . $p->scenario->name . '”)' : '')
            : 'the range decisions for this store and category, chosen together' }}@if($studioUrl) · <a href="{{ $studioUrl }}" wire:navigate>Open in the Decision Studio</a>@endif</p>
        @if(! $p->feasible)
            <p class="ax-text-sm ax-mt-3" style="color:var(--ax-danger-fg)"><strong>{{ $p->infeasible_reason }}</strong></p>
            <p class="ax-faint ax-text-xs ax-mt-1">Change the category's limits in Assortment → Range &amp; Must-Stock, or the must-stock list, and run again. Nothing is proposed while a limit cannot be met.</p>
        @endif
    </x-ui.card>

    <div class="ax-grid ax-grid-4">
        <x-ui.stat label="Products" :value="$p->current_count . ' → ' . $p->proposed_count"
            :foot="'Limit ' . ($c['min_size'] ?? '—') . '–' . ($c['max_size'] ?? '—') . ' · shelf ' . ($pct($i['space_pct'] ?? 0) ?? '0%')" />
        <x-ui.stat label="Category sales a year" :value="$range($i['sales'] ?? null)" :foot="$basis . ' · ' . ($pctRange($i['sales_pct'] ?? null) ?? '—') . ' of today'" />
        <x-ui.stat :label="($i['margin_known'] ?? false) ? 'Gross margin a year' : 'Margin a year (no cost prices: sales)'" :value="$range($i['margin'] ?? null)"
            :foot="$basis . (($i['margin_pct'] ?? null) ? ' · ' . $pctRange($i['margin_pct']) . ' of today' : '')" />
        <x-ui.stat label="Stock tied up, at cost" :value="$signed($i['stock'] ?? 0)"
            :foot="$basis . (($i['stock_pct'] ?? null) !== null ? ' · ' . $pct($i['stock_pct']) . ' of today' : '') . ' · a minus frees cash'" />
    </div>

    @if(! empty($p->actionable()))
        <x-ui.card title="The changes, in the order they were chosen">
            <div class="ax-scroll-x">
                <table class="ax-table">
                    <thead><tr>@if($canTick)<th style="width:2rem"></th>@endif<th>Change</th><th>Why this one</th><th class="ax-num">Category sales a year</th><th class="ax-num">Margin a year</th><th>Confidence</th></tr></thead>
                    <tbody>
                        @foreach($p->actionable() as $ch)
                            @php $off = $canTick ? in_array($ch['key'], $this->unticked, true) : ! ($ch['ticked'] ?? true); @endphp
                            <tr wire:key="ch-{{ $ch['key'] }}" @if($off) style="opacity:.45" @endif>
                                @if($canTick)
                                    <td><input type="checkbox" aria-label="Keep {{ $ch['name'] }} in the plan" @checked(! $off) wire:click="toggle(@js($ch['key']))"></td>
                                @endif
                                <td style="min-width:12rem">
                                    <x-ui.badge :color="$kindColor[$ch['kind']] ?? null">{{ $labels[$ch['kind']] ?? $ch['kind'] }}</x-ui.badge>
                                    @if($ch['kind'] === 'swap')
                                        <div class="ax-fw-600 ax-mt-1">Out: @if($decision($ch['out_gap_id'] ?? null))<a href="{{ $decision($ch['out_gap_id']) }}" wire:navigate>{{ $ch['out_name'] }}</a>@else{{ $ch['out_name'] }}@endif</div>
                                        <div class="ax-fw-600">In: @if($decision($ch['gap_id'] ?? null))<a href="{{ $decision($ch['gap_id']) }}" wire:navigate>{{ $ch['name'] }}</a>@else{{ $ch['name'] }}@endif</div>
                                    @else
                                        <div class="ax-fw-600 ax-mt-1">@if($decision($ch['gap_id'] ?? null))<a href="{{ $decision($ch['gap_id']) }}" wire:navigate>{{ $ch['name'] }}</a>@else{{ $ch['name'] }}@endif</div>
                                    @endif
                                    @if($off)<div class="ax-faint ax-text-xs">Left out of the plan</div>@endif
                                </td>
                                <td class="ax-text-sm" style="min-width:16rem">{{ $ch['why'] }}</td>
                                <td class="ax-num" style="white-space:nowrap">{{ $range($ch['sales'] ?? null) }}</td>
                                <td class="ax-num" style="white-space:nowrap">{{ $range($ch['margin'] ?? null) }}</td>
                                <td>{{ ucfirst($ch['tier'] ?? '') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="ax-faint ax-text-xs ax-mt-2">
                Each change is valued on the shelf as it stands after the changes before it: what an add takes from similar products, and what a delist's buyers take instead, come from observed stockouts and range changes or the stated product-similarity assumption (open a change to see which).
                @if($canTick) Untick a change to leave it out before you accept; the totals above are for the whole plan. @endif
            </p>
        </x-ui.card>
    @endif

    @php $left = $c['left_out'] ?? []; @endphp
    @if(! empty($left))
        <x-ui.card title="Considered and left out">
            <ul class="ax-list">
                @foreach($left as $l)
                    <li><span class="ax-fw-600">{{ $labels[$l['kind']] ?? $l['kind'] }} {{ $l['name'] }}</span> — {{ $l['why'] }}
                        @if($decision($l['gap_id'] ?? null)) <a class="ax-text-sm" href="{{ $decision($l['gap_id']) }}" wire:navigate>Open the decision</a>@endif</li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @php $protect = collect($p->changes ?? [])->where('kind', 'protect'); @endphp
    <div class="ax-grid ax-grid-2">
        <x-ui.card title="Limits it respects">
            <ul class="ax-list ax-text-sm">
                <li>At most {{ $c['max_size'] ?? '—' }} products{{ ($c['set_max'] ?? null) ? ' (set for this category)' : ' — today\'s ' . ($c['current_count'] ?? '') . ' plus ' . (int) round(($c['room'] ?? 0) * 100) . '% room' }} @if(in_array('max_size', $bound, true)) <strong>· reached</strong>@endif</li>
                <li>At least {{ $c['min_size'] ?? '—' }} products @if(in_array('min_size', $bound, true)) <strong>· reached</strong>@endif</li>
                @if(($c['sales_floor'] ?? null) !== null)<li>Category sales no worse than {{ $pct($c['sales_floor']) }} @if(in_array('sales_floor', $bound, true)) <strong>· reached</strong>@endif</li>@endif
                <li>Must-stock, new, emerging and seasonal products stay; products that keep running out are restocked, never delisted.</li>
            </ul>
        </x-ui.card>
        <x-ui.card title="Protected on this shelf">
            @if($protect->isEmpty())
                <p class="ax-muted ax-text-sm ax-m-0">Nothing on this shelf is protected beyond the plan's own rules.</p>
            @else
                <ul class="ax-list ax-text-sm">
                    @foreach($protect->take(12) as $pr)<li>{{ $pr['name'] }} <span class="ax-faint">— {{ $pr['why'] }}</span></li>@endforeach
                </ul>
                @if($protect->count() > 12)<p class="ax-faint ax-text-xs">and {{ $protect->count() - 12 }} more</p>@endif
            @endif
        </x-ui.card>
    </div>

    @if($p->decided_at)
        <x-ui.card title="What happened">
            <div class="ax-grid ax-grid-3">
                <div>
                    <div class="ax-faint ax-text-xs">Decision</div>
                    <div class="ax-fw-600">{{ $p->status === 'rejected' ? 'Rejected' : 'Accepted' }} by {{ $p->decider?->name ?? 'someone' }}</div>
                    <div class="ax-muted ax-text-sm">{{ $p->decided_at->format('j M Y') }}@if($p->decision_note) · {{ $p->decision_note }}@endif</div>
                </div>
                @if($p->status !== 'rejected')
                    <div>
                        <div class="ax-faint ax-text-xs">Reset task</div>
                        <div class="ax-fw-600">{{ $p->done_at ? 'Done ' . $p->done_at->format('j M') : 'To do' }}@if($p->assignee) · {{ $p->assignee->name }}@endif</div>
                        <div class="ax-muted ax-text-sm">@if(! $p->done_at && $p->due_at) Due {{ $p->due_at->format('j M Y') }} @endif</div>
                    </div>
                    <div>
                        <div class="ax-faint ax-text-xs">Result</div>
                        @if($m)
                            <div class="ax-fw-600">Measured: {{ $signed($m['uplift_per_year'] ?? 0) }} a year</div>
                            <div class="ax-muted ax-text-sm">Category sales against {{ $m['control_stores'] }} similar stores that did not reset it{{ ($m['strength'] ?? '') === 'weak' ? ' (few comparison stores — indicative)' : '' }}</div>
                            @if(isset($m['expected_per_year']))<div class="ax-faint ax-text-xs ax-mt-1">Estimated when accepted: {{ $signed($m['expected_per_year']) }} a year · {{ match($m['verdict'] ?? '') { 'success' => 'worked', 'partial' => 'worked in part', 'failure' => 'did not work', default => '' } }}</div>@endif
                        @elseif($p->measure_after)
                            <div class="ax-fw-600">Measured after {{ $p->measure_after->format('j M Y') }}</div>
                        @else
                            <div class="ax-muted ax-text-sm">Measured 8 weeks after the reset is marked done.</div>
                        @endif
                    </div>
                @endif
            </div>
        </x-ui.card>
    @endif
</div>
@else
<x-ui.card>
    <x-ui.empty title="Pick a plan">Open one from <a href="{{ $backUrl }}" wire:navigate>the list of range plans</a>.</x-ui.empty>
</x-ui.card>
@endif
</x-filament-panels::page>
