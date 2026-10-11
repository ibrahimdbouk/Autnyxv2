<x-filament-widgets::widget>
    <x-ui.card title="Stores per cluster">
        @if(! $viz)
            <x-ui.empty title="No clusters yet">Clusters are built from the store file and rebuilt as it changes.</x-ui.empty>
        @else
            <x-ui.viz.donut :segments="$viz['donut']" :center="(string) $viz['stores']" sub="stores" label="Stores per cluster" :stack="true" />
        @endif
    </x-ui.card>
</x-filament-widgets::widget>
