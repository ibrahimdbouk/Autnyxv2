<x-filament-panels::page>

    {{-- ── Header strip ────────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-6">

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Status</p>
            @php
                $statusColor = match($record->status) {
                    'open'        => 'warning',
                    'in_progress' => 'info',
                    'resolved'    => 'success',
                    'closed'      => 'gray',
                    default       => 'gray',
                };
                $statusColors = ['warning'=>'bg-amber-100 text-amber-800','info'=>'bg-blue-100 text-blue-800','success'=>'bg-green-100 text-green-800','gray'=>'bg-gray-100 text-gray-700'];
            @endphp
            <span class="mt-1 inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-semibold {{ $statusColors[$statusColor] ?? $statusColors['gray'] }}">
                {{ ucwords(str_replace('_', ' ', $record->status)) }}
            </span>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Priority</p>
            @php
                $prioColor = match($record->priority) {
                    'critical' => 'bg-red-100 text-red-800',
                    'high'     => 'bg-amber-100 text-amber-800',
                    'medium'   => 'bg-blue-100 text-blue-800',
                    default    => 'bg-gray-100 text-gray-700',
                };
            @endphp
            <span class="mt-1 inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-semibold {{ $prioColor }}">
                {{ ucfirst($record->priority) }}
            </span>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Anomalies</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $record->anomaly_count }}</p>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Assigned To</p>
            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                {{ $record->assignedTeam?->name ?? $record->assignedUser?->name ?? 'Unassigned' }}
            </p>
        </div>

    </div>

    {{-- ── AI Narrative ─────────────────────────────────────────────────────────── --}}
    @if($record->ai_summary)
    <div class="rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-950/30 p-5 mb-6 shadow-sm">
        <div class="flex items-center gap-2 mb-3">
            <x-heroicon-o-sparkles class="w-5 h-5 text-indigo-500" />
            <h3 class="text-sm font-semibold text-indigo-700 dark:text-indigo-300 uppercase tracking-wide">AI Narrative</h3>
            @if($record->ai_confidence)
                @php
                    $confColor = match($record->ai_confidence) {
                        'established' => 'bg-green-100 text-green-800',
                        'probable'    => 'bg-blue-100 text-blue-800',
                        'suspected'   => 'bg-amber-100 text-amber-800',
                        default       => 'bg-gray-100 text-gray-700',
                    };
                @endphp
                <span class="ml-auto inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $confColor }}">
                    {{ ucfirst($record->ai_confidence) }} confidence
                </span>
            @endif
        </div>

        <p class="text-sm text-gray-800 dark:text-gray-200 mb-3">{{ $record->ai_summary }}</p>

        @if($record->ai_root_cause)
        <div class="mt-3 border-t border-indigo-200 dark:border-indigo-700 pt-3">
            <p class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 uppercase tracking-wide mb-1">Root Cause</p>
            <p class="text-sm text-gray-800 dark:text-gray-200">{{ $record->ai_root_cause }}</p>
        </div>
        @endif

        @if($record->ai_recommended_action)
        <div class="mt-3 border-t border-indigo-200 dark:border-indigo-700 pt-3">
            <p class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 uppercase tracking-wide mb-1">Recommended Action</p>
            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $record->ai_recommended_action }}</p>
        </div>
        @endif

        @if($record->revenue_at_risk)
        <div class="mt-3 border-t border-indigo-200 dark:border-indigo-700 pt-3">
            <p class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 uppercase tracking-wide mb-1">Estimated Revenue at Risk</p>
            <p class="text-lg font-bold text-red-600 dark:text-red-400">${{ number_format($record->revenue_at_risk, 2) }}</p>
        </div>
        @endif

        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">Generated {{ $record->ai_generated_at?->diffForHumans() }}</p>
    </div>
    @else
    <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-600 p-5 mb-6 text-center">
        <x-heroicon-o-sparkles class="mx-auto w-8 h-8 text-gray-300 dark:text-gray-600 mb-2" />
        <p class="text-sm text-gray-500 dark:text-gray-400">No AI narrative yet. Click <strong>Generate Narrative</strong> above.</p>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- ── Left: Anomalies + Evidence ──────────────────────────────────────── --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Linked Anomalies --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                    <x-heroicon-o-exclamation-triangle class="w-4 h-4 text-amber-500" />
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Linked Anomalies ({{ $record->anomalies->count() }})</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($record->anomalies as $anomaly)
                    <div class="px-5 py-3 flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                {{ $anomaly->getRuleLabel() }}
                                @if($anomaly->sku) <span class="text-gray-500 dark:text-gray-400 font-normal">· {{ $anomaly->sku }}</span>@endif
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 line-clamp-1">{{ $anomaly->description }}</p>
                        </div>
                        @php $sevColors = ['high'=>'bg-red-100 text-red-700','medium'=>'bg-amber-100 text-amber-700','low'=>'bg-gray-100 text-gray-600']; @endphp
                        <span class="shrink-0 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $sevColors[$anomaly->severity] ?? $sevColors['low'] }}">
                            {{ ucfirst($anomaly->severity) }}
                        </span>
                    </div>
                    @empty
                    <p class="px-5 py-3 text-sm text-gray-400 dark:text-gray-500">No anomalies linked.</p>
                    @endforelse
                </div>
            </div>

            {{-- Evidence --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                    <x-heroicon-o-beaker class="w-4 h-4 text-blue-500" />
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Evidence Package ({{ $record->evidence->count() }} items)</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($record->evidence->sortBy('direction') as $ev)
                    <div class="px-5 py-2.5 flex items-center justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium text-gray-800 dark:text-gray-200">{{ $ev->label }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $ev->source }}</p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $ev->getFormattedValue() }}</p>
                            @php
                                $dirColor = match($ev->direction) {
                                    'supports'    => 'text-red-600 dark:text-red-400',
                                    'contradicts' => 'text-green-600 dark:text-green-400',
                                    default       => 'text-gray-400',
                                };
                            @endphp
                            <p class="text-xs {{ $dirColor }}">{{ ucfirst($ev->direction) }}</p>
                        </div>
                    </div>
                    @empty
                    <p class="px-5 py-3 text-sm text-gray-400 dark:text-gray-500">No evidence collected yet.</p>
                    @endforelse
                </div>
            </div>

        </div>

        {{-- ── Right: Actions + Audit Trail ─────────────────────────────────────── --}}
        <div class="space-y-6">

            {{-- Actions --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                    <x-heroicon-o-clipboard-document-check class="w-4 h-4 text-green-500" />
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Actions ({{ $record->actions->count() }})</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($record->actions as $action)
                    <div class="px-5 py-3">
                        <div class="flex items-center justify-between mb-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $action->title }}</p>
                            @php
                                $actColor = match($action->status) {
                                    'completed'   => 'bg-green-100 text-green-700',
                                    'in_progress' => 'bg-blue-100 text-blue-700',
                                    'cancelled'   => 'bg-gray-100 text-gray-500',
                                    default       => 'bg-amber-100 text-amber-700',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $actColor }}">
                                {{ ucwords(str_replace('_', ' ', $action->status)) }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $action->getTypeLabel() }}</p>
                        @if($action->assignedTo)
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">→ {{ $action->assignedTo->name }}</p>
                        @endif
                    </div>
                    @empty
                    <p class="px-5 py-3 text-sm text-gray-400 dark:text-gray-500">No actions yet. Use <strong>Add Action</strong> above.</p>
                    @endforelse
                </div>
            </div>

            {{-- Audit Trail --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                    <x-heroicon-o-clock class="w-4 h-4 text-gray-400" />
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Activity</h3>
                </div>
                <div class="px-5 py-3 space-y-3 max-h-80 overflow-y-auto">
                    @forelse($record->auditLogs->sortByDesc('created_at')->take(20) as $log)
                    <div class="flex gap-3">
                        <div class="shrink-0 mt-0.5 w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-600 mt-1.5"></div>
                        <div class="min-w-0">
                            <p class="text-xs text-gray-800 dark:text-gray-200">{{ $log->description }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">
                                {{ $log->getActorLabel() }} · {{ $log->created_at?->diffForHumans() }}
                            </p>
                        </div>
                    </div>
                    @empty
                    <p class="text-sm text-gray-400 dark:text-gray-500">No activity yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Resolution Notes (if resolved) --}}
            @if($record->resolution_notes)
            <div class="rounded-xl border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950/30 p-4 shadow-sm">
                <p class="text-xs font-semibold text-green-700 dark:text-green-400 uppercase tracking-wide mb-1">Resolution</p>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ $record->resolution_notes }}</p>
                @if($record->resolved_at)
                <p class="text-xs text-gray-400 mt-1">Resolved {{ $record->resolved_at->diffForHumans() }}</p>
                @endif
            </div>
            @endif

            {{-- Financial Outcome --}}
            @if($record->outcome)
            @php $outcome = $record->outcome; @endphp
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                    <x-heroicon-o-currency-dollar class="w-4 h-4 text-green-500" />
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Financial Outcome</h3>
                    @php
                        $otColors = [
                            'resolved'          => 'bg-green-100 text-green-700',
                            'false_positive'    => 'bg-gray-100 text-gray-600',
                            'duplicate'         => 'bg-gray-100 text-gray-600',
                            'escalated_to_ops'  => 'bg-amber-100 text-amber-700',
                            'no_action_needed'  => 'bg-blue-100 text-blue-700',
                        ];
                    @endphp
                    <span class="ml-auto inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $otColors[$outcome->outcome_type] ?? 'bg-gray-100 text-gray-600' }}">
                        {{ $outcome->getOutcomeTypeLabel() }}
                    </span>
                </div>
                <div class="px-5 py-4 space-y-3">
                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">At Risk</p>
                            <p class="text-lg font-bold text-red-600 dark:text-red-400">
                                @if($outcome->revenue_at_risk) ${{ number_format($outcome->revenue_at_risk, 2) }} @else <span class="text-gray-400">—</span> @endif
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Recovered</p>
                            <p class="text-lg font-bold text-green-600 dark:text-green-400">
                                @if($outcome->observed_recovery) ${{ number_format($outcome->observed_recovery, 2) }} @else <span class="text-gray-400">—</span> @endif
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Recovery Rate</p>
                            <p class="text-lg font-bold text-blue-600 dark:text-blue-400">
                                @if($outcome->getRecoveryRate() !== null) {{ $outcome->getRecoveryRate() }}% @else <span class="text-gray-400">—</span> @endif
                            </p>
                        </div>
                    </div>

                    @if($outcome->confirmed_root_cause)
                    <div class="border-t border-gray-100 dark:border-gray-700 pt-3">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Confirmed Root Cause</p>
                        <p class="text-xs text-gray-800 dark:text-gray-200">{{ $outcome->confirmed_root_cause }}</p>
                        @if($outcome->ai_root_cause_correct !== null)
                        <p class="text-xs mt-1 {{ $outcome->ai_root_cause_correct ? 'text-green-600' : 'text-amber-600' }}">
                            AI root cause: {{ $outcome->ai_root_cause_correct ? '✓ Correct' : '✗ Incorrect' }}
                        </p>
                        @endif
                    </div>
                    @endif

                    @if($outcome->recovery_method)
                    <p class="text-xs text-gray-400">Method: {{ $outcome->getRecoveryMethodLabel() }}</p>
                    @endif

                    <p class="text-xs text-gray-400">Recorded by {{ $outcome->recordedBy?->name ?? 'System' }} · {{ $outcome->recorded_at?->diffForHumans() }}</p>
                </div>
            </div>
            @endif

        </div>
    </div>

</x-filament-panels::page>
