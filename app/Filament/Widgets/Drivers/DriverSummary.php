<?php

namespace App\Filament\Widgets\Drivers;

use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** The headline, the two periods and the totals. */
class DriverSummary extends Widget
{
    use InteractsWithPageFilters;
    use UsesDriverAnalysis;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.drivers.summary';

    protected function getViewData(): array
    {
        $r = $this->result();

        return ['r' => $r, 'w' => $this];
    }

    public function fmt(?float $v): string
    {
        return $v === null ? '—' : $this->money($v);
    }

    public function chg(float $v): string
    {
        return $this->signed($v);
    }
}
