<x-filament-panels::page>
    @if(! $has)
        <x-ui.empty title="No briefing yet">
            @if($live)
                The briefing is built every Monday morning and sent to head office. Build this week's now with the button above.
            @else
                The briefing starts once range decisions are open to users (after the validation review).
            @endif
        </x-ui.empty>
    @endif
</x-filament-panels::page>
