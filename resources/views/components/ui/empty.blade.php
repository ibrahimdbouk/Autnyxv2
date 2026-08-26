@props([
    'title' => 'Nothing here yet',
    'icon' => '◎',
])
<div {{ $attributes->merge(['class' => 'ax-empty']) }}>
    <div class="ax-empty-icon">{{ $icon }}</div>
    <p class="ax-empty-title">{{ $title }}</p>
    @if(trim($slot) !== '')<p class="ax-empty-body">{{ $slot }}</p>@endif
</div>
