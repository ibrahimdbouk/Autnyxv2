<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Filament\Resources\AssortmentDecisionResource;
use App\Models\AssortmentGap;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Range outcomes — what the accepted decisions did: tasks to do and done,
 * and each result measured against similar stores that did not make the
 * change. Only measured results count toward the total.
 */
class AssortmentOutcomes extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_outcomes';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Outcomes';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'assortment-outcomes';

    protected string $view = 'filament.pages.assortment-outcomes';

    public function getTitle(): string
    {
        return 'Range outcomes';
    }

    protected function getViewData(): array
    {
        $accepted = AssortmentDecisionResource::getEloquentQuery()
            ->where('status', AssortmentGap::STATUS_ACCEPTED)
            ->orderByRaw('measured_at IS NULL, measured_at DESC')->orderByDesc('done_at')->orderByDesc('decided_at')
            ->limit(200)->get();
        $measured = $accepted->whereNotNull('measured_at');

        return [
            'rows'     => $accepted,
            'toDo'     => $accepted->where('task_status', AssortmentGap::TASK_TO_DO)->count(),
            'overdue'  => $accepted->where('task_status', AssortmentGap::TASK_TO_DO)->filter(fn ($g) => $g->due_at && $g->due_at->isPast())->count(),
            'done'     => $accepted->where('task_status', AssortmentGap::TASK_DONE)->count(),
            'measured' => $measured->count(),
            'uplift'   => (float) $measured->sum(fn ($g) => (float) ($g->measurement['uplift_per_year'] ?? 0)),
            'expected' => (float) $measured->sum('value_mid_at_decision'),
            'currency' => Filament::getTenant()?->currencyCode(),
        ];
    }
}
