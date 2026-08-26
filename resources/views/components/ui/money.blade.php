@props([
    'amount' => 0,
    'currency' => null,   // ISO code; defaults to the current tenant's
    'decimals' => 2,
    'signed' => false,    // colour positive/negative
])
@php
    $cur = $currency ?? optional(\Filament\Facades\Filament::getTenant())->currencyCode();
    $val = (float) $amount;
    $classes = 'ax-money' . ($signed ? ($val > 0 ? ' ax-money--pos' : ($val < 0 ? ' ax-money--neg' : '')) : '');
@endphp
<span {{ $attributes->merge(['class' => $classes]) }}>{{ \App\Support\Money::format($val, $cur, (int) $decimals) }}</span>
