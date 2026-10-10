<x-filament-widgets::widget>
    @if($b)
        <x-ui.card>
            <div class="ax-between ax-wrap ax-mb-2">
                <span class="ax-faint ax-text-xs">Week of {{ $b->week_of->format('j M Y') }} · built {{ $b->updated_at?->format('j M, H:i') }}{{ $b->sent_at ? ' · sent ' . $b->sent_at->format('j M') : '' }}</span>
                @if($b->narrated())
                    <x-ui.badge color="info">Written by AI from the facts below</x-ui.badge>
                @else
                    <x-ui.badge>Written by the rules</x-ui.badge>
                @endif
            </div>
            <h2 class="ax-text-lg ax-fw-700 ax-ink ax-m-0">{{ $b->headline() }}</h2>
            @if($b->summary())<p class="ax-text-sm ax-mt-2 ax-lh">{{ $b->summary() }}</p>@endif
            @if(! empty($b->content['focus']))
                <div class="ax-mt-3">
                    <div class="ax-faint ax-text-xs ax-fw-600">This week</div>
                    <ol class="ax-list ax-text-sm ax-mt-1">
                        @foreach($b->content['focus'] as $line)<li>{{ $line }}</li>@endforeach
                    </ol>
                </div>
            @endif
            <p class="ax-faint ax-text-xs ax-mt-3 ax-mb-1">The rules choose and rank what is here, from the records. When AI is on it rewrites only the headline and summary, and any sentence naming a number that is not in the facts is dropped.</p>
        </x-ui.card>
    @endif
</x-filament-widgets::widget>
