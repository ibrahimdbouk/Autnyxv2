<?php

namespace App\Filament\Resources\InvestigationResource\Pages;

use App\Filament\Concerns\ResolvesRecordKey;
use App\Filament\Resources\InvestigationResource;
use App\Models\Action as InvestigationAction;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\InvestigationWatch;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Collaboration\CommentService;
use App\Services\DataHealth\DataHealthService;
use App\Services\InvestigationNarratorService;
use App\Services\Noise\SnoozeService;
use App\Services\OutcomeService;
use App\Services\Watch\WatchService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class InvestigateInvestigation extends Page
{
    use ResolvesRecordKey;

    protected static string $resource = InvestigationResource::class;

    protected string $view = 'filament.resources.investigation-resource.pages.investigate-investigation';

    public Investigation $record;

    public function mount(int|string $record): void
    {
        // Filament v5 / Livewire may pass the serialised model JSON as the
        // route parameter in some binding contexts — extract the raw key.
        $record = $this->resolveRecordKey($record);

        $this->record = Investigation::with([
            'anomalies',
            'entities',
            'evidence',
            'actions.assignedTo',
            'actions.assignedTeam',
            'auditLogs.user',
            'assignedTeam',
            'assignedUser',
            'escalationEvents.escalationRule',
            'outcome.recordedBy',
            'comments' => fn ($q) => $q->with('user')->orderBy('created_at'),
            'watches.user',
            'watches.team',
            'snoozedByUser',
        ])->findOrFail($record);

        abort_unless(
            $this->record->tenant_id === Filament::getTenant()?->id,
            403
        );
    }

    /** Fields to eager-load when refreshing the record after an action. */
    protected function refreshRelations(): array
    {
        return [
            'anomalies', 'evidence', 'actions.assignedTo', 'actions.assignedTeam',
            'auditLogs.user', 'assignedTeam', 'assignedUser', 'outcome.recordedBy',
            'comments' => fn ($q) => $q->with('user')->orderBy('created_at'),
            'watches.user', 'watches.team', 'snoozedByUser',
        ];
    }

    protected function reloadRecord(): void
    {
        $this->record = $this->record->fresh($this->refreshRelations());
    }

    // ── Feature 5/6/10 helpers ─────────────────────────────────────────────────

    public function isWatchedByMe(): bool
    {
        return app(WatchService::class)->isWatchedByUser($this->record, auth()->id());
    }

    /** @return array<int,string> */
    public function dataHealthCaveats(): array
    {
        try {
            return app(DataHealthService::class)->evidenceCaveats($this->record);
        } catch (\Throwable) {
            return [];
        }
    }

    /** B8: deterministic root-cause inference for this investigation (memoised per request). */
    private ?array $causalCache = null;

    public function causalInference(): ?array
    {
        if ($this->causalCache !== null) return $this->causalCache ?: null;

        try {
            $r = app(\App\Services\Anomaly\RootCauseAnalysisService::class)->analyze($this->record);
            return ($this->causalCache = $r ?? []) ?: null;
        } catch (\Throwable) {
            return ($this->causalCache = []) ?: null;
        }
    }

    /** B4s2/B5: replenishment recommendations for this investigation's stockouts (memoised per request). */
    private ?array $recCache = null;

    public function recommendedActions(): array
    {
        if ($this->recCache !== null) return $this->recCache;

        try {
            return $this->recCache = app(\App\Services\Anomaly\ReplenishmentRecommendationService::class)
                ->forInvestigation($this->record);
        } catch (\Throwable) {
            return $this->recCache = [];
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // Generate / refresh AI narrative
            Action::make('narrate')
                ->label($this->record->ai_generated_at ? 'Refresh Narrative' : 'Generate Narrative')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->requiresConfirmation(fn () => (bool) $this->record->ai_generated_at)
                ->modalHeading('Refresh AI Narrative?')
                ->modalDescription('This will regenerate the AI narrative using the latest evidence.')
                ->action(function () {
                    app(InvestigationNarratorService::class)->narrate($this->record, force: true);
                    $this->record = $this->record->fresh([
                        'anomalies', 'evidence', 'actions.assignedTo',
                        'actions.assignedTeam', 'auditLogs.user',
                        'assignedTeam', 'assignedUser',
                    ]);
                    Notification::make()->title('Narrative generated')->success()->send();
                }),

            // Add Action
            Action::make('add_action')
                ->label('Add Action')
                ->icon('heroicon-o-plus-circle')
                ->color('gray')
                ->form([
                    Select::make('action_type')
                        ->label('Action Type')
                        ->options(InvestigationAction::TYPE_LABELS)
                        ->required(),
                    TextInput::make('title')
                        ->label('Title')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('Description')
                        ->rows(3),
                    Select::make('status')
                        ->options([
                            InvestigationAction::STATUS_PENDING     => 'Pending',
                            InvestigationAction::STATUS_IN_PROGRESS => 'In Progress',
                            InvestigationAction::STATUS_COMPLETED   => 'Completed',
                        ])
                        ->default(InvestigationAction::STATUS_PENDING)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $action = $this->record->actions()->create([
                        'action_type' => $data['action_type'],
                        'title'       => $data['title'],
                        'description' => $data['description'] ?? null,
                        'status'      => $data['status'],
                        'created_by'  => auth()->id(),
                    ]);

                    AuditLogger::actionCreated(
                        $this->record,
                        $action->id,
                        $action->title,
                        auth()->id()
                    );

                    $this->record = $this->record->fresh([
                        'anomalies', 'evidence', 'actions.assignedTo',
                        'actions.assignedTeam', 'auditLogs.user',
                        'assignedTeam', 'assignedUser',
                    ]);

                    Notification::make()->title('Action added')->success()->send();
                }),

            // Add Recommended Action (B4s2/B5) — adopt a replenishment recommendation
            // as a tracked internal action. Autnyx recommends only; nothing is sent
            // to the ERP.
            Action::make('add_recommended_action')
                ->label('Add Recommended Action')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->visible(fn () => ! empty($this->recommendedActions()))
                ->form([
                    Select::make('rec')
                        ->label('Recommendation')
                        ->options(fn () => collect($this->recommendedActions())
                            ->mapWithKeys(fn ($r, $i) => [$i => $r['label']])->all())
                        ->required()
                        ->helperText('Adopting logs a tracked action for your team to carry out in your own ERP — Autnyx sends nothing.'),
                ])
                ->action(function (array $data) {
                    $recs = $this->recommendedActions();
                    $rec  = $recs[$data['rec']] ?? null;
                    if ($rec === null) {
                        Notification::make()->title('Recommendation no longer available')->warning()->send();
                        return;
                    }

                    $action = app(\App\Services\Anomaly\ReplenishmentRecommendationService::class)
                        ->adopt($this->record, $rec, auth()->id());

                    AuditLogger::actionCreated($this->record, $action->id, $action->title, auth()->id());

                    $this->recCache = null;
                    $this->reloadRecord();

                    Notification::make()->title('Recommended action added')->success()->send();
                }),

            // Mark In Progress
            Action::make('mark_in_progress')
                ->label('Mark In Progress')
                ->icon('heroicon-o-play')
                ->color('info')
                ->visible(fn () => $this->record->status === Investigation::STATUS_OPEN)
                ->action(function () {
                    $old = $this->record->status;
                    $this->record->update([
                        'status'      => Investigation::STATUS_IN_PROGRESS,
                        'assigned_at' => $this->record->assigned_at ?? now(),
                    ]);
                    AuditLogger::statusChanged($this->record, $old, Investigation::STATUS_IN_PROGRESS);
                    $this->record = $this->record->fresh(['anomalies', 'evidence', 'actions.assignedTo', 'actions.assignedTeam', 'auditLogs.user', 'assignedTeam', 'assignedUser']);
                    Notification::make()->title('Marked in progress')->success()->send();
                }),

            // Mark Resolved
            Action::make('mark_resolved')
                ->label('Mark Resolved')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => in_array($this->record->status, [
                    Investigation::STATUS_OPEN,
                    Investigation::STATUS_IN_PROGRESS,
                ]))
                ->form([
                    Textarea::make('resolution_notes')
                        ->label('Resolution Notes')
                        ->required()
                        ->rows(4)
                        ->placeholder('Describe what was done to resolve this investigation…'),
                ])
                ->action(function (array $data) {
                    $old = $this->record->status;
                    $this->record->update([
                        'status'           => Investigation::STATUS_RESOLVED,
                        'resolution_notes' => $data['resolution_notes'],
                        'resolved_at'      => now(),
                    ]);
                    AuditLogger::statusChanged($this->record, $old, Investigation::STATUS_RESOLVED);
                    $this->record = $this->record->fresh(['anomalies', 'evidence', 'actions.assignedTo', 'actions.assignedTeam', 'auditLogs.user', 'assignedTeam', 'assignedUser']);
                    Notification::make()->title('Investigation resolved')->success()->send();
                }),

            // Close
            Action::make('close')
                ->label('Close')
                ->icon('heroicon-o-x-circle')
                ->color('gray')
                ->visible(fn () => $this->record->status === Investigation::STATUS_RESOLVED)
                ->requiresConfirmation()
                ->modalHeading('Close Investigation?')
                ->modalDescription('Closing marks this as permanently done. You can still view it.')
                ->action(function () {
                    $old = $this->record->status;
                    $this->record->update([
                        'status'    => Investigation::STATUS_CLOSED,
                        'closed_at' => now(),
                    ]);
                    AuditLogger::statusChanged($this->record, $old, Investigation::STATUS_CLOSED);
                    $this->record = $this->record->fresh(['anomalies', 'evidence', 'actions.assignedTo', 'actions.assignedTeam', 'auditLogs.user', 'assignedTeam', 'assignedUser']);
                    Notification::make()->title('Investigation closed')->success()->send();
                }),

            // Record / update outcome
            Action::make('record_outcome')
                ->label(fn () => $this->record->outcome ? 'Update Outcome' : 'Record Outcome')
                ->icon('heroicon-o-currency-dollar')
                ->color('success')
                ->visible(fn () => in_array($this->record->status, [
                    Investigation::STATUS_RESOLVED,
                    Investigation::STATUS_CLOSED,
                ]))
                ->fillForm(fn () => $this->record->outcome ? $this->record->outcome->toArray() : [
                    'outcome_type'    => InvestigationOutcome::TYPE_RESOLVED,
                    'revenue_at_risk' => $this->record->revenue_at_risk,
                ])
                ->form([
                    Select::make('outcome_type')
                        ->label('Outcome Type')
                        ->options(InvestigationOutcome::TYPE_LABELS)
                        ->default(InvestigationOutcome::TYPE_RESOLVED)
                        ->required(),

                    TextInput::make('revenue_at_risk')
                        ->label('Revenue at Risk')
                        ->numeric()
                        ->prefix(\App\Support\Money::symbol(Filament::getTenant()?->currencyCode()))
                        ->helperText('AI estimate — adjust if needed'),

                    TextInput::make('observed_recovery')
                        ->label('Observed Recovery')
                        ->numeric()
                        ->prefix(\App\Support\Money::symbol(Filament::getTenant()?->currencyCode()))
                        ->helperText('Actual revenue recovered or loss prevented'),

                    TextInput::make('cost_to_resolve')
                        ->label('Cost to Resolve')
                        ->numeric()
                        ->prefix(\App\Support\Money::symbol(Filament::getTenant()?->currencyCode()))
                        ->helperText('Internal time + remediation cost estimate'),

                    Select::make('recovery_method')
                        ->label('Recovery Method')
                        ->options(InvestigationOutcome::RECOVERY_METHOD_LABELS)
                        ->placeholder('How was recovery measured?'),

                    DatePicker::make('recovery_measured_from')
                        ->label('Recovery Period From'),

                    DatePicker::make('recovery_measured_to')
                        ->label('Recovery Period To'),

                    Textarea::make('confirmed_root_cause')
                        ->label('Confirmed Root Cause')
                        ->rows(2)
                        ->helperText('Analyst conclusion — may differ from AI suggestion'),

                    Toggle::make('ai_root_cause_correct')
                        ->label('AI Root Cause Was Correct')
                        ->inline(false),

                    Toggle::make('was_false_positive')
                        ->label('Mark as False Positive')
                        ->helperText('Sends feedback to the detection engine')
                        ->inline(false),

                    Textarea::make('recovery_notes')
                        ->label('Notes')
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    app(OutcomeService::class)->record($this->record, $data);

                    $this->record = $this->record->fresh([
                        'anomalies', 'evidence', 'actions.assignedTo',
                        'actions.assignedTeam', 'auditLogs.user',
                        'assignedTeam', 'assignedUser', 'outcome',
                    ]);

                    Notification::make()->title('Outcome recorded')->success()->send();
                }),

            // ── Feature 5 — Watch / Unwatch ────────────────────────────────
            Action::make('watch')
                ->label('Watch')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->visible(fn () => ! $this->isWatchedByMe())
                ->form([
                    Select::make('mode')
                        ->label('Watch')
                        ->options([
                            InvestigationWatch::MODE_UNTIL_RESOLVED => 'Until resolved',
                            InvestigationWatch::MODE_UNTIL_DATE      => 'Until a date',
                            InvestigationWatch::MODE_INDEFINITE      => 'Indefinitely',
                        ])
                        ->default(InvestigationWatch::MODE_UNTIL_RESOLVED)
                        ->live()
                        ->required(),
                    DateTimePicker::make('watch_until')
                        ->label('Watch until')
                        ->visible(fn ($get) => $get('mode') === InvestigationWatch::MODE_UNTIL_DATE)
                        ->minDate(now()),
                    CheckboxList::make('triggers')
                        ->label('Notify me on')
                        ->options(InvestigationWatch::TRIGGER_LABELS)
                        ->default(InvestigationWatch::DEFAULT_TRIGGERS)
                        ->columns(2),
                    Select::make('team_id')
                        ->label('Watch as team (optional)')
                        ->options(fn () => Team::where('tenant_id', $this->record->tenant_id)->pluck('name', 'id'))
                        ->placeholder('Just me')
                        ->helperText('Team watches notify all members.'),
                ])
                ->action(function (array $data) {
                    $service = app(WatchService::class);
                    $until   = ! empty($data['watch_until']) ? \Illuminate\Support\Carbon::parse($data['watch_until']) : null;
                    if (! empty($data['team_id'])) {
                        $service->watchForTeam($this->record, (int) $data['team_id'], $data['mode'], $until, $data['triggers'] ?? null, auth()->id());
                    } else {
                        $service->watchForUser($this->record, auth()->id(), $data['mode'], $until, $data['triggers'] ?? null);
                    }
                    $this->reloadRecord();
                    Notification::make()->title('Watching investigation')->success()->send();
                }),

            Action::make('unwatch')
                ->label('Unwatch')
                ->icon('heroicon-o-eye-slash')
                ->color('gray')
                ->visible(fn () => $this->isWatchedByMe())
                ->action(function () {
                    app(WatchService::class)->unwatchForUser($this->record, auth()->id());
                    $this->reloadRecord();
                    Notification::make()->title('Stopped watching')->success()->send();
                }),

            // ── Feature 6 — Snooze / Unsnooze ──────────────────────────────
            Action::make('snooze')
                ->label('Snooze')
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->visible(fn () => ! $this->record->isSnoozed())
                ->form([
                    Select::make('duration')
                        ->label('Snooze for')
                        ->options(SnoozeService::DURATIONS)
                        ->default('7d')
                        ->live()
                        ->required(),
                    DatePicker::make('custom_date')
                        ->label('Until')
                        ->visible(fn ($get) => $get('duration') === 'custom')
                        ->minDate(now()),
                    Select::make('reason')
                        ->label('Reason')
                        ->options(SnoozeService::REASONS)
                        ->required(),
                    Textarea::make('notes')->label('Notes')->rows(2),
                ])
                ->action(function (array $data) {
                    $service = app(SnoozeService::class);
                    $until = $service->resolveUntil($data['duration'], $data['custom_date'] ?? null);
                    $service->snooze($this->record, $until, $data['reason'], $data['notes'] ?? null, auth()->id());
                    $this->reloadRecord();
                    Notification::make()->title('Investigation snoozed')->success()->send();
                }),

            Action::make('unsnooze')
                ->label('Unsnooze')
                ->icon('heroicon-o-bell-alert')
                ->color('gray')
                ->visible(fn () => $this->record->isSnoozed())
                ->action(function () {
                    app(SnoozeService::class)->unsnooze($this->record, auth()->id());
                    $this->reloadRecord();
                    Notification::make()->title('Snooze cleared')->success()->send();
                }),

            // ── Feature 10 — Add comment ───────────────────────────────────
            Action::make('add_comment')
                ->label('Comment')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->form([
                    Textarea::make('body')
                        ->label('Comment')
                        ->required()
                        ->rows(3)
                        ->placeholder('Add context, a decision, or a question…'),
                    Select::make('mentioned_user_ids')
                        ->label('Mention users')
                        ->multiple()
                        ->options(fn () => User::where('tenant_id', $this->record->tenant_id)->orderBy('name')->pluck('name', 'id'))
                        ->searchable(),
                    Select::make('mentioned_team_ids')
                        ->label('Mention teams')
                        ->multiple()
                        ->options(fn () => Team::where('tenant_id', $this->record->tenant_id)->orderBy('name')->pluck('name', 'id'))
                        ->searchable(),
                ])
                ->action(function (array $data) {
                    app(CommentService::class)->post(
                        $this->record,
                        auth()->id(),
                        $data['body'],
                        array_map('intval', $data['mentioned_user_ids'] ?? []),
                        array_map('intval', $data['mentioned_team_ids'] ?? []),
                    );
                    $this->reloadRecord();
                    Notification::make()->title('Comment added')->success()->send();
                }),

            // Back to list
            Action::make('back')
                ->label('All Investigations')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(InvestigationResource::getUrl('index')),
        ];
    }
}
