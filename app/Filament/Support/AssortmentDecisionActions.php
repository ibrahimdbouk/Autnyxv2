<?php

namespace App\Filament\Support;

use App\Models\AssortmentGap;
use App\Models\User;
use App\Services\Assortment\DecisionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The accept / reject / done / cancel actions for a range decision, shared by
 * the Decisions table and the decision page. Each goes through DecisionService.
 */
final class AssortmentDecisionActions
{
    /** @return array<int|string,string> active people of the tenant */
    public static function people(): array
    {
        return User::active()->where('tenant_id', Filament::getTenant()?->id)
            ->where('is_super_admin', false)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function accept(): Action
    {
        return Action::make('accept')
            ->label('Accept')->icon('heroicon-o-check')->color('success')
            ->visible(fn (?AssortmentGap $record) => $record?->status === AssortmentGap::STATUS_OPEN)
            ->modalHeading('Accept this decision')
            ->modalDescription('It becomes a task. Make the change in your own merchandising system; mark the task done here when it is, and the result is measured 8 weeks later against similar stores.')
            ->form([
                Select::make('assignee_id')->label('Who does it')->options(fn () => self::people())->searchable()
                    ->placeholder('The store manager, if one is set'),
                DatePicker::make('due_at')->label('Due')->default(now()->addDays(14))->minDate(today()),
                Textarea::make('note')->rows(2)->maxLength(500),
            ])
            ->action(function (AssortmentGap $record, array $data) {
                app(DecisionService::class)->accept($record, auth()->user(), $data['assignee_id'] ?? null, $data['due_at'] ?? null, $data['note'] ?? null);
                Notification::make()->title('Accepted — it is now a task')->success()->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')->icon('heroicon-o-x-mark')->color('gray')
            ->visible(fn (?AssortmentGap $record) => $record?->status === AssortmentGap::STATUS_OPEN)
            ->modalHeading('Reject this decision')
            ->form([
                Select::make('reason')->label('Why')->required()->options([
                    'Strategic or contract reason'       => 'Strategic or contract reason',
                    'Local shoppers differ'              => 'Local shoppers differ',
                    'No space on the shelf'              => 'No space on the shelf',
                    'Supplier or pack not available'     => 'Supplier or pack not available',
                    'The data behind it is wrong'        => 'The data behind it is wrong',
                    'Other'                              => 'Other',
                ]),
                Textarea::make('note')->rows(2)->maxLength(400),
            ])
            ->action(function (AssortmentGap $record, array $data) {
                app(DecisionService::class)->reject($record, auth()->user(), trim($data['reason'] . (filled($data['note'] ?? null) ? ': ' . $data['note'] : '')));
                Notification::make()->title('Rejected — it will not be proposed again')->success()->send();
            });
    }

    public static function done(): Action
    {
        return Action::make('done')
            ->label('Mark done')->icon('heroicon-o-check-badge')->color('primary')
            ->visible(fn (?AssortmentGap $record) => $record?->status === AssortmentGap::STATUS_ACCEPTED && $record->task_status === AssortmentGap::TASK_TO_DO)
            ->modalHeading('The change is made')
            ->modalDescription('The result is measured 8 weeks after this date, against similar stores that did not make the change.')
            ->form([
                DatePicker::make('done_on')->label('Done on')->default(today())->maxDate(today())->required(),
                Textarea::make('note')->rows(2)->maxLength(300),
            ])
            ->action(function (AssortmentGap $record, array $data) {
                app(DecisionService::class)->markDone($record, auth()->user(), $data['note'] ?? null, $data['done_on'] ?? null);
                Notification::make()->title('Done — the result will be measured in 8 weeks')->success()->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Cancel task')->icon('heroicon-o-no-symbol')->color('gray')
            ->visible(fn (?AssortmentGap $record) => $record?->status === AssortmentGap::STATUS_ACCEPTED && $record->task_status === AssortmentGap::TASK_TO_DO)
            ->form([Textarea::make('reason')->label('Why it will not be done')->required()->rows(2)->maxLength(300)])
            ->action(function (AssortmentGap $record, array $data) {
                app(DecisionService::class)->cancel($record, auth()->user(), $data['reason']);
                Notification::make()->title('Task cancelled')->success()->send();
            });
    }

    public static function bulkAccept(): BulkAction
    {
        return BulkAction::make('acceptSelected')
            ->label('Accept selected')->icon('heroicon-o-check')->color('success')
            ->requiresConfirmation()
            ->modalDescription('Each open decision becomes a task for its store manager, due in 14 days.')
            ->action(function (Collection $records) {
                $n = 0;
                foreach ($records as $r) {
                    if ($r->status === AssortmentGap::STATUS_OPEN) {
                        app(DecisionService::class)->accept($r, auth()->user());
                        $n++;
                    }
                }
                Notification::make()->title("{$n} decision(s) accepted")->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function bulkReject(): BulkAction
    {
        return BulkAction::make('rejectSelected')
            ->label('Reject selected')->icon('heroicon-o-x-mark')->color('gray')
            ->form([Textarea::make('reason')->label('Why')->required()->rows(2)->maxLength(400)])
            ->action(function (Collection $records, array $data) {
                $n = 0;
                foreach ($records as $r) {
                    if ($r->status === AssortmentGap::STATUS_OPEN) {
                        app(DecisionService::class)->reject($r, auth()->user(), $data['reason']);
                        $n++;
                    }
                }
                Notification::make()->title("{$n} decision(s) rejected")->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
