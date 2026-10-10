<x-filament-panels::page>
@php
    $pct = static fn ($v) => $v === null ? '—' : (int) round((float) $v * 100) . '%';
    $x = static fn ($v) => $v === null ? '—' : '× ' . number_format((float) $v, 2);
    $pts = static fn ($v) => $v === null ? '—' : (((float) $v >= 0 ? '+' : '−') . (int) round(abs((float) $v) * 100) . ' points');
    $labels = ['add' => 'Adds', 'delist' => 'Delists', 'stockout_hidden' => 'Stockout fixes'];
    $identity = \App\Services\Assortment\CalibrationService::identity();
    $describe = function (array $adj) use ($labels, $x, $pts, $identity): array {
        $out = [];
        foreach ($adj['value_scale'] ?? [] as $t => $v) {
            if ((float) $v !== (float) ($identity['value_scale'][$t] ?? 1.0)) { $out[] = $labels[$t] . ': estimates ' . $x($v); }
        }
        foreach ($adj['range_widen'] ?? [] as $t => $v) {
            if ((float) $v !== 1.0) { $out[] = $labels[$t] . ': ranges ' . $x($v); }
        }
        foreach ($adj['tier_shift'] ?? [] as $t => $v) {
            if ((int) $v !== 0) { $out[] = $labels[$t] . ': one confidence tier lower'; }
        }
        if ((float) ($adj['share_shift'] ?? 0) !== 0.0) { $out[] = 'Transferable demand: ' . $pts($adj['share_shift']) . ' on the share of buyers who switch'; }

        return $out;
    };
@endphp
<div class="ax-stack">
    <x-ui.card title="In force">
        @if($active)
            <p class="ax-m-0"><strong>{{ $active->label() }}</strong> — approved {{ $active->decided_at?->format('j M Y') }}{{ $active->decider ? ' by ' . $active->decider->name : '' }}. Applies to every run since; each decision and plan made under it says so.</p>
            <ul class="ax-list ax-text-sm ax-mt-2">
                @foreach($describe((array) $active->adjustments) as $line)<li>{{ $line }}</li>@endforeach
            </ul>
        @else
            <p class="ax-m-0">No calibration: the rules run as built.</p>
        @endif
    </x-ui.card>

    @foreach($proposals as $cal)
        <x-ui.card :title="'Proposal ' . $cal->label()">
            <p class="ax-muted ax-text-sm ax-m-0">Worked out {{ $cal->created_at?->format('j M Y') }} from measured results. Nothing changes until it is approved.</p>
            <ul class="ax-list ax-text-sm ax-mt-2">
                @foreach((array) $cal->reasons as $r)<li>{{ $r }}</li>@endforeach
            </ul>
            <div class="ax-row ax-mt-3" style="gap:.5rem">
                {{ ($this->approveAction)(['id' => $cal->id]) }}
                {{ ($this->rejectAction)(['id' => $cal->id]) }}
            </div>
        </x-ui.card>
    @endforeach

    <x-ui.card title="What the measured results say">
        <div class="ax-scroll-x">
            <table class="ax-table">
                <thead><tr>
                    <th>Rule</th><th class="ax-num">Measured</th><th class="ax-num">Worked · partly · did not</th><th class="ax-num">Worked</th>
                    <th class="ax-num">Measured ÷ estimated</th><th class="ax-num">Inside the estimated range</th><th class="ax-num">Buyers who switched, against the estimate</th><th>Tuning</th>
                </tr></thead>
                <tbody>
                    @foreach(['add', 'delist', 'stockout_hidden'] as $t)
                        @php $s = $stats[$t]; @endphp
                        <tr>
                            <td class="ax-fw-600">{{ $labels[$t] }}</td>
                            <td class="ax-num">{{ $s['strong'] }}@if($s['weak']) <span class="ax-faint ax-text-xs">+ {{ $s['weak'] }} with too few comparison stores</span>@endif</td>
                            <td class="ax-num">{{ $s['success'] }} · {{ $s['partial'] }} · {{ $s['failure'] }}</td>
                            <td class="ax-num">{{ $pct($s['success_rate']) }}</td>
                            <td class="ax-num">{{ $t === 'delist' ? '—' : $x($s['ratio_median']) }}</td>
                            <td class="ax-num">{{ $pct($s['coverage']) }}</td>
                            <td class="ax-num">{{ $t === 'delist' ? $pts($s['share_bias']) : '—' }}</td>
                            <td class="ax-text-xs">{{ $s['strong'] >= $min ? 'Enough to tune' : ($min - $s['strong']) . ' more measured result(s) needed' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="ax-faint ax-text-xs ax-mt-2">
            Measured = an accepted decision, done, and compared with similar stores that did not change, 8 weeks later. "Measured ÷ estimated" compares with the estimate before any calibration.
            A rule is tuned once it has {{ $min }} results measured against at least 3 comparison stores: estimates are scaled when they are off by 10% or more, ranges widened when fewer than 60% of results fall inside (they are meant to hold about 80%), a rule is one confidence tier lower when under 40% of its decisions worked, and where buyers go is shifted when delists show it is off by 3 points or more.
        </p>
    </x-ui.card>

    <div class="ax-grid ax-grid-2">
        <x-ui.card title="Range plans measured">
            @php $p = $stats['plans']; @endphp
            <p class="ax-m-0">{{ $p['n'] }} measured{{ $p['n'] ? ' · ' . $p['success'] . ' worked, ' . $p['partial'] . ' partly, ' . $p['failure'] . ' did not · measured ÷ estimated ' . $x($p['ratio_median']) : '' }}.</p>
            <p class="ax-faint ax-text-xs ax-mt-1">Reported, not used to tune: a category's sales move for many reasons at once, so the rules are tuned on single decisions.</p>
        </x-ui.card>
        <x-ui.card title="By confidence">
            <ul class="ax-list ax-text-sm">
                @foreach(['add', 'delist', 'stockout_hidden'] as $t)
                    @foreach($stats[$t]['by_tier'] as $tier => $b)
                        <li>{{ $labels[$t] }}, {{ $tier }}: {{ $b['n'] }} measured, {{ $b['success'] ?? 0 }} worked</li>
                    @endforeach
                @endforeach
                @if(collect(['add', 'delist', 'stockout_hidden'])->every(fn ($t) => $stats[$t]['by_tier'] === []))<li class="ax-muted">Nothing measured yet.</li>@endif
            </ul>
        </x-ui.card>
    </div>

    @if($history->isNotEmpty())
        <x-ui.card title="History">
            <div class="ax-scroll-x">
                <table class="ax-table">
                    <thead><tr><th>Version</th><th>Status</th><th>What it changed</th><th>Decided</th><th>Note</th></tr></thead>
                    <tbody>
                        @foreach($history as $h)
                            <tr>
                                <td class="ax-mono">{{ $h->label() }}</td>
                                <td>{{ \App\Models\AssortmentCalibration::STATUSES[$h->status] ?? $h->status }}</td>
                                <td class="ax-text-sm">{{ implode('; ', $describe((array) $h->adjustments)) ?: 'As built' }}</td>
                                <td class="ax-text-sm">{{ $h->decided_at?->format('j M Y') ?? '—' }}{{ $h->decider ? ' · ' . $h->decider->name : '' }}</td>
                                <td class="ax-text-sm ax-muted">{{ $h->decision_note ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
</x-filament-panels::page>
