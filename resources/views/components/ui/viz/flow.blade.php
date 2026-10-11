@props([
    'source'  => ['label' => '', 'text' => ''],
    'targets' => [],   // list of ['label' => , 'share' => 0–1, 'kind' => keep|lose|gain, 'text' => , 'tip' => ]
    'width'    => 640,    // drawn at this many pixels wide at most (it shrinks to fit, never grows)
    'label'   => 'Where the buyers go',
])
@php
    $W = max(480, (int) $width); $T = 10; $B = 10;
    $n = count($targets);
    $H = max(140, 30 * $n + 24);
    $gap = 8; $avail = $H - $T - $B - $gap * max(0, $n - 1);
    $x1 = 168; $x2 = 400; $nw = 12;
    $sum = max(1e-9, array_sum(array_map(fn ($t) => max(0, (float) $t['share']), $targets)));
    $ya = $T; $yb = $T; $bands = [];
    foreach ($targets as $t) {
        $h = max(2.0, max(0, (float) $t['share']) / $sum * $avail);
        $hl = max(1.0, max(0, (float) $t['share']) / $sum * ($H - $T - $B));
        $bands[] = $t + ['a0' => $ya, 'a1' => $ya + $hl, 'b0' => $yb, 'b1' => $yb + $h];
        $ya += $hl; $yb += $h + $gap;
    }
    $mid = ($x1 + $nw + $x2) / 2;
    // Labels at their band's middle, pushed down where bands are too thin to hold them apart.
    $prev = -INF;
    foreach ($bands as $k => $b) {
        $bands[$k]['ly'] = $prev = max(($b['b0'] + $b['b1']) / 2 + 4, $prev + 16);
    }
@endphp
<div class="ax-scroll-x">
<svg class="ax-viz ax-viz--wide" width="{{ $W }}" height="{{ $H }}" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ $label }}">
    <rect class="ax-viz-source" x="{{ $x1 }}" y="{{ $T }}" width="{{ $nw }}" height="{{ $H - $T - $B }}" rx="2" stroke-width="0" />
    <text class="ax-viz-text--strong ax-viz-text" x="{{ $x1 - 10 }}" y="{{ $H / 2 - 4 }}" text-anchor="end">{{ \App\Support\Viz::cut((string) $source['label'], 24) }}</text>
    <text class="ax-viz-text" x="{{ $x1 - 10 }}" y="{{ $H / 2 + 12 }}" text-anchor="end">{{ $source['text'] ?? '' }}</text>
    @foreach($bands as $b)
        @php $cls = ['lose' => 'ax-viz-lose', 'gain' => 'ax-viz-up'][$b['kind'] ?? 'keep'] ?? 'ax-viz-keep'; @endphp
        <g class="ax-viz-hit">
            <title>{{ $b['tip'] ?? ($b['label'] . ': ' . ($b['text'] ?? '')) }}</title>
            <path class="ax-viz-flow {{ $cls }}" fill-opacity="0.28"
                d="M{{ $x1 + $nw }} {{ round($b['a0'], 2) }} C{{ $mid }} {{ round($b['a0'], 2) }} {{ $mid }} {{ round($b['b0'], 2) }} {{ $x2 }} {{ round($b['b0'], 2) }} L{{ $x2 }} {{ round($b['b1'], 2) }} C{{ $mid }} {{ round($b['b1'], 2) }} {{ $mid }} {{ round($b['a1'], 2) }} {{ $x1 + $nw }} {{ round($b['a1'], 2) }} Z" />
            <rect class="{{ $cls }}" x="{{ $x2 }}" y="{{ round($b['b0'], 2) }}" width="{{ $nw }}" height="{{ round($b['b1'] - $b['b0'], 2) }}" rx="2" stroke-width="0" />
            <text class="ax-viz-text" x="{{ $x2 + $nw + 8 }}" y="{{ round($b['ly'], 2) }}"><tspan class="ax-viz-text--ink">{{ \App\Support\Viz::cut((string) $b['label'], 32) }}</tspan>@if(($b['text'] ?? '') !== '') · {{ $b['text'] }}@endif</text>
        </g>
    @endforeach
</svg>
</div>
