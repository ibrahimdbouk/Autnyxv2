@props([
    'title' => null,
    'variant' => null,   // null | 'accent'
    'soft' => false,
])
@php
    $classes = 'ax-card'
        . ($soft ? ' ax-card--soft' : '')
        . ($variant === 'accent' ? ' ax-card--accent' : '');
@endphp
<div {{ $attributes->merge(['class' => $classes]) }}>
    @if($title !== null || isset($actions))
        <div class="ax-card-head">
            <span class="ax-card-title">{{ $title }}</span>
            @isset($actions)<div class="ax-card-actions">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div class="ax-card-body">{{ $slot }}</div>
</div>
