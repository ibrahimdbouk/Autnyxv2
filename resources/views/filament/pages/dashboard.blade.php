<x-filament-panels::page>

{{-- The one Dashboard: a switch between each app's own dashboard. Each tab is
     rendered by that app (App\Filament\Dashboards\*); nothing is merged. --}}
@if(count($tabs) > 1)
    <nav class="ax-app-switch" aria-label="Choose an app dashboard">
        <x-filament::tabs>
            @foreach($tabs as $tab)
                <x-filament::tabs.item
                    tag="a"
                    :href="$tab['url']"
                    :active="$tab['active']"
                    :icon="$tab['icon']"
                >
                    {{ $tab['label'] }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    </nav>
@endif

@if($appView)
    @include($appView, $appData)
@else
    <x-ui.card>
        <x-ui.empty title="No apps are switched on">
            Your organisation does not have an Autnyx app yet. Ask your Autnyx contact to switch one on.
        </x-ui.empty>
    </x-ui.card>
@endif

</x-filament-panels::page>
