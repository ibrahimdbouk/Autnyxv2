@props([
    'title' => '',
    'kicker' => null,
    'sub' => null,
])
<div {{ $attributes->merge(['class' => 'ax-section']) }}>
    @if($kicker)<div class="ax-section-kicker">{{ $kicker }}</div>@endif
    <h2 class="ax-section-title">{{ $title }}</h2>
    @if($sub)<p class="ax-section-sub">{{ $sub }}</p>@endif
</div>
