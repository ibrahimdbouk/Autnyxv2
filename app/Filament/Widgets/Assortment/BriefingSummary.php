<?php

namespace App\Filament\Widgets\Assortment;

use App\Models\AssortmentBriefing;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/** v2.1 — the briefing's headline, summary and what to do this week. */
class BriefingSummary extends Widget
{
    protected static bool $isLazy = false;

    public ?int $briefing = null;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.assortment.widgets.briefing-summary';

    protected function getViewData(): array
    {
        return ['b' => $this->briefing ? AssortmentBriefing::where('tenant_id', Filament::getTenant()?->id)->find($this->briefing) : null];
    }
}
