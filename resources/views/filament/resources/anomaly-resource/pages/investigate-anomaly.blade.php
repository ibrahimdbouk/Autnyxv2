<x-filament-panels::page>

    {{-- ── Header summary card ──────────────────────────────────────────── --}}
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-6 mb-6">
        <div class="flex flex-wrap items-start gap-4">
            {{-- Severity badge --}}
            <span @class([
                'inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide',
                'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'   => $this->record->severity === 'high',
                'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300' => $this->record->severity === 'medium',
                'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' => $this->record->severity === 'low',
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

        {{-- Status pill --}}
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
                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'       => $statusColor === 'gray',
                'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300' => $statusColor === 'purple',
                'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'   => $statusColor === 'blue',
                'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300' => $statusColor === 'yellow',
                'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' => $statusColor === 'green',
                'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'       => $statusColor === 'red',
            ])>
                <svg class="h-1.5 w-1.5 fill-current" viewBox="0 0 6 6"><circle cx="3" cy="3" r="3"/></svg>
                {{ $statusLabel }}
            </span>

            @if($this->record->ai_generated_at)
                <span class="ml-3 text-xs text-gray-400">
                    Analysis generated {{ $this->record->ai_generated_at->diffForHumans() }}
                </span>
            @endif
        </div>
    </div>

    {{-- ── Not yet investigated ─────────────────────────────────────────── --}}
    @if(!$this->record->isInvestigated())
        <div class="rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 p-12 text-center">
            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-violet-50 dark:bg-violet-900/20">
                <x-heroicon-o-cpu-chip class="h-8 w-8 text-violet-500" />
            </div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">No investigation yet</h3>
            <p class="mt-1 text-sm text-gray-500">Click "Run Investigation" above to let AI analyse this anomaly across all 7 dimensions.</p>
        </div>
    @else
        {{-- ── Vertical stepped investigation timeline ──────────────────── --}}
        <div class="relative">

            @php
                $steps = [
                    [
                        'number' => 1,
                        'label'  => 'What changed?',
                        'icon'   => 'heroicon-o-arrow-trending-up',
                        'color'  => 'blue',
                        'body'   => $this->record->ai_what,
                        'meta'   => null,
                    ],
                    [
                        'number' => 2,
                        'label'  => 'Why did it change?',
                        'icon'   => 'heroicon-o-magnifying-glass',
                        'color'  => 'violet',
                        'body'   => $this->record->ai_why,
                        'meta'   => [
                            'confidence'        => $this->record->getConfidenceLabel(),
                            'confidence_color'  => $this->record->getConfidenceColor(),
                        ],
                    ],
                    [
                        'number' => 3,
                        'label'  => 'How big is the problem?',
                        'icon'   => 'heroicon-o-scale',
                        'color'  => 'orange',
                        'body'   => $this->record->ai_how_big,
                        'meta'   => [
                            'trajectory' => ucfirst($this->record->ai_trajectory ?? 'unknown'),
                            'trajectory_color' => match($this->record->ai_trajectory) {
                                'widening'  => 'danger',
                                'stable'    => 'warning',
                                'narrowing' => 'success',
                                default     => 'gray',
                            },
                        ],
                    ],
                    [
                        'number' => 4,
                        'label'  => 'What should we do?',
                        'icon'   => 'heroicon-o-bolt',
                        'color'  => 'red',
                        'body'   => $this->record->ai_action,
                        'meta'   => [
                            'gate'       => $this->record->getGateLabel(),
                            'gate_color' => $this->record->getGateColor(),
                        ],
                    ],
                    [
                        'number' => 5,
                        'label'  => 'Is it a pattern or one-off?',
                        'icon'   => 'heroicon-o-arrow-path',
                        'color'  => 'teal',
                        'body'   => $this->record->ai_pattern,
                        'meta'   => [
                            'recurring'       => $this->record->ai_is_recurring ? 'Recurring' : 'One-off',
                            'recurring_color' => $this->record->ai_is_recurring ? 'warning' : 'success',
                        ],
                    ],
                    [
                        'number' => 6,
                        'label'  => 'What else might be connected?',
                        'icon'   => 'heroicon-o-link',
                        'color'  => 'indigo',
                        'body'   => $this->record->ai_related_summary ?? 'No connected anomalies found on the same SKU or store.',
                        'meta'   => null,
                        'related_count' => count($this->record->ai_related_anomaly_ids ?? []),
                    ],
                    [
                        'number' => 7,
                        'label'  => 'Did it work?',
                        'icon'   => 'heroicon-o-check-circle',
                        'color'  => 'green',
                        'body'   => $this->record->ai_outcome
                                    ?? ($this->record->action_taken_at
                                        ? 'Action was taken on ' . $this->record->action_taken_at->toDateString() . '. Outcome not yet recorded.'
                                        : 'No action taken yet.'),
                        'meta'   => null,
                        'action_notes' => $this->record->action_notes,
                    ],
                ];
            @endphp

            <div class="space-y-0">
                @foreach($steps as $i => $step)
                    @php $isLast = $i === count($steps) - 1; @endphp

                    <div class="relative flex gap-4">

                        {{-- Connector line + circle --}}
                        <div class="flex flex-col items-center">
                            <div @class([
                                'flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full text-white text-sm font-bold shadow-sm z-10',
                                'bg-blue-500'   => $step['color'] === 'blue',
                                'bg-violet-500' => $step['color'] === 'violet',
                                'bg-orange-500' => $step['color'] === 'orange',
                                'bg-red-500'    => $step['color'] === 'red',
                                'bg-teal-500'   => $step['color'] === 'teal',
                                'bg-indigo-500' => $step['color'] === 'indigo',
                                'bg-green-500'  => $step['color'] === 'green',
                            ])>
                                {{ $step['number'] }}
                            </div>
                            @if(!$isLast)
                                <div class="w-0.5 flex-1 bg-gray-200 dark:bg-gray-700 my-1"></div>
                            @endif
                        </div>

                        {{-- Card --}}
                        <div @class(['pb-6 flex-1', 'pb-0' => $isLast])>
                            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">

                                {{-- Step header --}}
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white uppercase tracking-wide">
                                        {{ $step['label'] }}
                                    </h3>

                                    {{-- Meta badges --}}
                                    @if(!empty($step['meta']))
                                        @if(isset($step['meta']['confidence']))
                                            @php
                                                $cc = $step['meta']['confidence_color'];
                                                $ccClasses = match($cc) {
                                                    'success' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                                                    'info'    => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                                                    'warning' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
                                                    default   => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                                                };
                                            @endphp
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $ccClasses }}">
                                                {{ $step['meta']['confidence'] }}
                                            </span>
                                        @endif

                                        @if(isset($step['meta']['trajectory']))
                                            @php
                                                $tc = $step['meta']['trajectory_color'];
                                                $tcClasses = match($tc) {
                                                    'danger'  => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                                    'warning' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
                                                    'success' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                                                    default   => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                                                };
                                            @endphp
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $tcClasses }}">
                                                ↗ {{ $step['meta']['trajectory'] }}
                                            </span>
                                        @endif

                                        @if(isset($step['meta']['gate']))
                                            @php
                                                $gc = $step['meta']['gate_color'];
                                                $gcClasses = match($gc) {
                                                    'danger'  => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                                    'warning' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
                                                    default   => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                                                };
                                            @endphp
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $gcClasses }}">
                                                {{ $step['meta']['gate'] }}
                                            </span>
                                        @endif

                                        @if(isset($step['meta']['recurring']))
                                            @php
                                                $rc = $step['meta']['recurring_color'];
                                                $rcClasses = match($rc) {
                                                    'warning' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
                                                    default   => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                                                };
                                            @endphp
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $rcClasses }}">
                                                {{ $step['meta']['recurring'] }}
                                            </span>
                                        @endif
                                    @endif
                                </div>

                                {{-- Body text --}}
                                <p class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                                    {{ $step['body'] ?? '—' }}
                                </p>

                                {{-- Q6: related count --}}
                                @if(isset($step['related_count']) && $step['related_count'] > 0)
                                    <p class="mt-2 text-xs text-gray-400">
                                        {{ $step['related_count'] }} related open anomaly/anomalies found on the same SKU/store.
                                    </p>
                                @endif

                                {{-- Q7: action notes --}}
                                @if(isset($step['action_notes']) && $step['action_notes'])
                                    <div class="mt-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 px-4 py-3">
                                        <p class="text-xs font-semibold text-green-700 dark:text-green-300 mb-1">Action taken:</p>
                                        <p class="text-sm text-green-800 dark:text-green-200">{{ $step['action_notes'] }}</p>
                                        @if($this->record->action_taken_at)
                                            <p class="mt-1 text-xs text-green-500">{{ $this->record->action_taken_at->toDateTimeString() }}</p>
                                        @endif
                                    </div>
                                @endif

                                {{-- Resolved / Unresolved note --}}
                                @if($step['number'] === 7 && $this->record->resolved_at)
                                    <div class="mt-3 rounded-lg {{ $this->record->investigation_status === 'resolved' ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800' }} border px-4 py-3">
                                        <p class="text-xs font-semibold {{ $this->record->investigation_status === 'resolved' ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300' }} mb-1">
                                            {{ $this->record->investigation_status === 'resolved' ? '✓ Resolved' : '✗ Unresolved' }}
                                            — {{ $this->record->resolved_at->toDateString() }}
                                        </p>
                                        @if($this->record->resolution_notes)
                                            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $this->record->resolution_notes }}</p>
                                        @endif
                                    </div>
                                @endif

                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── Raw context (collapsed) ──────────────────────────────────────── --}}
    @if($this->record->context)
        <div class="mt-6">
            <details class="group">
                <summary class="cursor-pointer text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 select-none">
                    ▶ Raw anomaly context
                </summary>
                <pre class="mt-2 rounded-lg bg-gray-50 dark:bg-gray-800 p-4 text-xs text-gray-600 dark:text-gray-400 overflow-x-auto">{{ json_encode($this->record->context, JSON_PRETTY_PRINT) }}</pre>
            </details>
        </div>
    @endif

</x-filament-panels::page>
