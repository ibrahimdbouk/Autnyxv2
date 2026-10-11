@props([
    'points'   => [],     // list of ['x' => , 'y' => , 's' => series 1–8, 'tip' => , 'hollow' => bool]
    'series'   => [],     // series number => label (the legend)
    'xLabel'   => '',
    'yLabel'   => '',
    'xFmt'     => null,   // fn (float) => string, for the ticks
    'yFmt'     => null,
    'diagonal' => false,  // the y = x line ("as estimated")
    'refX'     => null,   // ['at' => , 'label' => ] a vertical reference line
    'refY'     => null,
    'geo'      => false,  // a schematic map: x = longitude, y = latitude, equal scale, no axes
    'yFrom'    => null,   // start the y axis here at the latest (e.g. 0 for a share)
    'width'    => 640,    // drawn at this many pixels wide at most (it shrinks to fit, never grows)
    'label'    => 'Scatter chart',
    'height'   => 340,
])
@php
    $W = max(480, (int) $width); $H = (int) $height;
    $L = $geo ? 16 : 62; $R = 16; $T = 14; $B = $geo ? 16 : 44;
    $xs = array_map(fn ($p) => (float) $p['x'], $points);
    $ys = array_map(fn ($p) => (float) $p['y'], $points);
    $xFmt ??= fn ($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
    $yFmt ??= fn ($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
    if ($points === []) { $xs = $ys = [0, 1]; }
    $x0 = min($xs); $x1 = max($xs); $y0 = min($ys); $y1 = max($ys);
    foreach ([$refX['at'] ?? null] as $v) { if ($v !== null) { $x0 = min($x0, $v); $x1 = max($x1, $v); } }
    foreach ([$refY['at'] ?? null] as $v) { if ($v !== null) { $y0 = min($y0, $v); $y1 = max($y1, $v); } }
    if ($yFrom !== null) { $y0 = min($y0, (float) $yFrom); if ($y1 - $y0 < 1e-9) { $y1 = $y0 + max(abs($y0) * 0.1, 0.1); } }
    if ($diagonal) { $x0 = $y0 = min($x0, $y0); $x1 = $y1 = max($x1, $y1); }
    if ($geo) {
        // Equal scale both ways (longitude shrunk by the latitude's cosine), centred.
        $k = cos(deg2rad(($y0 + $y1) / 2));
        $spanX = max(1e-6, ($x1 - $x0) * $k); $spanY = max(1e-6, $y1 - $y0);
        $u = min(($W - $L - $R) / $spanX, ($H - $T - $B) / $spanY) * 0.92;
        $cx = ($x0 + $x1) / 2; $cy = ($y0 + $y1) / 2;
        $px = fn ($x) => round($W / 2 + ($x - $cx) * $k * $u, 2);
        $py = fn ($y) => round($H / 2 - ($y - $cy) * $u, 2);
        $xt = $yt = [];
    } else {
        $xt = \App\Support\Viz::ticks($x0, $x1, 6); $yt = \App\Support\Viz::ticks($y0, $y1, 5);
        $x0 = min($x0, $xt[0]); $x1 = max($x1, end($xt)); $y0 = min($y0, $yt[0]); $y1 = max($y1, end($yt));
        $L = (int) max($L, 30 + 6.3 * max(array_map(fn ($t) => mb_strlen((string) $yFmt($t)), $yt)));   // room for the widest tick label
        $px = fn ($x) => \App\Support\Viz::scale((float) $x, $x0, $x1, $L, $W - $R);
        $py = fn ($y) => \App\Support\Viz::scale((float) $y, $y0, $y1, $H - $B, $T);
    }
@endphp
<div class="ax-scroll-x">
<svg class="ax-viz ax-viz--wide" width="{{ $W }}" height="{{ $H }}" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ $label }}">
    @if($geo)
        <rect class="ax-viz-track" x="0" y="0" width="{{ $W }}" height="{{ $H }}" rx="8" />
    @endif
    @unless($geo)
        @foreach($yt as $t)
            <line class="ax-viz-grid" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $py($t) }}" y2="{{ $py($t) }}" />
            <text class="ax-viz-text" x="{{ $L - 8 }}" y="{{ $py($t) + 4 }}" text-anchor="end">{{ $yFmt($t) }}</text>
        @endforeach
        @foreach($xt as $t)
            <text class="ax-viz-text" x="{{ $px($t) }}" y="{{ $H - $B + 16 }}" text-anchor="middle">{{ $xFmt($t) }}</text>
        @endforeach
        <line class="ax-viz-axis" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $H - $B }}" y2="{{ $H - $B }}" />
        <text class="ax-viz-text ax-viz-text--ink" x="{{ ($L + $W - $R) / 2 }}" y="{{ $H - 6 }}" text-anchor="middle">{{ $xLabel }}</text>
        <text class="ax-viz-text ax-viz-text--ink" x="14" y="{{ ($T + $H - $B) / 2 }}" text-anchor="middle" transform="rotate(-90 14 {{ ($T + $H - $B) / 2 }})">{{ $yLabel }}</text>
        @if($diagonal)
            <line class="ax-viz-ideal" x1="{{ $px($x0) }}" y1="{{ $py($x0) }}" x2="{{ $px($x1) }}" y2="{{ $py($x1) }}" />
            <text class="ax-viz-text--faint ax-viz-text" x="{{ $px($x1) - 4 }}" y="{{ $py($x1) + 14 }}" text-anchor="end">as estimated</text>
        @endif
        @if($refX)
            <line class="ax-viz-zero" x1="{{ $px($refX['at']) }}" x2="{{ $px($refX['at']) }}" y1="{{ $T }}" y2="{{ $H - $B }}" />
            <text class="ax-viz-text--faint ax-viz-text" x="{{ $px($refX['at']) + 4 }}" y="{{ $T + 10 }}">{{ $refX['label'] ?? '' }}</text>
        @endif
        @if($refY)
            <line class="ax-viz-zero" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $py($refY['at']) }}" y2="{{ $py($refY['at']) }}" />
            <text class="ax-viz-text--faint ax-viz-text" x="{{ $W - $R - 4 }}" y="{{ $py($refY['at']) - 5 }}" text-anchor="end">{{ $refY['label'] ?? '' }}</text>
        @endif
    @endunless
    @foreach($points as $p)
        <circle class="ax-viz-dot ax-viz-hit {{ \App\Support\Viz::series((int) ($p['s'] ?? 1)) }}{{ ! empty($p['hollow']) ? ' ax-viz-dot--hollow' : '' }}" cx="{{ $px($p['x']) }}" cy="{{ $py($p['y']) }}" r="{{ $p['r'] ?? 6 }}" fill-opacity="0.85"><title>{{ $p['tip'] ?? '' }}</title></circle>
    @endforeach
</svg>
</div>
@if($series)
    <div class="ax-viz-legend" aria-hidden="true">
        @foreach($series as $i => $name)
            <span><svg class="ax-viz-key" viewBox="0 0 10 10"><circle class="{{ \App\Support\Viz::series((int) $i) }}" cx="5" cy="5" r="4.5" /></svg>{{ $name }}</span>
        @endforeach
        {{ $slot }}
    </div>
@endif
