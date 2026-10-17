<?php

namespace App\Filament\Widgets\Drivers;

use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** Why it moved (price aside): shoppers, stock, promotions, events, range — and what is left unexplained. */
class DriverWhy extends Widget
{
    use InteractsWithPageFilters;
    use UsesDriverAnalysis;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.drivers.waterfall';

    protected function getViewData(): array
    {
        $r = $this->result();
        $extra = [];
        foreach ((array) ($r['detail']['events'] ?? []) as $e) {
            $extra[] = $e['measured']
                ? "{$e['name']}: " . $this->signed((float) $e['value']) . ' (' . ($e['lift'] >= 0 ? '+' : '') . round(100 * (float) $e['lift']) . '% the last time it ran)'
                : "{$e['name']}: in one period more than the other, not measured (no sales history from the last time).";
        }
        if ($r && ($n = (int) ($r['detail']['availability']['positions'] ?? 0)) > 0) {
            $extra[] = "Out of stock: {$n} store × product position(s) with stock-out days in one period more than the other.";
        }
        if ($r && ($n = (int) ($r['detail']['promotions']['positions'] ?? 0)) > 0) {
            $extra[] = "Promotions: {$n} position(s) on promotion in one period more than the other.";
        }
        $range = (array) ($r['detail']['range'] ?? []);
        if (($range['new'] ?? 0) || ($range['gone'] ?? 0)) {
            $extra[] = "Range: {$range['new']} position(s) sold only this period, {$range['gone']} only in the comparison.";
        }

        return [
            'title' => 'Why it moved',
            'intro' => 'The change other than price, by cause, each measured from the data. Unexplained is what is left — the part worth an investigation.',
            'steps' => $r ? $this->steps($r['why']) : [],
            'total' => $r ? ['label' => 'Without price', 'value' => (float) array_sum(array_column($r['why'], 'value')), 'text' => $this->signed((float) array_sum(array_column($r['why'], 'value')))] : null,
            'fmt'   => fn ($v) => $this->money((float) $v),
            'extra' => $extra,
        ];
    }
}
