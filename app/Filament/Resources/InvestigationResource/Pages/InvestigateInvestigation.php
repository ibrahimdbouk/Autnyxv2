<?php

namespace App\Filament\Resources\InvestigationResource\Pages;

use App\Filament\Resources\InvestigationResource;
use App\Models\Action as InvestigationAction;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Services\AuditLogger;
use App\Services\InvestigationNarratorService;
use App\Services\OutcomeService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class InvestigateInvestigation extends Page
{
    protected static string $resource = InvestigationResource::class;

    protected string $view = 'filament.resources.investigation-resource.pages.investigate-investigation';

    public function getMaxContentWidth(): \Filament\Support\Enums\MaxWidth | string | null
    {
        return \Filament\Support\Enums\MaxWidth::Full;
    }

    public Investigation $record;

    public function mount(int|string $record): void
    {
        // Filament v5 / Livewire 3 may pass the serialised model JSON as the
        // route parameter in some binding contexts — extract the raw ID.
        if (is_string($record) && str_starts_with(trim($record), '{')) {
            $decoded = json_decode($record, true);
            $record  = $decoded['id'] ?? $record;
        }

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
        ])->findOrFail($record);

        abort_unless(
            $this->record->tenant_id === Filament::getTenant()?->id,
            403
        );
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
                        ->label('Revenue at Risk ($)')
                        ->numeric()
                        ->prefix('$')
                        ->helperText('AI estimate — adjust if needed'),

                    TextInput::make('observed_recovery')
                        ->label('Observed Recovery ($)')
                        ->numeric()
                        ->prefix('$')
                        ->helperText('Actual revenue recovered or loss prevented'),

                    TextInput::make('cost_to_resolve')
                        ->label('Cost to Resolve ($)')
                        ->numeric()
                        ->prefix('$')
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

            // Back to list
            Action::make('back')
                ->label('All Investigations')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(InvestigationResource::getUrl('index')),
        ];
    }
}
