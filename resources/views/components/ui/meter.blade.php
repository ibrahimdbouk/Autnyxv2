@props([
    'value' => 0,      // 0–100
    'color' => null,   // null (accent) | success | warning | danger
])
@php
    $v = max(0, min(100, (float) $value));
    $bar = 'ax-meter-bar' . ($color ? " ax-meter-bar--{$color}" : '');
@endphp
{{-- The fill's length is an SVG attribute, so the view carries no inline CSS. --}}
<svg {{ $attributes->merge(['class' => 'ax-meter-svg']) }} viewBox="0 0 100 4" preserveAspectRatio="none" role="img" aria-label="{{ (int) round($v) }}%">
    <rect class="ax-meter-track" x="0" y="0" width="100" height="4" rx="2" />
    <rect class="{{ $bar }}" x="0" y="0" width="{{ $v }}" height="4" rx="2" />
</svg>
