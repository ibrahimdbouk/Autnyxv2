<x-filament-panels::page>
@php
    $labels = \App\Models\AssortmentGap::TYPES;
    $pct = static fn ($v) => $v === null ? '—' : (int) round($v * 100) . '%';
@endphp

<div class="ax-stack">
    <x-ui.card :variant="$live ? 'accent' : null">
        @if($live)
            <p class="ax-m-0 ax-fw-600">Assortment is live — everyone with access sees the decisions.</p>
        @else
            <p class="ax-m-0">Go through the top {{ config('assortment.gate_sample', 50) }} decisions of each type with the person who knows your range, and mark each one. When at least {{ (int) round($pass * 100) }}% of each type make sense, <strong>Go live</strong> opens them to everyone.</p>
        @endif
        <p class="ax-faint ax-text-xs ax-mt-2">
            @if($lastRun)
                Last run {{ $lastRun->created_at?->diffForHumans() }} · {{ $lastRun->status }}@if($lastRun->as_of_date) · data up to {{ $lastRun->as_of_date->format('j M Y') }}@endif
                @if(($lastRun->stats['reason'] ?? null)) · {{ $lastRun->stats['reason'] }} @endif
            @else
                No run yet — press Run now.
            @endif
        </p>
    </x-ui.card>

    <div class="ax-grid ax-grid-3">
        @foreach($status['types'] as $typeKey => $t)
            <x-ui.stat
                :label="$labels[$typeKey]"
                :value="$t['needed'] === 0 ? 'None found' : ($t['reviewed'] . ' / ' . $t['needed'] . ' reviewed')"
                :color="$t['needed'] === 0 ? null : ($t['passed'] ? 'success' : 'warning')"
                :foot="$t['needed'] === 0 ? 'Nothing to review' : ($pct($t['rate']) . ' sensible' . ($t['passed'] ? ' — passed' : ''))" />
        @endforeach
    </div>

    <x-ui.card title="Range plans">
        @php $pg = $planGate; @endphp
        <p class="ax-m-0 ax-text-sm">
            @if($pg['live'])
                Range plans are open to everyone with access.
            @elseif(! $pg['decisions_live'])
                Plans are reviewed after the decisions go live. Until then they are built every night and kept in review.
            @else
                Go through the top {{ $pg['needed'] }} plans with your category manager: each one changes a store's category as a whole. When at least {{ (int) round($pass * 100) }}% make sense, <strong>Open range plans</strong> shows them to everyone.
            @endif
        </p>
        <p class="ax-faint ax-text-xs ax-mt-1">{{ $pg['total'] }} plan(s) · {{ $pg['reviewed'] }} of {{ $pg['needed'] }} reviewed · {{ $pct($pg['rate']) }} sensible{{ $pg['passed'] ? ' — passed' : '' }}</p>
        @if($planSample->isNotEmpty())
            <div class="ax-scroll-x ax-mt-3">
                <table class="ax-table">
                    <thead><tr><th>#</th><th>Plan</th><th class="ax-num">Category sales a year</th><th>Makes sense?</th></tr></thead>
                    <tbody>
                        @foreach($planSample as $n => $pl)
                            @php $s = $pl->impact['sales'] ?? [0, 0, 0]; @endphp
                            <tr wire:key="plan-rev-{{ $pl->id }}">
                                <td class="ax-faint">{{ $n + 1 }}</td>
                                <td style="min-width:15rem">
                                    <a class="ax-fw-600" href="{{ \App\Filament\Pages\AssortmentPlanPage::getUrl(['plan' => $pl->id]) }}" wire:navigate>{{ $pl->headline() }}</a>
                                    <div class="ax-muted ax-text-xs">{{ collect($pl->actionable())->map(fn ($c) => (\App\Models\AssortmentPlan::CHANGE_LABELS[$c['kind']] ?? $c['kind']) . ' ' . ($c['kind'] === 'swap' ? $c['out_name'] . ' → ' . $c['name'] : $c['name']))->implode(' · ') }}</div>
                                </td>
                                <td class="ax-num" style="white-space:nowrap">{{ \App\Support\Money::compact(min($s[0], $s[2]), $currency) }} to {{ \App\Support\Money::compact(max($s[0], $s[2]), $currency) }}</td>
                                <td style="white-space:nowrap">
                                    <x-filament::button size="xs" :color="$pl->review_verdict === 'sensible' ? 'success' : 'gray'" :outlined="$pl->review_verdict !== 'sensible'" wire:click="reviewPlan({{ $pl->id }}, 'sensible')">Yes</x-filament::button>
                                    <x-filament::button size="xs" :color="$pl->review_verdict === 'not_sensible' ? 'danger' : 'gray'" :outlined="$pl->review_verdict !== 'not_sensible'" wire:click="reviewPlan({{ $pl->id }}, 'not_sensible')">No</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card title="How the rules are doing">
        <div class="ax-scroll-x">
            <table class="ax-table">
                <thead><tr><th>Rule</th><th class="ax-num">Judged sensible in review</th><th class="ax-num">Accepted</th><th class="ax-num">Measured</th><th class="ax-num">Worked</th><th class="ax-num">Estimated vs measured, a year</th></tr></thead>
                <tbody>
                    @foreach($rules as $typeKey => $r)
                        <tr>
                            <td>{{ $labels[$typeKey] }}</td>
                            <td class="ax-num">{{ $r['reviewed'] ? $pct($r['sensible_rate']) . ' of ' . $r['reviewed'] : '—' }}</td>
                            <td class="ax-num">{{ $r['decided'] ? $pct($r['accept_rate']) . ' of ' . $r['decided'] : '—' }}</td>
                            <td class="ax-num">{{ $r['measured'] ?: '—' }}</td>
                            <td class="ax-num">{{ $r['measured'] ? $pct($r['worked_rate']) : '—' }}</td>
                            <td class="ax-num">{{ $r['measured'] ? \App\Support\Money::displayCompact($r['expected'], $currency) . ' vs ' . \App\Support\Money::displayCompact($r['realized'], $currency) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="ax-faint ax-text-xs ax-mt-2">From decision memory: every accept and reject is recorded with the figures it was made on, and each measured result is written back. "Worked" means sales moved at least half as much as estimated (for a delist: fell no more than estimated). The rules are only changed by a reviewed, versioned update — never silently.</p>
    </x-ui.card>

    <nav aria-label="Decision type">
        <x-filament::tabs>
            @foreach($labels as $key => $label)
                <x-filament::tabs.item tag="a" :href="static::getUrl(['type' => $key])" :active="$type === $key">
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    </nav>

    <x-ui.card>
        @if($sample->isEmpty())
            <x-ui.empty title="Nothing to review">No {{ strtolower($labels[$type]) }} decisions were found in the last run.</x-ui.empty>
        @else
            <div class="ax-scroll-x">
                <table class="ax-table">
                    <thead><tr><th>#</th><th>Decision and why</th><th class="ax-num">Value a year</th><th>Makes sense?</th></tr></thead>
                    <tbody>
                        @foreach($sample as $i => $g)
                            <tr wire:key="rev-{{ $g->id }}">
                                <td class="ax-faint">{{ $i + 1 }}</td>
                                <td style="min-width:15rem">
                                    <div class="ax-fw-600">{{ $g->product?->name ?? $g->sku }}</div>
                                    <div class="ax-muted ax-text-xs">{{ $g->store?->name }} · {{ $g->product?->category }} · {{ ucfirst($g->confidence_tier) }}</div>
                                    <div class="ax-text-sm ax-mt-1">{{ implode(' ', $g->explanation['evidence'] ?? []) }}</div>
                                </td>
                                <td class="ax-num" style="white-space:nowrap">{{ $g->valueRange($currency) }}</td>
                                <td style="white-space:nowrap">
                                    <x-filament::button size="xs" :color="$g->review_verdict === 'sensible' ? 'success' : 'gray'"
                                        :outlined="$g->review_verdict !== 'sensible'" wire:click="review({{ $g->id }}, 'sensible')">Yes</x-filament::button>
                                    <x-filament::button size="xs" :color="$g->review_verdict === 'not_sensible' ? 'danger' : 'gray'"
                                        :outlined="$g->review_verdict !== 'not_sensible'" wire:click="review({{ $g->id }}, 'not_sensible')">No</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
</x-filament-panels::page>
