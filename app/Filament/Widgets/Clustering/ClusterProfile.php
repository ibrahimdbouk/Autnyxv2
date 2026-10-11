<?php

namespace App\Filament\Widgets\Clustering;

use App\Services\Clustering\ClusterVisuals;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/** v2.5 — each cluster against the average store on the behavioural features. */
class ClusterProfile extends Widget
{
    protected static bool $isLazy = false;

    /** On a cluster's own page: that cluster in colour, the others faint. */
    public ?int $focus = null;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 2];

    protected string $view = 'filament.clustering.profile';

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
