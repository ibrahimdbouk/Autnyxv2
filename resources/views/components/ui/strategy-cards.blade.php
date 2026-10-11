@props([
    'strategies' => ['cards' => [], 'scale' => ['min' => -0.01, 'max' => 0.01]],
    'currency'   => null,
    'loaded'     => null,   // the label of the scenario in the workspace, if one was loaded
])
@php
    $signed = static fn ($v) => ((float) $v >= 0 ? '+' : '−') . \App\Support\Money::compact(abs((float) $v), $currency);
    $range = static fn ($r) => is_array($r) && count($r) === 3
        ? ($signed(min($r[0], $r[2])) === $signed(max($r[0], $r[2])) ? $signed($r[1]) : $signed(min($r[0], $r[2])) . ' to ' . $signed(max($r[0], $r[2])))
        : '—';
    $pct = static fn ($v) => ((float) $v >= 0 ? '+' : '−') . number_format(abs((float) $v) * 100, 1) . '%';
    $pctRange = static fn ($r) => is_array($r) ? ($pct(min($r[0], $r[2])) === $pct(max($r[0], $r[2])) ? $pct($r[1]) : $pct(min($r[0], $r[2])) . ' to ' . $pct(max($r[0], $r[2]))) : '—';
    $share = static fn ($v) => $v === null ? '—' : (int) floor((float) $v * 100) . '%';
    $sc = $strategies['scale'];
@endphp
<div class="ax-strategies">
    @foreach($strategies['cards'] as $card)
        @php $s = $card['summary']; $sp = (array) ($s['sales_pct'] ?? [0, 0, 0]); @endphp
        <article wire:key="strategy-{{ $card['key'] }}" @class(['ax-strategy', 'ax-strategy--recommended' => $card['recommended'], 'ax-strategy--loaded' => $loaded === \App\Services\Assortment\StudioService::PRESETS[$card['key']]['label']])>
            @if($card['recommended'])<span class="ax-strategy-ribbon">Recommended</span>@endif
            <div>
                <h3 class="ax-strategy-name">{{ $card['label'] }}</h3>
                <p class="ax-strategy-about">{{ $card['about'] }}</p>
            </div>

            <div>
                <p class="ax-strategy-head">Category sales a year</p>
                <div class="ax-fw-700 ax-ink">{{ $range($s['sales'] ?? null) }}</div>
                <div class="ax-faint ax-text-xs">{{ $pctRange($sp) }} of today</div>
                <x-ui.viz.range :low="(float) $sp[0]" :mid="(float) $sp[1]" :high="(float) $sp[2]" :min="$sc['min']" :max="$sc['max']"
                    :tip="'Category sales ' . $pctRange($sp) . ' a year, the middle ' . $pct($sp[1])" />
            </div>

            <dl class="ax-strategy-metrics">
                <dt>{{ ($s['margin_known'] ?? false) ? 'Margin a year' : 'Margin (as sales)' }}</dt><dd>{{ $range($s['margin'] ?? null) }}</dd>
                <dt title="Today's category sales still on the shelf after the products taken off">Sales kept</dt><dd>{{ $share($card['kept']) }}</dd>
                <dt>Products</dt><dd>{{ ($s['current_count'] ?? '—') . ' → ' . ($s['count'] ?? '—') }}</dd>
                <dt>Changes</dt><dd>{{ (int) ($s['changes'] ?? 0) }}{{ ($s['recovers'] ?? 0) ? ' + ' . $s['recovers'] . ' stock fix' . ($s['recovers'] > 1 ? 'es' : '') : '' }}</dd>
                <dt title="Stock tied up, at cost — a minus frees cash">Stock at cost</dt><dd>{{ $signed($s['stock'] ?? 0) }}</dd>
            </dl>

            <div>
                <p class="ax-strategy-head">Key actions</p>
                @if($card['same_as'])
                    <p class="ax-m-0 ax-text-xs ax-muted">The same changes as {{ $card['same_as'] }} on this shelf.</p>
                @elseif($card['actions'] === [])
                    <p class="ax-m-0 ax-text-xs ax-muted">None — the shelf already fits this objective.</p>
                @else
                    <ul class="ax-strategy-actions">
                        @foreach($card['actions'] as $a)<li>{{ $a }}</li>@endforeach
                    </ul>
                @endif
                @if(! ($s['feasible'] ?? true))
                    <p class="ax-m-0 ax-mt-1 ax-text-xs ax-danger-text">Cannot be met: {{ $s['reason'] }}</p>
                @elseif($s['violations'] ?? [])
                    <p class="ax-m-0 ax-mt-1 ax-text-xs ax-danger-text">Breaks a limit: {{ $s['violations'][0] }}</p>
                @endif
            </div>

            <div class="ax-strategy-foot">
                <span class="ax-risk ax-risk--{{ $card['risk']['level'] }}" title="{{ $card['risk']['why'] }}"><span class="ax-risk-dot"></span>{{ $card['risk']['label'] }}</span>
                <button type="button" class="ax-btn" wire:click="loadPreset('{{ $card['key'] }}')">Load into the workspace</button>
            </div>
        </article>
    @endforeach
</div>
