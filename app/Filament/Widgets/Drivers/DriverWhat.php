<?php

namespace App\Filament\Widgets\Drivers;

use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** What moved: traffic, basket, mix and price, adding up to the change. */
class DriverWhat extends Widget
{
    use InteractsWithPageFilters;
    use UsesDriverAnalysis;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.drivers.waterfall';

    protected function getViewData(): array
    {
        $r = $this->result();

        return [
            'title' => 'What moved',
            'intro' => 'The change split into shoppers, what each bought, the mix of products and prices. The bars add up to the change.',
            'steps' => $r ? $this->steps($r['what']) : [],
            'total' => $r ? ['label' => 'Change', 'value' => (float) $r['totals']['change'], 'text' => $this->signed((float) $r['totals']['change'])] : null,
            'fmt'   => fn ($v) => $this->money((float) $v),
            'extra' => [],
        ];
    }
}
