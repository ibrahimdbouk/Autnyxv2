<x-filament-panels::page>
    @php
        $percent = $this->record->progressPercent();
        $processed = (int) $this->record->process_cursor;
        $total = (int) $this->record->total_rows;
    @endphp

    <div
        @if (! $done && $this->record->isImporting())
            wire:poll.1500ms="tick"
        @endif
        class="mx-auto w-full max-w-xl"
    >
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center gap-x-3">
                @unless ($done)
                    <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
                    <span class="text-base font-semibold text-gray-950 dark:text-white">
                        Importing your file…
                    </span>
                @else
                    <x-filament::icon
                        icon="heroicon-o-check-circle"
                        class="h-6 w-6 text-success-500"
                    />
                    <span class="text-base font-semibold text-gray-950 dark:text-white">
                        Import complete
                    </span>
                @endunless
            </div>

            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Large files are processed in batches so nothing times out — you can leave this page open.
            </p>

            {{-- Progress bar --}}
            <div class="mt-5">
                <div class="h-3 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                    <div
                        class="h-full rounded-full bg-primary-500 transition-all duration-500 ease-out"
                        style="width: {{ max($percent, $done ? 100 : 2) }}%"
                    ></div>
                </div>

                <div class="mt-2 flex items-center justify-between text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-300">
                        {{ number_format($processed) }}@if ($total > 0) / {{ number_format($total) }}@endif rows
                    </span>
                    <span class="tabular-nums text-gray-500 dark:text-gray-400">
                        {{ $done ? 100 : $percent }}%
                    </span>
                </div>
            </div>

            @if ($this->record->failed_rows > 0)
                <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">
                    {{ number_format($this->record->failed_rows) }} row(s) couldn't be imported and will be listed for review.
                </p>
            @endif

            @if ($done)
                <div class="mt-6">
                    <x-filament::button
                        tag="a"
                        :href="\App\Filament\Resources\ImportResource\Pages\ViewImport::getUrl(['record' => $this->record])"
                        icon="heroicon-o-arrow-right"
                    >
                        View import summary
                    </x-filament::button>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
