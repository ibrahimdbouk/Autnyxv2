@props([
    'segments' => [],   // list of ['label' => , 'value' => , 's' => series 1–8, 'text' => ?]
    'center'   => null, // the big figure in the middle
    'sub'      => null, // and the line under it
    'label'    => 'Donut chart',
    'stack'    => false,  // legend under the ring (a narrow card) instead of beside it
])
@php
    $total = array_sum(array_map(fn ($s) => max(0, (float) $s['value']), $segments));
    $a = 0.0;
    $arcs = [];
    foreach ($segments as $i => $s) {
        $v = max(0, (float) $s['value']);
        if ($total <= 0 || $v <= 0) { continue; }
        $span = $v / $total * 2 * M_PI;
        $arcs[] = ['d' => \App\Support\Viz::arc(100, 100, 92, 60, $a + 0.012, $a + $span - 0.012), 's' => $s['s'] ?? $i + 1,
            'tip' => $s['label'] . ': ' . ($s['text'] ?? number_format($v)) . ' (' . (int) round($v / $total * 100) . '%)'];
        $a += $span;
    }
@endphp
<div @class(['ax-viz-split' => ! $stack, 'ax-stack' => $stack])>
    <svg class="ax-viz-donut" viewBox="0 0 200 200" role="img" aria-label="{{ $label }}">
        @if($arcs === [])
            <circle class="ax-viz-track" cx="100" cy="100" r="76" />
        @endif
        @foreach($arcs as $arc)
            <path class="ax-viz-hit {{ \App\Support\Viz::series((int) $arc['s']) }}" d="{{ $arc['d'] }}" stroke-width="0"><title>{{ $arc['tip'] }}</title></path>
        @endforeach
        @if($center !== null)
            <text class="ax-viz-text--big ax-viz-text" x="100" y="104" text-anchor="middle">{{ $center }}</text>
        @endif
        @if($sub)
            <text class="ax-viz-text" x="100" y="124" text-anchor="middle">{{ $sub }}</text>
        @endif
    </svg>
    <ul class="ax-list ax-text-sm">
        @foreach($segments as $i => $s)
            <li class="ax-row ax-gap-2">
                <svg class="ax-viz-key" viewBox="0 0 10 10" aria-hidden="true"><rect class="{{ \App\Support\Viz::series((int) ($s['s'] ?? $i + 1)) }}" width="10" height="10" rx="2" /></svg>
                <span class="ax-fw-600 ax-ink">{{ $s['label'] }}</span>
                <span class="ax-faint">{{ $s['text'] ?? number_format((float) $s['value']) }}{{ $total > 0 ? ' · ' . (int) round(max(0, (float) $s['value']) / $total * 100) . '%' : '' }}</span>
            </li>
        @endforeach
    </ul>
</div>
