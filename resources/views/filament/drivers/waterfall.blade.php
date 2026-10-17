<x-filament-widgets::widget>
    <x-ui.card :title="$title">
        @if($steps === [])
            <x-ui.empty title="Nothing to show">No sales in this scope for the period or its comparison.</x-ui.empty>
        @else
            <p class="ax-muted ax-text-sm ax-mb-3">{{ $intro }}</p>
            <x-ui.viz.waterfall :steps="$steps" :total="$total" :fmt="$fmt" :label="$title" />
            @foreach($extra as $line)
                <p class="ax-faint ax-text-xs ax-mt-1">{{ $line }}</p>
            @endforeach
        @endif
    </x-ui.card>
</x-filament-widgets::widget>
