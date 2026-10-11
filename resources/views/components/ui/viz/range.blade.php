@props([
    'low'  => 0.0,   // the range of a figure, on a scale shared with its neighbours
    'mid'  => 0.0,
    'high' => 0.0,
    'min'  => -1.0,  // the shared scale
    'max'  => 1.0,
    'tip'  => '',
])
@php
    $x = fn ($v) => \App\Support\Viz::scale(max($min, min($max, (float) $v)), (float) $min, (float) $max, 4, 196);
    $lo = min($low, $high); $hi = max($low, $high);
@endphp
{{-- A figure's range against a shared scale: the band is low–high, the dot the middle, the dashed line zero. --}}
<svg {{ $attributes->merge(['class' => 'ax-range-svg']) }} viewBox="0 0 200 24" role="img" aria-label="{{ $tip }}">
    <title>{{ $tip }}</title>
    <rect class="ax-viz-track" x="4" y="9" width="192" height="6" rx="3" />
    @if($min < 0 && $max > 0)
        <line class="ax-viz-zero" x1="{{ $x(0) }}" x2="{{ $x(0) }}" y1="2" y2="22" />
    @endif
    <rect class="{{ $mid >= 0 ? 'ax-viz-up' : 'ax-viz-down' }}" x="{{ $x($lo) }}" y="8" width="{{ max(2, $x($hi) - $x($lo)) }}" height="8" rx="4" fill-opacity="0.45" stroke-width="0" />
    <circle class="{{ $mid >= 0 ? 'ax-viz-up' : 'ax-viz-down' }}" cx="{{ $x($mid) }}" cy="12" r="4" stroke-width="0" />
</svg>
