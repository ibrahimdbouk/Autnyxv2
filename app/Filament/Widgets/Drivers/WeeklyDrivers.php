<?php

namespace App\Filament\Widgets\Drivers;

use App\Filament\Pages\PerformanceDrivers;
use App\Services\Drivers\DriverReports;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * On the Weekly Briefing: what moved sales last week and why — computed, not
 * written by AI (the briefing's text is given these figures as fixed facts).
 */
class WeeklyDrivers extends Widget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.drivers.weekly';

    protected function getViewData(): array
    {
        $tenantId = (int) Filament::getTenant()?->id;
        $row = $tenantId ? app(DriverReports::class)->latestWeekly($tenantId) : null;
        $r = $row?->result;
        $currency = Money::normalize(Filament::getTenant()?->currency);
        $signed = fn (float $v) => ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . Money::displayCompact(abs($v), $currency);
        $steps = [];
        foreach ((array) ($r['why'] ?? []) as $i) {
            if (abs((float) $i['value']) >= 0.005 || $i['key'] === 'unexplained') {
                $steps[] = ['label' => $i['label'], 'value' => (float) $i['value'], 'text' => $signed((float) $i['value'])];
            }
        }
        $price = collect($r['what'] ?? [])->firstWhere('key', 'price');
        if ($price && abs((float) $price['value']) >= 0.005) {
            $steps[] = ['label' => 'Price', 'value' => (float) $price['value'], 'text' => $signed((float) $price['value'])];
        }
        $movers = [];
        foreach (['stores' => 'Store', 'skus' => 'Product'] as $k => $kind) {
            foreach ((array) ($r['contributors'][$k] ?? []) as $c) {
                $movers[] = ['kind' => $kind, 'name' => $c['name'], 'change' => (float) $c['change'], 'text' => $signed((float) $c['change']), 'main' => $c['main_label']];
            }
        }
        usort($movers, fn ($a, $b) => abs($b['change']) <=> abs($a['change']));

        return [
            'r'      => $r,
            'steps'  => $steps,
            'total'  => $r ? ['label' => 'Change', 'value' => (float) $r['totals']['change'], 'text' => $signed((float) $r['totals']['change'])] : null,
            'fmt'    => fn ($x) => Money::displayCompact((float) $x, $currency),
            'movers' => array_slice($movers, 0, 6),
            'url'    => $r ? PerformanceDrivers::getUrl() : null,
        ];
    }
}
