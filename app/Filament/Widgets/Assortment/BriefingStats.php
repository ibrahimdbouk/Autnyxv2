<?php

namespace App\Filament\Widgets\Assortment;

use App\Filament\Pages\AssortmentLearning;
use App\Filament\Pages\AssortmentOutcomes;
use App\Filament\Pages\AssortmentPlans;
use App\Filament\Resources\AssortmentDecisionResource;
use App\Models\AssortmentBriefing;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** v2.1 — the briefing's figures, each a way into the screen behind it. */
class BriefingStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    public ?int $briefing = null;

    protected ?string $heading = 'The week in figures';

    protected function getStats(): array
    {
        $b = $this->briefing ? AssortmentBriefing::where('tenant_id', Filament::getTenant()?->id)->find($this->briefing) : null;
        if ($b === null) {
            return [];
        }
        $f = $b->content['facts'] ?? [];
        $url = fn (callable $u) => rescue($u, null, false);

        return [
            Stat::make('New decisions', number_format((int) ($f['new_decisions'] ?? 0)))
                ->description(($f['new_value'] ?? null) ? $f['new_value'] . ' a year' : ((int) ($f['open_decisions'] ?? 0)) . ' open in all')
                ->color('info')->url($url(fn () => AssortmentDecisionResource::getUrl('index'))),
            Stat::make('Plans to decide', number_format((int) ($f['plans_waiting'] ?? 0)))
                ->description('Each store and category, its changes chosen together')
                ->url($url(fn () => AssortmentPlans::canAccess() ? AssortmentPlans::getUrl() : null)),
            Stat::make('Changes to make in store', number_format((int) ($f['tasks_to_do'] ?? 0) + (int) ($f['resets_to_do'] ?? 0)))
                ->description(((int) ($f['tasks_overdue'] ?? 0) + (int) ($f['resets_overdue'] ?? 0)) . ' overdue')
                ->color(((int) ($f['tasks_overdue'] ?? 0) + (int) ($f['resets_overdue'] ?? 0)) > 0 ? 'danger' : null)
                ->url($url(fn () => AssortmentOutcomes::canAccess() ? AssortmentOutcomes::getUrl() : null)),
            Stat::make('Results measured', number_format((int) ($f['measured'] ?? 0)))
                ->description(($f['measured'] ?? 0) ? ($f['worked'] . ' worked · ' . $f['partly'] . ' partly · ' . $f['did_not'] . ' did not · ' . $f['measured_value'] . ' a year') : 'Against similar stores, 8 weeks after a change')
                ->color('success')
                ->url($url(fn () => ((int) ($f['calibrations'] ?? 0)) > 0 && AssortmentLearning::canAccess() ? AssortmentLearning::getUrl() : (AssortmentOutcomes::canAccess() ? AssortmentOutcomes::getUrl() : null))),
        ];
    }
}
