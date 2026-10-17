<?php

namespace App\Filament\Widgets\Drivers;

use App\Filament\Pages\PerformanceDrivers;
use App\Support\Money;
use Filament\Facades\Filament;

/** Root Cause drivers — what every drivers widget shares: the page's breakdown and money formatting. */
trait UsesDriverAnalysis
{
    /** @var array<string,mixed>|null|false memo (false = not loaded) */
    private array|null|false $result = false;

    protected function result(): ?array
    {
        if ($this->result === false) {
            $this->result = PerformanceDrivers::analysis($this->pageFilters ?? null)?->result;
        }

        return $this->result;
    }

    protected function currency(): string
    {
        return Money::normalize(Filament::getTenant()?->currency);
    }

    protected function money(float $v): string
    {
        return Money::displayCompact($v, $this->currency());
    }

    protected function signed(float $v): string
    {
        return ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . Money::displayCompact(abs($v), $this->currency());
    }

    /** @return array<int,array{label:string,value:float,text:string}> waterfall steps */
    protected function steps(array $items): array
    {
        return array_values(array_map(fn ($i) => ['label' => $i['label'], 'value' => (float) $i['value'], 'text' => $this->signed((float) $i['value'])],
            array_filter($items, fn ($i) => abs((float) $i['value']) >= 0.005 || $i['key'] === 'unexplained')));
    }
}
