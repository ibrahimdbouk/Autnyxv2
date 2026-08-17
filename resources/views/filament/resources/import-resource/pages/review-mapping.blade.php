<x-filament-panels::page>
    {{-- Header info --}}
    <x-filament::section>
        <x-slot name="heading">Column Mapping — {{ $this->record->original_filename }}</x-slot>
        <x-slot name="description">
            Review how your file's columns were mapped to our system. Adjust any incorrect mappings, then confirm to import.
        </x-slot>

        <div class="grid grid-cols-3 gap-4 text-sm mb-4">
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Data Type</div>
                <div class="font-semibold">{{ \App\Models\Import::dataTypeLabels()[$this->record->data_type] ?? $this->record->data_type }}</div>
            </div>
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Total Rows</div>
                <div class="font-semibold">{{ number_format($this->record->total_rows) }}</div>
            </div>
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Columns Found</div>
                <div class="font-semibold">{{ count($this->mappings) }}</div>
            </div>
        </div>
    </x-filament::section>

    {{-- Mapping table --}}
    <x-filament::section>
        <x-slot name="heading">Column Mappings</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Your Column</th>
                        <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Sample Values</th>
                        <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Maps To</th>
                        <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">Confidence</th>
                        <th class="text-left py-2 px-3 font-medium text-gray-600 dark:text-gray-400">AI Reasoning</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($this->mappings as $index => $mapping)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            {{-- Source header --}}
                            <td class="py-2 px-3 font-mono text-xs font-medium">
                                {{ $mapping['source_header'] }}
                            </td>

                            {{-- Sample values --}}
                            <td class="py-2 px-3 text-xs text-gray-500 dark:text-gray-400">
                                @php
                                    $samples = collect($this->sampleRows)
                                        ->pluck($mapping['source_header'])
                                        ->filter()
                                        ->take(3)
                                        ->unique()
                                        ->values();
                                @endphp
                                {{ $samples->implode(', ') ?: '—' }}
                            </td>

                            {{-- Target field dropdown --}}
                            <td class="py-2 px-3">
                                <select
                                    wire:model="mappings.{{ $index }}.target_field"
                                    class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500 w-full"
                                >
                                    @foreach($this->targetFieldOptions as $value => $label)
                                        <option value="{{ $value }}" @selected($mapping['target_field'] === $value)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>

                            {{-- Confidence badge --}}
                            <td class="py-2 px-3">
                                @php
                                    $pct = round($mapping['confidence'] * 100);
                                    $color = match(true) {
                                        $pct >= 85 => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                        $pct >= 50 => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                        default    => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $color }}">
                                    {{ $pct }}%
                                </span>
                            </td>

                            {{-- AI reasoning --}}
                            <td class="py-2 px-3 text-xs text-gray-400 dark:text-gray-500 italic">
                                {{ $mapping['reasoning'] ?: '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end mt-6 gap-3">
            <x-filament::button
                color="gray"
                tag="a"
                :href="$this->getHeaderActions()[0]->getUrl()"
            >
                Cancel
            </x-filament::button>

            <x-filament::button wire:click="confirmAndImport" wire:loading.attr="disabled">
                <span wire:loading.remove>Confirm & Import</span>
                <span wire:loading>Importing…</span>
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
