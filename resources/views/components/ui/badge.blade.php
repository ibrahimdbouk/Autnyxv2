@props([
    'color' => null,   // accent|success|warning|danger|info | high|medium|low | null (neutral)
    'dot' => false,
])
@php $classes = 'ax-badge' . ($color ? " ax-badge--{$color}" : ''); @endphp
<span {{ $attributes->merge(['class' => $classes]) }}>@if($dot)<span class="ax-badge-dot"></span>@endif{{ $slot }}</span>
