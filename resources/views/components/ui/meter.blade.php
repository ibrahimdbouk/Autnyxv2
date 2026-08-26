@props([
    'value' => 0,      // 0–100
    'color' => null,   // null (accent) | success | warning | danger
])
@php
    $v = max(0, min(100, (float) $value));
    $fill = 'ax-meter-fill' . ($color ? " ax-meter-fill--{$color}" : '');
@endphp
<div {{ $attributes->merge(['class' => 'ax-meter']) }}>
    <div class="{{ $fill }}" style="width: {{ $v }}%"></div>
</div>
