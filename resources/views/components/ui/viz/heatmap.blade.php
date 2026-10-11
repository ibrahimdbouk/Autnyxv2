@props([
    'rows'   => [],          // list of ['key' => , 'label' => , 'sub' => ?]
    'cols'   => [],          // list of ['key' => , 'label' => ]
    'cells'  => [],          // [rowKey][colKey] => ['v' => ?float, 'text' => string, 'tip' => string]
    'mode'   => 'diverging', // diverging (around 1.0) | good | bad (shares 0–1)
    'spread' => 0.6,         // diverging: how far from 1.0 is full colour
    'domain' => null,        // good/bad: [low, high] the shades span (default 0–1)
    'cellW'  => 72,
    'cellH'  => 30,
    'rowChars' => 34,        // row labels cut at this many characters (full text on hover)
    'label'  => 'Heatmap',
    'legend' => [],          // ['low' => , 'mid' => , 'high' => ] or ['items' => [['class' => , 'opacity' => , 'label' => ], …]]
])
@php
    $maxRow = min((int) $rowChars, max(4, ...array_map(fn ($r) => mb_strlen((string) $r['label']), $rows ?: [['label' => '']])));
    $labelW = (int) min(230, max(90, $maxRow * 7.2 + 16));
    $maxCol = max(1, ...array_map(fn ($c) => mb_strlen((string) $c['label']), $cols ?: [['label' => '']]));
    $rotate = $maxCol * 6.4 > $cellW - 6;
    $colChars = 32;
    $headH = $rotate ? (int) min(150, min($maxCol, $colChars) * 6.4 * 0.62 + 18) : 24;
    $extra = $rotate ? (int) min(170, min($maxCol, $colChars) * 6.4 * 0.8) : 8;
    $w = $labelW + count($cols) * $cellW + $extra;
    $h = $headH + count($rows) * $cellH + 4;
@endphp
<div class="ax-scroll-x">
<svg class="ax-viz ax-viz--fixed" width="{{ $w }}" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="{{ $label }}">
    @foreach($cols as $j => $c)
        @php $cx = $labelW + $j * $cellW + $cellW / 2; @endphp
        @if($rotate)
            <text class="ax-viz-text" x="{{ $cx - 4 }}" y="{{ $headH - 8 }}" transform="rotate(-38 {{ $cx - 4 }} {{ $headH - 8 }})"><title>{{ $c['label'] }}</title>{{ \App\Support\Viz::cut((string) $c['label'], $colChars) }}</text>
        @else
            <text class="ax-viz-text" x="{{ $cx }}" y="{{ $headH - 8 }}" text-anchor="middle">{{ $c['label'] }}</text>
        @endif
    @endforeach
    @foreach($rows as $i => $r)
        @php $y = $headH + $i * $cellH; @endphp
        <text class="ax-viz-text ax-viz-text--ink" x="{{ $labelW - 10 }}" y="{{ $y + $cellH / 2 + 4 }}" text-anchor="end"><title>{{ $r['label'] }}{{ ! empty($r['sub']) ? ' · ' . $r['sub'] : '' }}</title>{{ \App\Support\Viz::cutMiddle((string) $r['label'], (int) $rowChars) }}</text>
        @foreach($cols as $j => $c)
            @php
                $cell = $cells[$r['key']][$c['key']] ?? null;
                $heat = \App\Support\Viz::heat($cell['v'] ?? null, $mode, (float) $spread, $domain);
                $x = $labelW + $j * $cellW;
            @endphp
            <g class="ax-viz-hit">
                <title>{{ $cell['tip'] ?? ($r['label'] . ' · ' . $c['label'] . ': no figure') }}</title>
                <rect class="ax-viz-cell {{ $heat['class'] }}" x="{{ $x }}" y="{{ $y }}" width="{{ $cellW }}" height="{{ $cellH }}" rx="4" fill-opacity="{{ $heat['opacity'] }}" />
                @if(($cell['text'] ?? '') !== '')
                    <text class="{{ \App\Support\Viz::onDark($heat) ? 'ax-viz-text--on' : 'ax-viz-text--cell' }}" x="{{ $x + $cellW / 2 }}" y="{{ $y + $cellH / 2 + 4 }}" text-anchor="middle">{{ $cell['text'] }}</text>
                @endif
            </g>
        @endforeach
    @endforeach
</svg>
</div>
@if($legend)
    <div class="ax-viz-legend" aria-hidden="true">
        @if(isset($legend['items']))
            @foreach($legend['items'] as $it)
                <span><svg class="ax-viz-key" viewBox="0 0 10 10"><rect class="{{ $it['class'] }}" width="10" height="10" rx="2" fill-opacity="{{ $it['opacity'] ?? 1 }}" /></svg>{{ $it['label'] }}</span>
            @endforeach
        @elseif($mode === 'diverging')
            <span><svg class="ax-viz-key" viewBox="0 0 10 10"><rect class="ax-viz-heat-down" width="10" height="10" rx="2" fill-opacity="0.8" /></svg>{{ $legend['low'] ?? 'Below' }}</span>
            <span><svg class="ax-viz-key" viewBox="0 0 10 10"><rect class="ax-viz-heat-up" width="10" height="10" rx="2" fill-opacity="0.12" /></svg>{{ $legend['mid'] ?? 'About the same' }}</span>
            <span><svg class="ax-viz-key" viewBox="0 0 10 10"><rect class="ax-viz-heat-up" width="10" height="10" rx="2" fill-opacity="0.8" /></svg>{{ $legend['high'] ?? 'Above' }}</span>
        @else
            <span><svg class="ax-viz-key" viewBox="0 0 10 10"><rect class="ax-viz-heat-bad" width="10" height="10" rx="2" fill-opacity="{{ $mode === 'bad' ? '0.12' : '0.8' }}" /></svg>{{ $legend['low'] ?? 'Low' }}</span>
            @if($mode === 'good')<span><svg class="ax-viz-key" viewBox="0 0 10 10"><rect class="ax-viz-heat-good" width="10" height="10" rx="2" fill-opacity="0.12" /></svg>{{ $legend['mid'] ?? 'Middling' }}</span>@endif
            <span><svg class="ax-viz-key" viewBox="0 0 10 10"><rect class="{{ $mode === 'bad' ? 'ax-viz-heat-bad' : 'ax-viz-heat-good' }}" width="10" height="10" rx="2" fill-opacity="0.8" /></svg>{{ $legend['high'] ?? 'High' }}</span>
        @endif
        <span><svg class="ax-viz-key" viewBox="0 0 10 10"><rect class="ax-viz-cell--empty" width="10" height="10" rx="2" /></svg>No figure</span>
        <span>Hover a cell for its figures</span>
    </div>
@endif
