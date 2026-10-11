<x-filament-widgets::widget>
    <x-ui.card :title="$focus ? 'Where this cluster\'s stores are' : 'Where the clusters are'">
        @if(! $viz || $viz['map']['points'] === [])
            <x-ui.empty title="No store can be placed yet">Add latitude and longitude to the store file, or a city, and the stores appear here.</x-ui.empty>
        @else
            @php $placed = $viz['map']['placed']; @endphp
            <x-ui.viz.map :points="$viz['map']['points']" :landmarks="$viz['map']['landmarks']" :series="$focus ? [] : $viz['series']"
                :label="'Stores on a schematic map, coloured by cluster'" :width="820" :height="440">
                <span>Bigger dot: more daily sales</span>
                @if($focus)<span>Faint: the other clusters</span>@endif
            </x-ui.viz.map>
            <p class="ax-faint ax-text-xs ax-mt-2">Schematic: to scale, without a base map; hover a store for its figures.
                {{ $placed['exact'] }} store(s) at their coordinates{{ $placed['city'] ? ', ' . $placed['city'] . ' placed at their city (add latitude and longitude to the store file for exact positions)' : '' }}{{ $placed['none'] ? ', ' . $placed['none'] . ' with no coordinates or known city, not shown' : '' }}.</p>
        @endif
    </x-ui.card>
</x-filament-widgets::widget>
