<x-filament-panels::page>

    {{-- ── Header summary card ──────────────────────────────────────────── --}}
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-6 mb-6">
        <div class="flex flex-wrap items-start gap-4">
            {{-- Severity badge --}}
            <span @class([
                'inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide',
                'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'              => $this->record->severity === 'high',
                'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300'  => $this->record->severity === 'medium',
                'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'          => $this->record->severity === 'low',
            ])>
                {{ ucfirst($this->record->severity) }}
            </span>

            {{-- Rule badge --}}
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                {{ $this->record->getRuleLabel() }}
            </span>

            @if($this->record->sku)
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">
                    SKU: {{ $this->record->sku }}
                </span>
            @endif

            @if($this->record->store_id)
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                    Store {{ $this->record->store_id }}
                </span>
            @endif

            <span class="ml-auto text-sm text-gray-400">
                Detected {{ $this->record->detected_at?->diffForHumans() }}
            </span>
        </div>

        <p class="mt-3 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
            {{ $this->record->description }}
        </p>

        {{-- Anomaly status badge (used for dismiss/investigation status tracking) --}}
        @if($this->record->investigation_status && $this->record->investigation_status !== 'not_started')
        <div class="mt-4">
            @php
                $statusLabel = match($this->record->investigation_status) {
                    'investigating'     => 'Investigating',
                    'cause_established' => 'Cause Established',
                    'action_taken'      => 'Action Taken',
                    'resolved'          => 'Resolved',
                    'unresolved'        => 'Unresolved',
                    default             => 'Detected',
                };
                $statusColor = match($this->record->investigation_status) {
                    'cause_established' => 'blue',
                    'action_taken'      => 'yellow',
                    'resolved'          => 'green',
                    'unresolved'        => 'red',
                    'investigating'     => 'purple',
                    default             => 'gray',
                };
            @endphp
            <span @class([
                'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold',
                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'             => $statusColor === 'gray',
                'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300'  => $statusColor === 'purple',
                'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'          => $statusColor === 'blue',
                'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300'  => $statusColor === 'yellow',
                'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'      => $statusColor === 'green',
                'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'              => $statusColor === 'red',
            ])>
                <svg class="h-1.5 w-1.5 fill-current" viewBox="0 0 6 6"><circle cx="3" cy="3" r="3"/></svg>
                {{ $statusLabel }}
            </span>
        </div>
        @endif
    </div>

    {{-- ── Investigation link ────────────────────────────────────────────── --}}
    @if($this->record->investigation)
        @php $inv = $this->record->investigation; @endphp
        <div class="rounded-xl border border-violet-200 dark:border-violet-700/60 bg-violet-50 dark:bg-violet-900/20 shadow-sm p-6 mb-6">
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold text-violet-500 dark:text-violet-400 uppercase tracking-wide mb-1">
                        Linked Investigation
                    </p>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white truncate">
                        {{ $inv->title }}
                    </h3>

                    <div class="flex flex-wrap gap-2 mt-3">
                        {{-- Status --}}
                        @php
                            $invStatusColor = match($inv->status) {
                                'open'        => 'yellow',
                                'in_progress' => 'blue',
                                'resolved'    => 'green',
                                'closed'      => 'gray',
                                default       => 'gray',
                            };
                        @endphp
                        <span @class([
                            'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                            'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300' => $invStatusColor === 'yellow',
                            'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'         => $invStatusColor === 'blue',
                            'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'     => $invStatusColor === 'green',
                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'            => $invStatusColor === 'gray',
                        ])>
                            {{ ucwords(str_replace('_', ' ', $inv->status)) }}
                        </span>

                        {{-- Priority --}}
                        @php
                            $invPriColor = match($inv->priority) {
                                'critical' => 'red',
                                'high'     => 'orange',
                                'medium'   => 'blue',
                                default    => 'gray',
                            };
                        @endphp
                        <span @class([
                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
                            'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'             => $invPriColor === 'red',
                            'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300' => $invPriColor === 'orange',
                            'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'         => $invPriColor === 'blue',
                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'            => $invPriColor === 'gray',
                        ])>
                            {{ ucfirst($inv->priority) }} Priority
                        </span>

                        {{-- AI confidence --}}
                        @if($inv->ai_confidence)
                            @php
                                $confColor = match($inv->ai_confidence) {
                                    'established' => 'green',
                                    'probable'    => 'blue',
                                    'suspected'   => 'yellow',
                                    default       => 'gray',
                                };
                            @endphp
                            <span @class([
                                'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'   => $confColor === 'green',
                                'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'       => $confColor === 'blue',
                                'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300' => $confColor === 'yellow',
                                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'          => $confColor === 'gray',
                            ])>
                                {{ ucfirst($inv->ai_confidence) }} confidence
                            </span>
                        @endif

                        {{-- Team --}}
                        @if($inv->assignedTeam)
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                {{ $inv->assignedTeam->name }}
                            </span>
                        @endif
                    </div>

                    {{-- AI summary snippet if available --}}
                    @if($inv->ai_summary)
                        <p class="mt-3 text-sm text-gray-700 dark:text-gray-300 leading-relaxed line-clamp-3">
                            {{ $inv->ai_summary }}
                        </p>
                        <p class="mt-1 text-xs text-gray-400">
                            Narrative generated {{ $inv->ai_generated_at?->diffForHumans() }}
                        </p>
                    @else
                        <p class="mt-3 text-xs text-gray-400 italic">
                            AI narrative not yet generated — it will be produced during the nightly pipeline at 03:30 AM, or click "Generate Narrative" on the investigation page.
                        </p>
                    @endif
                </div>
            </div>

            {{-- Anomaly count in investigation --}}
            @if($inv->anomaly_count > 1)
                <div class="mt-4 pt-4 border-t border-violet-200 dark:border-violet-700/60 text-xs text-violet-600 dark:text-violet-400">
                    This investigation groups {{ $inv->anomaly_count }} correlated anomalies. Click "View Investigation" above to see the full picture, all evidence, and recommended actions.
                </div>
            @endif
        </div>

    @else
        {{-- No investigation correlated yet --}}
        <div class="rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 p-10 text-center mb-6">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-gray-50 dark:bg-gray-800">
                <x-heroicon-o-clock class="h-7 w-7 text-gray-400" />
            </div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Pending investigation correlation</h3>
            <p class="mt-1 text-sm text-gray-500 max-w-md mx-auto">
                This anomaly has not yet been correlated into an investigation. Investigation correlation runs nightly at 02:00 AM alongside anomaly detection.
            </p>
            <p class="mt-3 text-xs text-gray-400">
                Once correlated, an Investigation object will be created grouping this anomaly with related signals on the same SKU or store. You can then view the full AI-powered analysis, evidence package, and actions from the Investigation page.
            </p>
        </div>
    @endif

    {{-- ── Raw detection context (collapsed) ──────────────────────────── --}}
    @if($this->record->context)
        <div class="mt-2">
            <details class="group">
                <summary class="cursor-pointer text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 select-none">
                    ▶ Raw detection context
                </summary>
                <pre class="mt-2 rounded-lg bg-gray-50 dark:bg-gray-800 p-4 text-xs text-gray-600 dark:text-gray-400 overflow-x-auto">{{ json_encode($this->record->context, JSON_PRETTY_PRINT) }}</pre>
            </details>
        </div>
    @endif

</x-filament-panels::page>
