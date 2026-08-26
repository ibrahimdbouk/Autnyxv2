@props([
    'label' => '',
    'value' => '',
    'color' => null,    // null | 'success' | 'warning' | 'danger' | 'info'
    'foot' => null,
    'trend' => null,    // null | 'up' | 'down'
    'href' => null,
])
@php
    $classes = 'ax-stat' . ($color ? " ax-stat--{$color}" : '');
    $trendCls = $trend === 'up' ? ' ax-stat-trend--up' : ($trend === 'down' ? ' ax-stat-trend--down' : '');
    $footContent = $foot ?? (trim($slot) !== '' ? $slot : null);
@endphp
@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        <span class="ax-stat-label">{{ $label }}</span>
        <span class="ax-stat-value">{{ $value }}</span>
        @if($footContent !== null)<span class="ax-stat-foot{{ $trendCls }}">{{ $footContent }}</span>@endif
    </a>
@else
    <div {{ $attributes->merge(['class' => $classes]) }}>
        <span class="ax-stat-label">{{ $label }}</span>
        <span class="ax-stat-value">{{ $value }}</span>
        @if($footContent !== null)<span class="ax-stat-foot{{ $trendCls }}">{{ $footContent }}</span>@endif
    </div>
@endif
