<x-filament-widgets::widget>
    @php $data = $this->getViewData(); @endphp

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">

        {{-- Open --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm text-center">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Open</p>
            <p class="mt-1 text-3xl font-bold text-amber-600 dark:text-amber-400">{{ $data['open'] }}</p>
        </div>

        {{-- In Progress --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm text-center">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">In Progress</p>
            <p class="mt-1 text-3xl font-bold text-blue-600 dark:text-blue-400">{{ $data['inProgress'] }}</p>
        </div>

        {{-- Critical --}}
        <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 p-4 shadow-sm text-center">
            <p class="text-xs font-medium text-red-500 dark:text-red-400 uppercase tracking-wide">Critical</p>
            <p class="mt-1 text-3xl font-bold text-red-700 dark:text-red-400">{{ $data['critical'] }}</p>
        </div>

        {{-- High --}}
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-4 shadow-sm text-center">
            <p class="text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wide">High Priority</p>
            <p class="mt-1 text-3xl font-bold text-amber-700 dark:text-amber-400">{{ $data['high'] }}</p>
        </div>

        {{-- Resolved (7 days) --}}
        <div class="rounded-xl border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950/30 p-4 shadow-sm text-center">
            <p class="text-xs font-medium text-green-600 dark:text-green-400 uppercase tracking-wide">Resolved (7d)</p>
            <p class="mt-1 text-3xl font-bold text-green-700 dark:text-green-400">{{ $data['recentlyResolved'] }}</p>
        </div>

        {{-- Avg Age --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm text-center">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Avg Age</p>
            @if($data['avgOpenHours'] !== null)
                @if($data['avgOpenHours'] >= 24)
                    <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ round($data['avgOpenHours'] / 24, 1) }}<span class="text-sm font-normal text-gray-400 ml-1">d</span></p>
                @else
                    <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $data['avgOpenHours'] }}<span class="text-sm font-normal text-gray-400 ml-1">h</span></p>
                @endif
            @else
                <p class="mt-1 text-2xl font-bold text-gray-400">—</p>
            @endif
        </div>

    </div>
</x-filament-widgets::widget>
