<x-filament-widgets::widget>
    @if($items !== [])
        <x-ui.card title="What moved the sales, and why">
            @foreach($items as $it)
                <div class="ax-mb-4">
                    <div class="ax-between ax-wrap ax-mb-2">
                        <strong class="ax-text-sm">{{ $it['title'] }}</strong>
                        @if($it['explained'] === 'availability')
                            <x-ui.badge color="warning">Mostly out of stock · {{ round(100 * $it['share']) }}%</x-ui.badge>
                        @elseif($it['explained'] === 'calendar')
                            <x-ui.badge color="info">Explained by promotions / events · {{ round(100 * $it['share']) }}%</x-ui.badge>
                        @endif
                    </div>
                    <p class="ax-text-sm ax-mb-2">{{ $it['headline'] }}</p>
                    <x-ui.viz.waterfall :steps="$it['steps']" :total="$it['total']" :fmt="$fmt" label="Sales drivers" />
                    <p class="ax-faint ax-text-xs ax-mt-1">{{ $it['text'] }}</p>
                    @if($it['events'] !== [])
                        <p class="ax-faint ax-text-xs ax-mt-1">Calendar events in one period more than the other: {{ implode(', ', $it['events']) }} (part of the department trend).</p>
                    @endif
                </div>
            @endforeach
            <p class="ax-faint ax-text-xs">Computed from the data, not by AI: each cause is measured, and the bars add up to the change.</p>
        </x-ui.card>
    @endif
</x-filament-widgets::widget>
