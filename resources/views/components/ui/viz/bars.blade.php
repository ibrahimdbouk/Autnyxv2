@props([
    'items'   => [],    // list of ['label' => , 'value' => , 's' => series 1–8 | 'class' => , 'text' => , 'tip' => , 'sub' => ?]
    'max'     => null,  // the right end (defaults to the largest value)
    'markers' => [],    // list of ['at' => , 'label' => ] vertical reference lines
    'barH'    => 18,
    'width'    => 640,    // drawn at this many pixels wide at most (it shrinks to fit, never grows)
    'label'   => 'Bar chart',
])
@php
    $W = max(480, (int) $width);
    $maxLen = max(4, ...array_map(fn ($i) => mb_strlen((string) $i['label']), $items ?: [['label' => '']]));
    $L = (int) min(250, max(80, $maxLen * 7.1 + 14));
    $R = 86; $T = $markers ? 18 : 4; $gap = 8;
    $top = (float) ($max ?? max(1e-9, ...array_map(fn ($i) => (float) $i['value'], $items ?: [['value' => 1]])));
    $H = $T + count($items) * ($barH + $gap) + 4;
    $px = fn ($v) => \App\Support\Viz::scale(max(0, min($top, (float) $v)), 0, $top, $L, $W - $R);
@endphp
<div class="ax-scroll-x">
<svg class="ax-viz ax-viz--wide" width="{{ $W }}" height="{{ $H }}" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ $label }}">
    @foreach($items as $i => $it)
        @php $y = $T + $i * ($barH + $gap); $cls = $it['class'] ?? \App\Support\Viz::series((int) ($it['s'] ?? 1)); @endphp
        <g class="ax-viz-hit">
            <title>{{ $it['tip'] ?? ($it['label'] . ': ' . ($it['text'] ?? $it['value'])) }}</title>
            <text class="ax-viz-text ax-viz-text--ink" x="{{ $L - 8 }}" y="{{ $y + $barH / 2 + 4 }}" text-anchor="end">{{ \App\Support\Viz::cut((string) $it['label'], 38) }}</text>
            <rect class="ax-viz-track" x="{{ $L }}" y="{{ $y }}" width="{{ $W - $R - $L }}" height="{{ $barH }}" rx="3" />
            <rect class="{{ $cls }}" x="{{ $L }}" y="{{ $y }}" width="{{ max(0, $px($it['value']) - $L) }}" height="{{ $barH }}" rx="3" stroke-width="0" />
            <text class="ax-viz-text" x="{{ $W - $R + 8 }}" y="{{ $y + $barH / 2 + 4 }}">{{ $it['text'] ?? $it['value'] }}</text>
        </g>
    @endforeach
    @foreach($markers as $m)
        <line class="ax-viz-zero" x1="{{ $px($m['at']) }}" x2="{{ $px($m['at']) }}" y1="{{ $T - 4 }}" y2="{{ $H - 2 }}" />
        <text class="ax-viz-text--faint ax-viz-text" x="{{ $px($m['at']) }}" y="{{ $T - 7 }}" text-anchor="middle">{{ $m['label'] }}</text>
    @endforeach
</svg>
</div>
