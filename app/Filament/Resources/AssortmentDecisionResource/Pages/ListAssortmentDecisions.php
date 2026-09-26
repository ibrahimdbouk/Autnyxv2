<?php

namespace App\Filament\Resources\AssortmentDecisionResource\Pages;

use App\Filament\Resources\AssortmentDecisionResource;
use App\Models\AssortmentGap;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAssortmentDecisions extends ListRecords
{
    protected static string $resource = AssortmentDecisionResource::class;

    public function getTitle(): string
    {
        return 'Range decisions';
    }

    public function getSubheading(): ?string
    {
        return 'What to add, what to drop, and what keeps running out — compared with similar stores. Accepting one makes it a task; the result is measured 8 weeks after it is done.';
    }

    public function getTabs(): array
    {
        $count = fn (callable $scope) => $scope(AssortmentDecisionResource::getEloquentQuery())->count();
        $open    = fn (Builder $query) => $query->where('status', AssortmentGap::STATUS_OPEN);
        $tasks   = fn (Builder $query) => $query->where('status', AssortmentGap::STATUS_ACCEPTED)->where('task_status', AssortmentGap::TASK_TO_DO);
        $done    = fn (Builder $query) => $query->where('status', AssortmentGap::STATUS_ACCEPTED)->whereIn('task_status', [AssortmentGap::TASK_DONE, AssortmentGap::TASK_CANCELLED]);
        $reject  = fn (Builder $query) => $query->where('status', AssortmentGap::STATUS_REJECTED);

        return [
            'queue'    => Tab::make('To decide')->modifyQueryUsing($open)->badge($count($open) ?: null),
            'tasks'    => Tab::make('Tasks')->modifyQueryUsing($tasks)->badge($count($tasks) ?: null),
            'done'     => Tab::make('Done')->modifyQueryUsing($done),
            'rejected' => Tab::make('Rejected')->modifyQueryUsing($reject),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'queue';
    }
}
