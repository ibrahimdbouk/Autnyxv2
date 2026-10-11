<?php

namespace App\Filament\Widgets\Clustering;

use App\Services\Clustering\ClusterVisuals;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/** v2.5 — every store by basket value and daily sales, coloured by cluster. */
class ClusterScatter extends Widget
{
    protected static bool $isLazy = false;

    /** On a cluster's own page: that cluster in colour, the others faint. */
    public ?int $focus = null;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    protected string $view = 'filament.clustering.scatter';

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $currency = Money::normalize($tenant?->currency);

        return [
            'viz'      => $tenant ? app(ClusterVisuals::class)->overview((int) $tenant->id, $currency, $this->focus) : null,
            'currency' => $currency,
            'focus'    => $this->focus,
        ];
    }
}
