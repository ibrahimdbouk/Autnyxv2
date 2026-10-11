<x-filament-widgets::widget>
    <x-ui.card title="Basket value against daily sales">
        @if(! $viz || $viz['scatter'] === [])
            <x-ui.empty title="No store profiles yet">Stores are placed once their profiles are computed.</x-ui.empty>
        @else
            @php $m = fn ($v) => \App\Support\Money::compact((float) $v, $currency); @endphp
            <x-ui.viz.scatter :points="$viz['scatter']" :series="$focus ? [] : $viz['series']" x-label="Basket value" y-label="Daily sales" :x-fmt="$m" :y-fmt="$m"
                :y-from="0" label="Stores by basket value and daily sales" :width="480" height="320" />
        @endif
    </x-ui.card>
</x-filament-widgets::widget>
