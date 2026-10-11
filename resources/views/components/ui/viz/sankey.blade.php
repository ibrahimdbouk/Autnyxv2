@props([
    'left'   => [],   // list of ['key' => , 'label' => ] — e.g. the structural groups
    'right'  => [],   // list of ['key' => , 'label' => , 's' => series 1–8] — e.g. the behavioural groups
    'links'  => [],   // list of ['from' => leftKey, 'to' => rightKey, 'n' => count, 'tip' => ]
    'leftTitle'  => '',
    'rightTitle' => '',
    'unit'   => 'stores',
    'width'  => 900,
    'label'  => 'How the groups map onto each other',
])
@php
    // Two columns of groups; each band carries the stores that sit in both. Band
    // widths are to scale (stores), bands leave each side in the order of the other.
    $W = max(560, (int) $width);
    $total = max(1, array_sum(array_map(fn ($l) => (int) $l['n'], $links)));
    $gap = 10; $T = 26; $nw = 12;
    $nL = max(1, count($left)); $nR = max(1, count($right));
    $H = max(220, (int) (16 * max($nL, $nR) + 0.0) + 260);
    $usable = $H - $T - 10 - $gap * (max($nL, $nR) - 1);
    $scale = $usable / $total;
    $sumBy = fn (string $side, $key) => array_sum(array_map(fn ($l) => (int) $l['n'], array_filter($links, fn ($l) => (string) $l[$side] === (string) $key)));
    $place = function (array $nodes, string $side) use ($sumBy, $scale, $gap, $T) {
        $y = $T; $out = [];
        foreach ($nodes as $i => $n) {
            $h = max(14.0, $sumBy($side, $n['key']) * $scale);   // room for the label
            $out[(string) $n['key']] = $n + ['y0' => $y, 'y1' => $y + $h, 'i' => $i];
            $y += $h + $gap;
        }
        return $out;
    };
    $L = $place($left, 'from'); $R = $place($right, 'to');
    $x1 = (int) ($W * 0.30); $x2 = (int) ($W * 0.62); $mid = ($x1 + $nw + $x2) / 2;
    // Stack bands: on the left in the order of the right groups, on the right in the order of the left groups.
    $rightOrder = array_flip(array_map(fn ($n) => (string) $n['key'], $right));
    $leftOrder = array_flip(array_map(fn ($n) => (string) $n['key'], $left));
    $sorted = $links;
    usort($sorted, fn ($a, $b) => [$leftOrder[(string) $a['from']] ?? 0, $rightOrder[(string) $a['to']] ?? 0] <=> [$leftOrder[(string) $b['from']] ?? 0, $rightOrder[(string) $b['to']] ?? 0]);
    $curL = array_map(fn ($n) => $n['y0'], $L); $curR = array_map(fn ($n) => $n['y0'], $R);
    $bands = [];
    foreach ($sorted as $l) { $bands[] = $l; }
    // Right-side positions follow the left order within each right node.
    $byRight = $sorted;
    usort($byRight, fn ($a, $b) => [$rightOrder[(string) $a['to']] ?? 0, $leftOrder[(string) $a['from']] ?? 0] <=> [$rightOrder[(string) $b['to']] ?? 0, $leftOrder[(string) $b['from']] ?? 0]);
    $rPos = [];
    foreach ($byRight as $l) {
        $h = (int) $l['n'] * $scale; $k = (string) $l['to'];
        if (! isset($curR[$k])) { continue; }
        $rPos[(string) $l['from'] . '|' . $k] = [$curR[$k], $curR[$k] + $h]; $curR[$k] += $h;
    }
    $paths = [];
    foreach ($bands as $l) {
        $h = (int) $l['n'] * $scale; $kl = (string) $l['from']; $kr = (string) $l['to'];
        if (! isset($curL[$kl], $rPos[$kl . '|' . $kr], $R[$kr])) { continue; }
        $a0 = $curL[$kl]; $a1 = $a0 + $h; $curL[$kl] = $a1;
        [$b0, $b1] = $rPos[$kl . '|' . $kr];
        $paths[] = ['d' => 'M' . ($x1 + $nw) . ' ' . round($a0, 2) . ' C' . $mid . ' ' . round($a0, 2) . ' ' . $mid . ' ' . round($b0, 2) . ' ' . $x2 . ' ' . round($b0, 2)
            . ' L' . $x2 . ' ' . round($b1, 2) . ' C' . $mid . ' ' . round($b1, 2) . ' ' . $mid . ' ' . round($a1, 2) . ' ' . ($x1 + $nw) . ' ' . round($a1, 2) . ' Z',
            's' => (int) ($R[$kr]['s'] ?? 1), 'tip' => $l['tip'] ?? ($L[$kl]['label'] . ' → ' . $R[$kr]['label'] . ': ' . $l['n'] . ' ' . $unit)];
    }
    $H = (int) max(array_merge([$H * 0.5], array_map(fn ($n) => $n['y1'] + 10, $L), array_map(fn ($n) => $n['y1'] + 10, $R)));
@endphp
<div class="ax-scroll-x">
<svg class="ax-viz ax-viz--wide" width="{{ $W }}" height="{{ $H }}" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ $label }}">
    <text class="ax-viz-text ax-viz-text--ink" x="{{ $x1 + $nw }}" y="14" text-anchor="end">{{ $leftTitle }}</text>
    <text class="ax-viz-text ax-viz-text--ink" x="{{ $x2 }}" y="14">{{ $rightTitle }}</text>
    @foreach($paths as $p)
        <path class="ax-viz-flow ax-viz-hit {{ \App\Support\Viz::series($p['s']) }}" fill-opacity="0.3" stroke-width="0" d="{{ $p['d'] }}"><title>{{ $p['tip'] }}</title></path>
    @endforeach
    @foreach($L as $n)
        <rect class="ax-viz-base" x="{{ $x1 }}" y="{{ round($n['y0'], 2) }}" width="{{ $nw }}" height="{{ round($n['y1'] - $n['y0'], 2) }}" rx="2" stroke-width="0"><title>{{ $n['label'] }}: {{ $sumBy('from', $n['key']) }} {{ $unit }}</title></rect>
        <text class="ax-viz-text ax-viz-text--ink" x="{{ $x1 - 8 }}" y="{{ round(($n['y0'] + $n['y1']) / 2 + 4, 2) }}" text-anchor="end">{{ \App\Support\Viz::cut((string) $n['label'], 34) }} · {{ $sumBy('from', $n['key']) }}</text>
    @endforeach
    @foreach($R as $n)
        <rect class="{{ \App\Support\Viz::series((int) ($n['s'] ?? 1)) }}" x="{{ $x2 }}" y="{{ round($n['y0'], 2) }}" width="{{ $nw }}" height="{{ round($n['y1'] - $n['y0'], 2) }}" rx="2" stroke-width="0"><title>{{ $n['label'] }}: {{ $sumBy('to', $n['key']) }} {{ $unit }}</title></rect>
        <text class="ax-viz-text ax-viz-text--ink" x="{{ $x2 + $nw + 8 }}" y="{{ round(($n['y0'] + $n['y1']) / 2 + 4, 2) }}">{{ \App\Support\Viz::cut((string) $n['label'], 38) }} · {{ $sumBy('to', $n['key']) }}</text>
    @endforeach
</svg>
</div>
