@props([
    'steps' => [],     // list of ['label' => , 'value' => (a change), 'text' => , 'tip' => , 'sub' => ?]
    'total' => null,   // ['label' => , 'value' => , 'range' => [low, high], 'text' => , 'tip' => ]
    'fmt'   => null,   // fn (float) => string, for the axis
    'width'    => 640,    // drawn at this many pixels wide at most (it shrinks to fit, never grows)
    'label' => 'Waterfall chart',
])
@php
    $fmt ??= fn ($v) => number_format($v);
    $W = max(480, (int) $width); $H = 280; $L = 70; $R = 16; $T = 22; $B = 46;
    $run = 0.0; $bars = []; $lo = 0.0; $hi = 0.0;
    foreach ($steps as $s) {
        $v = (float) $s['value'];
        $bars[] = $s + ['from' => $run, 'to' => $run + $v, 'kind' => $v >= 0 ? 'up' : 'down'];
        $run += $v;
        $lo = min($lo, $run, $run - $v); $hi = max($hi, $run, $run - $v);
    }
    if ($total) {
        $bars[] = $total + ['from' => 0.0, 'to' => (float) $total['value'], 'kind' => 'total'];
        foreach ([(float) $total['value'], ...array_map('floatval', (array) ($total['range'] ?? []))] as $v) { $lo = min($lo, $v); $hi = max($hi, $v); }
    }
    $ticks = \App\Support\Viz::ticks($lo, $hi, 5);
    $d0 = min($lo, $ticks[0]); $d1 = max($hi, end($ticks));
    $L = (int) max($L, 16 + 6.3 * max(array_map(fn ($t) => mb_strlen((string) $fmt($t)), $ticks)));
    $py = fn ($v) => \App\Support\Viz::scale((float) $v, $d0, $d1, $H - $B, $T);
    $n = max(1, count($bars));
    $slot = ($W - $L - $R) / $n; $bw = min(78, $slot * 0.62);
@endphp
<div class="ax-scroll-x">
<svg class="ax-viz ax-viz--wide" width="{{ $W }}" height="{{ $H }}" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ $label }}">
    @foreach($ticks as $t)
        <line class="ax-viz-grid" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $py($t) }}" y2="{{ $py($t) }}" />
        <text class="ax-viz-text" x="{{ $L - 8 }}" y="{{ $py($t) + 4 }}" text-anchor="end">{{ $fmt($t) }}</text>
    @endforeach
    <line class="ax-viz-zero" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $py(0) }}" y2="{{ $py(0) }}" />
    @foreach($bars as $i => $b)
        @php
            $cx = $L + $slot * $i + $slot / 2;
            $yTop = min($py($b['from']), $py($b['to'])); $yBot = max($py($b['from']), $py($b['to']));
            $cls = ['up' => 'ax-viz-up', 'down' => 'ax-viz-down', 'total' => 'ax-viz-total'][$b['kind']];
        @endphp
        <g class="ax-viz-hit">
            <title>{{ $b['tip'] ?? ($b['label'] . ': ' . ($b['text'] ?? $b['to'])) }}</title>
            <rect class="{{ $cls }}" x="{{ $cx - $bw / 2 }}" y="{{ $yTop }}" width="{{ $bw }}" height="{{ max(1.5, $yBot - $yTop) }}" rx="3" stroke-width="0" fill-opacity="{{ $b['kind'] === 'total' ? '0.9' : '0.8' }}" />
            @if($b['kind'] === 'total' && ! empty($b['range']))
                @php $r0 = $py(min($b['range'])); $r1 = $py(max($b['range'])); @endphp
                <line class="ax-viz-whisker" x1="{{ $cx }}" x2="{{ $cx }}" y1="{{ $r0 }}" y2="{{ $r1 }}" />
                <line class="ax-viz-whisker" x1="{{ $cx - 9 }}" x2="{{ $cx + 9 }}" y1="{{ $r0 }}" y2="{{ $r0 }}" />
                <line class="ax-viz-whisker" x1="{{ $cx - 9 }}" x2="{{ $cx + 9 }}" y1="{{ $r1 }}" y2="{{ $r1 }}" />
            @endif
            <text class="ax-viz-text ax-viz-text--ink" x="{{ $cx }}" y="{{ $b['kind'] === 'total' && ! empty($b['range']) ? min($yTop, $py(max($b['range'])), $py(min($b['range']))) - 6 : ($b['to'] >= $b['from'] ? $yTop - 6 : $yBot + 13) }}" text-anchor="middle">{{ $b['text'] ?? $fmt($b['to'] - $b['from']) }}</text>
            <text class="ax-viz-text" x="{{ $cx }}" y="{{ $H - $B + 16 }}" text-anchor="middle">{{ \App\Support\Viz::cut((string) $b['label'], 18) }}</text>
            @if(! empty($b['sub']))<text class="ax-viz-text--faint ax-viz-text" x="{{ $cx }}" y="{{ $H - $B + 30 }}" text-anchor="middle">{{ $b['sub'] }}</text>@endif
        </g>
        @if($i < count($bars) - 1 && $b['kind'] !== 'total')
            <line class="ax-viz-connector" x1="{{ $cx + $bw / 2 }}" x2="{{ $cx + $slot - $bw / 2 }}" y1="{{ $py($b['to']) }}" y2="{{ $py($b['to']) }}" />
        @endif
    @endforeach
</svg>
</div>
