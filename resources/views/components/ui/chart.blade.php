@props([
    'title' => null,
])
<div {{ $attributes->merge(['class' => 'ax-chart']) }}>
    @if($title)<p class="ax-chart-title">{{ $title }}</p>@endif
    {{ $slot }}
</div>
