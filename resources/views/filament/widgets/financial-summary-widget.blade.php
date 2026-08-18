<x-filament-widgets::widget>
    @php $data = $this->getViewData(); @endphp

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">

        {{-- Total Revenue at Risk --}}
        <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 p-4 shadow-sm text-center">
            <p class="text-xs font-medium text-red-500 dark:text-red-400 uppercase tracking-wide">Revenue at Risk</p>
            <p class="mt-1 text-2xl font-bold text-red-700 dark:text-red-400">
                ${{ number_format($data['total_at_risk'], 0) }}
            </p>
            <p class="text-xs text-red-400 mt-1">AI-estimated across all outcomes</p>
        </div>

        {{-- Total Recovered --}}
        <div class="rounded-xl border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950/30 p-4 shadow-sm text-center">
            <p class="text-xs font-medium text-green-500 dark:text-green-400 uppercase tracking-wide">Observed Recovery</p>
            <p class="mt-1 text-2xl font-bold text-green-700 dark:text-green-400">
                ${{ number_format($data['total_recovered'], 0) }}
            </p>
            <p class="text-xs text-green-400 mt-1">Analyst-confirmed</p>
        </div>

        {{-- Recovery Rate --}}
        <div class="rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/30 p-4 shadow-sm text-center">
            <p class="text-xs font-medium text-blue-500 dark:text-blue-400 uppercase tracking-wide">Recovery Rate</p>
            <p class="mt-1 text-2xl font-bold text-blue-700 dark:text-blue-400">
                @if($data['recovery_rate'] !== null)
                    {{ $data['recovery_rate'] }}%
                @else
                    <span class="text-gray-400">—</span>
                @endif
            </p>
            <p class="text-xs text-blue-400 mt-1">Recovered / at risk</p>
        </div>

        {{-- False Positives --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm text-center">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">False Positives</p>
            <p class="mt-1 text-2xl font-bold text-gray-700 dark:text-gray-300">
                {{ $data['fp_count'] }}
            </p>
            <p class="text-xs text-gray-400 mt-1">Investigations closed as FP</p>
        </div>

    </div>
</x-filament-widgets::widget>
