<x-filament-panels::page>
    {{-- Summary stats --}}
    <x-filament::section>
        <x-slot name="heading">{{ $this->record->original_filename }}</x-slot>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Status</div>
                <div class="font-semibold">{{ ucwords(str_replace('_', ' ', $this->record->status)) }}</div>
            </div>
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Total Rows</div>
                <div class="font-semibold">{{ number_format($this->record->total_rows) }}</div>
            </div>
            <div class="rounded-lg bg-green-50 dark:bg-green-900/20 p-3">
                <div class="text-xs font-medium text-green-600 dark:text-green-400 uppercase mb-1">Imported</div>
                <div class="font-semibold text-green-700 dark:text-green-300">{{ number_format($this->record->imported_rows) }}</div>
            </div>
            <div class="rounded-lg {{ $this->record->failed_rows > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-gray-50 dark:bg-gray-800' }} p-3">
                <div class="text-xs font-medium {{ $this->record->failed_rows > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }} uppercase mb-1">Failed</div>
                <div class="font-semibold {{ $this->record->failed_rows > 0 ? 'text-red-700 dark:text-red-300' : '' }}">{{ number_format($this->record->failed_rows) }}</div>
            </div>
        </div>

        @if($this->record->error_message)
            <div class="mt-4 rounded-md bg-red-50 dark:bg-red-900/20 p-3 text-sm text-red-700 dark:text-red-300">
                <strong>Error:</strong> {{ $this->record->error_message }}
            </div>
        @endif
    </x-filament::section>

    {{-- Failed rows review --}}
    @if($this->record->failed_rows > 0)
        <x-filament::section>
            <x-slot name="heading">Failed Rows — Pending Review</x-slot>
            <x-slot name="description">Review each failed row and approve or reject it.</x-slot>

            {{-- Filter tabs --}}
            <div class="flex gap-2 mb-4">
                @foreach(['pending_review' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label)
                    <button
                        wire:click="$set('statusFilter', '{{ $value }}')"
                        class="px-3 py-1 rounded-full text-xs font-medium transition-colors
                            {{ $statusFilter === $value
                                ? 'bg-primary-600 text-white'
                                : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if($this->failedRows->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No rows in this status.</p>
            @else
                <div class="space-y-3">
                    @foreach($this->failedRows as $row)
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Row {{ $row->row_number }}</span>
                                        <span class="rounded-full bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 px-2 py-0.5 text-xs">
                                            {{ $row->error_message }}
                                        </span>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($row->raw_data as $key => $value)
                                            <div class="text-xs">
                                                <span class="text-gray-400 dark:text-gray-500">{{ $key }}:</span>
                                                <span class="font-medium">{{ $value ?: '—' }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                @if($row->status === 'pending_review')
                                    <div class="flex gap-2 shrink-0">
                                        <x-filament::button
                                            size="sm"
                                            color="success"
                                            wire:click="approveRow({{ $row->id }})"
                                        >
                                            Approve
                                        </x-filament::button>
                                        <x-filament::button
                                            size="sm"
                                            color="danger"
                                            outlined
                                            wire:click="rejectRow({{ $row->id }})"
                                        >
                                            Reject
                                        </x-filament::button>
                                    </div>
                                @else
                                    <span class="text-xs font-medium {{ $row->status === 'approved' ? 'text-green-600' : 'text-red-500' }} shrink-0">
                                        {{ ucfirst($row->status) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">
                    {{ $this->failedRows->links() }}
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
