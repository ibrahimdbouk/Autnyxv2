<x-filament-widgets::widget>
    <x-ui.card title="How each cluster trades against the average store">
        @if(! $viz || ! $viz['profile'])
            <x-ui.empty title="No store profiles yet">Store profiles are computed overnight from sales; each cluster's trading profile appears here then.</x-ui.empty>
        @else
            <x-ui.viz.heatmap :rows="$viz['profile']['rows']" :cols="$viz['profile']['cols']" :cells="$viz['profile']['cells']" mode="diverging" :spread="0.5" :cell-w="54" :row-chars="27"
                :legend="['low' => 'Below the average store', 'mid' => 'About average (100)', 'high' => 'Above it']" label="Cluster profiles against the average store" />
            <p class="ax-faint ax-text-xs ax-mt-2">Each cell is the cluster's average against the average store's (100 = the same): what sets the group apart. Hover for the figures. From the store profiles, rebuilt overnight.</p>
        @endif
    </x-ui.card>
</x-filament-widgets::widget>
